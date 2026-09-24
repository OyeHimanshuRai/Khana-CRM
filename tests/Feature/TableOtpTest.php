<?php

namespace Tests\Feature;

use App\Contracts\SmsGateway;
use App\Models\Floor;
use App\Models\Order;
use App\Models\Product;
use App\Models\RestaurantTable;
use App\Models\Shop;
use App\Models\TableSession;
use App\Models\VerificationCode;
use App\Services\OtpService;
use App\Services\Sms\SmsManager;
use App\Services\TableQrService;
use App\Support\CurrentShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Tests\TestCase;

/**
 * The optional OTP step before an order reaches the kitchen (§3.8).
 *
 * The thing worth testing hardest is not that a correct code works - it is
 * everything around that:
 *
 *   - a branch that has not asked for it is not slowed down at all;
 *   - a branch that has asked for it, with nothing able to send a message,
 *     still takes orders (a closed restaurant is worse than a prank order);
 *   - the cart survives the detour, because a guest who has to re-choose a
 *     meal will not;
 *   - a sitting is asked once, not once per round.
 *
 * Nothing here sends anything. The gateway is a fake and stray HTTP is
 * prevented outright.
 */
class TableOtpTest extends TestCase
{
    use RefreshDatabase;

    private FakeSms $sms;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');

        $this->seed();

        CurrentShop::forget();

        // Nothing in this file may reach the internet.
        Http::preventStrayRequests();

