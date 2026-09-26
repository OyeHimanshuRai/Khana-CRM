<?php

namespace Tests\Feature;

use App\Models\Floor;
use App\Models\PaymentIntent;
use App\Models\PaymentRefund;
use App\Models\RestaurantTable;
use App\Models\Shop;
use App\Models\User;
use App\Models\TableSession;
use App\Services\PaymentIntentService;
use App\Services\Payments\GatewayManager;
use App\Services\Payments\OfflineGateway;
use App\Services\Payments\RazorpayGateway;
use App\Services\TableSessionService;
use App\Support\CurrentShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Tests\TestCase;

/**
 * Sending money back (§11, §16).
 *
 * The rule the whole feature is arranged around: a refund that quietly did
 * nothing is the worst failure available here. The restaurant believes it has
 * paid somebody back, the guest is still out of pocket, and nothing anywhere
 * says so.
 *
 * So the tests below care most about the order of operations - the row is
 * written before the provider is called - and about what is left refundable
 * after each one. Nothing here reaches the internet.
 */
class PaymentRefundTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'rzp_test_key';

    private const SECRET = 'rzp_test_secret';

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');

        $this->seed();

        CurrentShop::forget();

        Http::preventStrayRequests();

        $this->actingAs($this->staff(['finance.online_payments.view', 'finance.online_payments.refund']));
        CurrentShop::forget();
    }

    protected function tearDown(): void
    {
        CurrentShop::forget();

        parent::tearDown();
    }

    /* ---------------------------------------------------------- fixtures */

    private function service(): PaymentIntentService
    {
        return app(PaymentIntentService::class);
    }

    private function shop(): Shop
    {
        return Shop::query()->orderBy('id')->firstOrFail();
    }

    /** @param array<int, string> $permissions */
    private function staff(array $permissions = []): User
    {
        $user = User::query()->firstWhere('email', 'books@example.test');

        if ($user === null) {
            $user = User::create([
                'tenant_id' => $this->shop()->tenant_id,
                'name' => 'Books',
                'email' => 'books@example.test',
                'password' => 'books-password-1',
                'is_admin' => true,
            ]);

            $user->shops()->syncWithoutDetaching([$this->shop()->id => ['is_default' => true]]);
            $user->forceFill([
                'current_shop_id' => $this->shop()->id,
                'all_shops_view' => false,
            ])->save();
        }

        $user->givePermissionTo(array_merge(['dashboard.overview.view'], $permissions));

        return $user->fresh();
    }

    private function live(): void
    {
        app(GatewayManager::class)->swap(new RazorpayGateway([
            'label' => 'Pay now',
            'key' => self::KEY,
            'secret' => self::SECRET,
            'webhook_secret' => 'whsec',
            'currency' => 'INR',
            'checkout_js' => 'https://example.test/checkout.js',
            'api' => 'https://api.example.test/v1',
        ]));
    }

    /**
     * A sitting for the intent to hang off.
     *
     * payment_intents is polymorphic and the morph is required - an intent
     * with nothing to pay for is not a thing this system has.
     */
    private function sitting(): TableSession
    {
        $floor = Floor::firstOrCreate(
            ['shop_id' => $this->shop()->id, 'code' => 'GF'],
            ['name' => 'Ground Floor', 'is_active' => true],
        );

        $table = RestaurantTable::create([
            'shop_id' => $floor->shop_id,
            'floor_id' => $floor->id,
            'name' => (string) random_int(1, 999),
            'code' => 'GF-'.uniqid(),
            'capacity' => 4,
            'status' => RestaurantTable::AVAILABLE,
            'is_active' => true,
        ]);

        return app(TableSessionService::class)->openFor($table);
    }

    /** A captured payment of 500. */
    private function paid(float $amount = 500): PaymentIntent
    {
        $session = $this->sitting();

        return PaymentIntent::create([
            'shop_id' => $this->shop()->id,
            'payable_type' => $session->getMorphClass(),
            'payable_id' => $session->id,
            'reference' => 'PI-'.uniqid(),
            'provider' => 'razorpay',
            'provider_order_id' => 'order_TEST',
            'provider_payment_id' => 'pay_TEST',
            'amount' => $amount,
            'currency' => 'INR',
            'status' => PaymentIntent::PAID,
            'paid_at' => now(),
        ]);
    }

    private function fakeRefund(string $id = 'rfnd_TEST', string $status = 'processed'): void
    {
        Http::fake([
            'api.example.test/*' => Http::response(['id' => $id, 'status' => $status], 200),
        ]);
    }

    /* ------------------------------------------------------ the arithmetic */

    public function test_a_full_refund_leaves_nothing_left_and_marks_the_payment(): void
    {
        $this->live();
        $this->fakeRefund();

        $intent = $this->paid(500);

        $refund = $this->service()->refund($intent, 500, 'dish never arrived');

        $this->assertSame(PaymentRefund::PROCESSED, $refund->status);
        $this->assertSame('rfnd_TEST', $refund->provider_refund_id);
        $this->assertMatchesRegularExpression('/^RFD-\d{6}$/', $refund->reference);

        $this->assertEqualsWithDelta(0, $this->service()->refundableAmount($intent->fresh()), 0.01);
        $this->assertSame(PaymentIntent::REFUNDED, $intent->fresh()->status);
    }

    public function test_a_part_refund_leaves_the_payment_still_paid(): void
    {
        $this->live();
        $this->fakeRefund();

        $intent = $this->paid(500);

        $this->service()->refund($intent, 120, 'one starter');

        // Calling a mostly-paid payment "refunded" would make every report of
        // takings wrong.
        $this->assertSame(PaymentIntent::PAID, $intent->fresh()->status);
        $this->assertEqualsWithDelta(380, $this->service()->refundableAmount($intent->fresh()), 0.01);
    }

    public function test_refunds_add_up_and_the_last_one_closes_it(): void
    {
        $this->live();
        $this->fakeRefund();

        $intent = $this->paid(500);

        $this->service()->refund($intent, 200, 'first');
        $this->service()->refund($intent->fresh(), 300, 'second');

        $this->assertSame(2, PaymentRefund::query()->count());
        $this->assertSame(PaymentIntent::REFUNDED, $intent->fresh()->status);
    }

    public function test_more_than_is_left_is_refused_by_the_number(): void
    {
        $this->live();
        $this->fakeRefund();

        $intent = $this->paid(500);
        $this->service()->refund($intent, 400, 'most of it');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Only 100.00');

        $this->service()->refund($intent->fresh(), 200, 'too much');
    }

    public function test_a_refund_must_say_why(): void
    {
        $this->live();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Say why');

        $this->service()->refund($this->paid(), 100, '   ');
    }

    public function test_a_payment_that_was_never_captured_cannot_be_refunded(): void
    {
        $this->live();

        $session = $this->sitting();

        $intent = PaymentIntent::create([
            'shop_id' => $this->shop()->id,
            'payable_type' => $session->getMorphClass(),
            'payable_id' => $session->id,
            'reference' => 'PI-'.uniqid(),
            'provider' => 'razorpay',
            'amount' => 500,
            'currency' => 'INR',
            'status' => PaymentIntent::PENDING,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('never captured');

        $this->service()->refund($intent, 100, 'nope');
    }

    /* --------------------------------------------------------- failures */

    public function test_a_refund_the_provider_refuses_is_kept_as_a_failure(): void
    {
        $this->live();

        Http::fake([
            'api.example.test/*' => Http::response([
                'error' => ['description' => 'Insufficient balance in your account'],
            ], 400),
        ]);

        $intent = $this->paid(500);

        try {
            $this->service()->refund($intent, 500, 'dish never arrived');
            $this->fail('a refused refund was reported as successful');
        } catch (RuntimeException $e) {
            // The provider's own wording is passed through, because
            // "insufficient balance" is something the restaurant must act on.
            $this->assertStringContainsString('Insufficient balance', $e->getMessage());
        }

        $row = PaymentRefund::query()->latest('id')->firstOrFail();

        // The row survives. A refund that vanished on failure would leave
        // somebody certain they had paid a guest who is still out of pocket.
        $this->assertSame(PaymentRefund::FAILED, $row->status);
        $this->assertStringContainsString('Insufficient balance', $row->error);
    }

    public function test_a_failed_refund_does_not_reduce_what_is_still_refundable(): void
    {
        $this->live();
        Http::fake(['api.example.test/*' => Http::response(['error' => ['description' => 'nope']], 400)]);

        $intent = $this->paid(500);

        try {
            $this->service()->refund($intent, 500, 'first attempt');
        } catch (RuntimeException $e) {
            // expected
        }

        // Money that never left is money the guest is still owed.
        $this->assertEqualsWithDelta(500, $this->service()->refundableAmount($intent->fresh()), 0.01);
        $this->assertSame(PaymentIntent::PAID, $intent->fresh()->status);
    }

    public function test_a_counter_payment_says_to_refund_at_the_counter(): void
    {
        app(GatewayManager::class)->swap(new OfflineGateway());

        $intent = $this->paid(500);

        try {
            $this->service()->refund($intent, 500, 'dish never arrived');
            $this->fail('an offline payment was refunded through a gateway');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('refunded at the counter', $e->getMessage());
        }
    }

    /* ------------------------------------------------------- the screens */

    public function test_the_three_screens_open(): void
    {
        $this->live();

        $this->get(route('admin.online-payments.index'))->assertOk();
        $this->get(route('admin.online-payments.refunds'))->assertOk();
        $this->get(route('admin.online-payments.settlements'))->assertOk();
    }

    public function test_refunding_over_http(): void
    {
        $this->live();
        $this->fakeRefund();

        $intent = $this->paid(500);

        $response = $this->postJson(route('admin.online-payments.refund', $intent), [
            'amount' => 250,
            'reason' => 'starter never arrived',
        ]);

        $response->assertOk();
        $this->assertStringContainsString('three to five working days', $response->json('message'));
        $this->assertSame(1, PaymentRefund::query()->count());
    }

    public function test_refunding_needs_its_own_right(): void
    {
        /*
         | A different account, not the one setUp signed in.
         |
         | givePermissionTo is additive, so re-calling the helper with a
         | narrower list would hand back the same user still holding the
         | right - and the test would pass while proving nothing.
         */
        $reader = User::factory()->create([
            'tenant_id' => $this->shop()->tenant_id,
            'is_admin' => true,
        ]);
        $reader->shops()->syncWithoutDetaching([$this->shop()->id => ['is_default' => true]]);
        $reader->forceFill(['current_shop_id' => $this->shop()->id, 'all_shops_view' => false])->save();
        $reader->givePermissionTo(['dashboard.overview.view', 'finance.online_payments.view']);

        $this->actingAs($reader->fresh());
        CurrentShop::forget();
        $this->live();

        // It sends real money out of the restaurant's account, so it is not
        // something `view` carries along with it.
        $this->postJson(route('admin.online-payments.refund', $this->paid()), [
            'amount' => 100, 'reason' => 'trying it on',
        ])->assertForbidden();
    }

    public function test_the_settlement_report_nets_refunds_off_the_takings(): void
    {
        $this->live();
        $this->fakeRefund();

        $intent = $this->paid(500);
        $this->service()->refund($intent, 200, 'one dish');

        $this->get(route('admin.online-payments.settlements'))
            ->assertOk()
            ->assertSee('500.00')
            ->assertSee('200.00')
            // 500 taken less 200 back.
            ->assertSee('300.00');
    }
}