        $this->sms = new FakeSms();
        app(SmsManager::class)->swap($this->sms);
    }

    protected function tearDown(): void
    {
        CurrentShop::forget();

        parent::tearDown();
    }

    /* ---------------------------------------------------------- fixtures */

    private function shop(): Shop
    {
        return Shop::query()->orderBy('id')->firstOrFail();
    }

    private function requireOtp(): Shop
    {
        $shop = $this->shop();
        $shop->forceFill(['requires_otp' => true])->save();

        return $shop->fresh();
    }

    private function table(): RestaurantTable
    {
        $floor = Floor::create([
            'shop_id' => $this->shop()->id,
            'name' => 'Ground Floor',
            'code' => 'GF',
            'is_active' => true,
        ]);

        $table = RestaurantTable::create([
            'shop_id' => $floor->shop_id,
            'floor_id' => $floor->id,
            'name' => '4',
            'code' => 'GF-04',
            'capacity' => 4,
            'status' => RestaurantTable::AVAILABLE,
            'is_active' => true,
        ]);

        app(TableQrService::class)->issue($table);

        return $table->fresh(['activeQr']);
    }

    private function dish(): Product
    {
        return Product::create([
            'name' => 'Dal Makhani',
            'slug' => Product::uniqueSlug('Dal Makhani'),
            'sku' => Product::generateSku('Dal Makhani'),
            'selling_price' => 280,
            'is_active' => true,
        ]);
    }

    /** Scan the sticker and put one dish in the cart. */
    private function seatWithCart(RestaurantTable $table): TableSession
    {
        $this->get('/t/'.$table->activeQr->token)->assertRedirect(route('table.show'));
        $this->post('/t/cart', ['product_id' => $this->dish()->id])->assertRedirect();

        return TableSession::allShops()->live()->firstOrFail();
    }

    /** The code the fake gateway just "sent". */
    private function lastCode(): string
    {
        preg_match('/\b(\d{6})\b/', $this->sms->last['message'] ?? '', $m);

        return $m[1] ?? '';
    }

    /* ------------------------------------------------- when it is not asked */

    public function test_a_branch_that_has_not_asked_for_otp_is_not_slowed_down(): void
    {
        $table = $this->table();
        $this->seatWithCart($table);

        $this->post('/t/order')->assertRedirect(route('table.orders'));

        $this->assertSame(1, Order::allShops()->count());
        $this->assertSame([], $this->sms->sent);
    }

    public function test_a_branch_asking_for_otp_with_no_way_to_send_still_takes_orders(): void
    {
        $this->requireOtp();
        app(SmsManager::class)->swap(new DeadSms());

        $table = $this->table();
        $this->seatWithCart($table);

        // A closed restaurant is a worse outcome than a prank order. The
        // misconfiguration is reported; the guest is fed.
        $this->post('/t/order')->assertRedirect(route('table.orders'));
        $this->assertSame(1, Order::allShops()->count());
    }

    /* ----------------------------------------------------- the happy path */

    public function test_the_order_is_held_until_the_number_is_confirmed(): void
    {
        $this->requireOtp();
        $table = $this->table();
        $session = $this->seatWithCart($table);

        $this->post('/t/order')->assertRedirect(route('table.verify'));

        // Held BEFORE the ticket exists, not withdrawn after - a ticket that
        // reached the pass and was then pulled is worse than one never sent.
        $this->assertSame(0, Order::allShops()->count());

        // And the cart is exactly where the guest left it.
        $this->get('/t/cart')->assertOk()->assertSee('Dal Makhani');

        $this->post('/t/verify/send', ['mobile' => '98765 43210'])
            ->assertRedirect(route('table.verify'));

        $this->assertSame('9876543210', $this->sms->last['to']);

        $this->post('/t/verify', [
            'mobile' => '9876543210',
            'code' => $this->lastCode(),
        ])->assertRedirect(route('table.cart'));

        $session->refresh();
        $this->assertNotNull($session->mobile_verified_at);
        $this->assertSame('9876543210', $session->guest_mobile);

        $this->post('/t/order')->assertRedirect(route('table.orders'));
        $this->assertSame(1, Order::allShops()->count());
    }

    public function test_a_sitting_is_asked_once_and_not_once_per_round(): void
    {
        $this->requireOtp();
        $table = $this->table();
        $session = $this->seatWithCart($table);

        $session->forceFill(['mobile_verified_at' => now()])->save();

        $this->post('/t/order')->assertRedirect(route('table.orders'));

        // Second round, same sitting: straight through.
        $this->post('/t/cart', ['product_id' => $this->dish()->id])->assertRedirect();
        $this->post('/t/order')->assertRedirect(route('table.orders'));

        $this->assertSame(2, Order::allShops()->count());
        $this->assertSame([], $this->sms->sent);
    }

    public function test_confirming_does_not_place_the_order_on_its_own(): void
    {
        $this->requireOtp();
        $table = $this->table();
        $this->seatWithCart($table);

        $this->post('/t/verify/send', ['mobile' => '9876543210']);
        $this->post('/t/verify', ['mobile' => '9876543210', 'code' => $this->lastCode()]);

        // An order that went in on a screen the guest never confirmed would
        // be the worst bug on this journey.
        $this->assertSame(0, Order::allShops()->count());
    }

    /* ---------------------------------------------------------- the code */

    public function test_the_code_is_never_stored_in_the_clear(): void
    {
        $this->requireOtp();
        $this->seatWithCart($this->table());

        $this->post('/t/verify/send', ['mobile' => '9876543210']);

        $row = VerificationCode::query()->latest('id')->firstOrFail();
        $code = $this->lastCode();

        $this->assertNotSame($code, $row->code_hash);
        $this->assertStringNotContainsString($code, $row->code_hash);
        $this->assertArrayNotHasKey('code_hash', $row->toArray());
    }

    public function test_a_wrong_code_costs_an_attempt_and_the_fifth_burns_it(): void
    {
        $this->requireOtp();
        $this->seatWithCart($this->table());
        $this->post('/t/verify/send', ['mobile' => '9876543210']);

        $otp = app(OtpService::class);

        for ($i = 0; $i < 5; $i++) {
            try {
                $otp->verify('9876543210', '000000', VerificationCode::TABLE_SESSION);
                $this->fail('a wrong code was accepted');
            } catch (RuntimeException $e) {
                // expected
            }
        }

        $this->assertSame(5, VerificationCode::query()->latest('id')->firstOrFail()->attempts);

        // Even the right code is refused once the row is burnt.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Too many wrong tries');
        $otp->verify('9876543210', $this->lastCode(), VerificationCode::TABLE_SESSION);
    }

    public function test_an_expired_code_is_refused(): void
    {
        $this->requireOtp();
        $this->seatWithCart($this->table());
        $this->post('/t/verify/send', ['mobile' => '9876543210']);

        VerificationCode::query()->latest('id')->firstOrFail()
            ->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('expired');

        app(OtpService::class)->verify('9876543210', $this->lastCode(), VerificationCode::TABLE_SESSION);
    }

    public function test_a_code_cannot_be_spent_twice(): void
    {
        $this->requireOtp();
        $this->seatWithCart($this->table());
        $this->post('/t/verify/send', ['mobile' => '9876543210']);

        $code = $this->lastCode();
        $otp = app(OtpService::class);

        $otp->verify('9876543210', $code, VerificationCode::TABLE_SESSION);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No code is waiting');
        $otp->verify('9876543210', $code, VerificationCode::TABLE_SESSION);
    }

    public function test_the_resend_throttle_survives_a_cleared_cache(): void
    {
        $this->requireOtp();
        $this->seatWithCart($this->table());

        $this->post('/t/verify/send', ['mobile' => '9876543210']);

        // Everything a cache-based throttle would have remembered is gone.
        cache()->flush();

        $this->post('/t/verify/send', ['mobile' => '9876543210']);

        // Still one message: the throttle is read off the row, not a cache
        // that a deploy clears.
        $this->assertCount(1, $this->sms->sent);
    }

    public function test_one_phone_number_has_one_spelling(): void
    {
        $otp = app(OtpService::class);

        // "+91 98765 43210", "09876543210" and "9876543210" are one phone,
        // and a throttle that thinks they are three is no throttle.
        $this->assertSame('9876543210', $otp->normalise('+91 98765 43210'));
        $this->assertSame('9876543210', $otp->normalise('09876543210'));
        $this->assertSame('9876543210', $otp->normalise('9876543210'));
        $this->assertSame('9876543210', $otp->normalise('(98765) 43210'));
    }

    public function test_something_that_is_not_a_mobile_number_is_refused_kindly(): void
    {
        $this->requireOtp();
        $this->seatWithCart($this->table());

        $this->post('/t/verify/send', ['mobile' => '123'])
            ->assertSessionHas('table_error');

        $this->assertSame([], $this->sms->sent);
    }

    public function test_a_provider_that_refuses_does_not_leave_a_five_hundred_on_a_phone(): void
    {
        $this->requireOtp();
        $this->seatWithCart($this->table());

        app(SmsManager::class)->swap(new DeadSms());

        $this->post('/t/verify/send', ['mobile' => '9876543210'])
            ->assertRedirect()
            ->assertSessionHas('table_error');

        // The attempt is recorded with sent_at null, which is a different
        // fact from never having tried.
        $this->assertNull(VerificationCode::query()->latest('id')->firstOrFail()->sent_at);
    }

    /* -------------------------------------------------------- the screen */

    public function test_the_screen_asks_for_a_number_then_for_the_code(): void
    {
        $this->requireOtp();
        $this->seatWithCart($this->table());

        $this->get('/t/verify')->assertOk()->assertSee('Your mobile number');

        $this->post('/t/verify/send', ['mobile' => '9876543210']);

        $this->get('/t/verify')
            ->assertOk()
            ->assertSee('Enter the code')
            // Where it went, without printing the whole number on screen.
            ->assertSee('3210');
    }

    public function test_a_verified_sitting_is_sent_back_to_its_cart(): void
    {
        $this->requireOtp();
        $table = $this->table();
        $session = $this->seatWithCart($table);

        $session->forceFill(['mobile_verified_at' => now()])->save();

        $this->get('/t/verify')->assertRedirect(route('table.cart'));
    }

    public function test_the_verify_screen_needs_a_live_sitting(): void
    {
        // No scan, no cookie, no table.
        $this->get('/t/verify')->assertRedirect(route('table.expired'));
    }
}

/**
 * An SMS gateway that records instead of sending.
 */
class FakeSms implements SmsGateway
{
    /** @var array<int, array{to: string, message: string}> */
    public array $sent = [];

    /** @var array{to: string, message: string}|null */
    public ?array $last = null;

    public function key(): string
    {
        return 'fake';
    }

    public function label(): string
    {
        return 'Fake';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function send(string $to, string $message): bool
    {
        $this->last = ['to' => $to, 'message' => $message];
        $this->sent[] = $this->last;

        return true;
    }
}

/**
 * A gateway that is switched on but cannot send - the commonest real
 * failure, and the one the journey must survive.
 */
class DeadSms implements SmsGateway
{
    public function key(): string
    {
        return 'dead';
    }

    public function label(): string
    {
        return 'Dead';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function send(string $to, string $message): bool
    {
        return false;
    }
}
