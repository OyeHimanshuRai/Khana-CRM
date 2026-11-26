<?php

namespace Tests\Feature;

use App\Mail\TableBookedMail;
use App\Models\Customer;
use App\Models\Floor;
use App\Models\PaymentIntent;
use App\Models\Reservation;
use App\Models\RestaurantTable;
use App\Models\Shop;
use App\Models\User;
use App\Services\OnlineBookingService;
use App\Services\Payments\GatewayManager;
use App\Services\Payments\OfflineGateway;
use App\Services\Payments\RazorpayGateway;
use App\Support\CurrentShop;
use App\Support\Modules;
use App\Support\OnlineBooking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

// Guests booking a table on the storefront: slots, holds, the Razorpay advance, refunds and expiry.
class OnlineBookingTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'rzp_test_secret';

    private const WEBHOOK_SECRET = 'rzp_webhook_secret';

    private int $orders = 0;

    protected function setUp(): void
    {
        parent::setUp();
        URL::forceRootUrl('http://localhost');
        $this->seed();
        CurrentShop::forget();
        Mail::fake();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        CurrentShop::forget();
        parent::tearDown();
    }

    /* ------------------------------------------------------------ fixtures */

    private function shop(): Shop
    {
        return Shop::query()->withoutGlobalScopes()->orderBy('id')->firstOrFail();
    }

    private function enable(array $overrides = []): Shop
    {
        $shop = $this->shop();
        $modules = array_values(array_unique(array_merge((array) ($shop->modules ?? Modules::defaults()), ['dining', 'storefront'])));
        $shop->forceFill([
            'modules' => $modules,
            'online_booking' => array_merge(OnlineBooking::DEFAULTS, [
                'enabled' => true,
                'opens' => '00:00',
                'closes' => '23:30',
                'lead_minutes' => 0,
                'deposit_mode' => OnlineBooking::DEPOSIT_FIXED,
                'deposit_amount' => 200,
            ], $overrides),
        ])->save();
        Modules::forget();

        return $shop->fresh();
    }

    private function table(string $name, int $capacity): RestaurantTable
    {
        $floor = Floor::query()->withoutGlobalScopes()->firstOrCreate(
            ['shop_id' => $this->shop()->id, 'code' => 'GF'],
            ['name' => 'Ground Floor', 'is_active' => true],
        );

        return RestaurantTable::query()->withoutGlobalScopes()->create([
            'shop_id' => $floor->shop_id, 'floor_id' => $floor->id, 'name' => $name, 'code' => 'GF-'.$name,
            'capacity' => $capacity, 'status' => RestaurantTable::AVAILABLE, 'is_active' => true,
        ]);
    }

    private function guest(string $email = 'riya@example.test'): Customer
    {
        $shop = $this->shop();

        return Customer::query()->withoutGlobalScopes()->create([
            'shop_id' => $shop->id, 'code' => Customer::nextCode($shop->id), 'name' => 'Riya Sharma',
            'mobile' => '98'.str_pad((string) (crc32($email) % 100000000), 8, '0', STR_PAD_LEFT), 'email' => $email, 'password' => Hash::make('secret-123'),
            'type' => 'retail', 'is_active' => true,
        ]);
    }

    private function live(): void
    {
        app(GatewayManager::class)->swap(new RazorpayGateway([
            'label' => 'Pay now', 'key' => 'rzp_test_key', 'secret' => self::SECRET,
            'webhook_secret' => self::WEBHOOK_SECRET, 'currency' => 'INR',
            'checkout_js' => 'https://example.test/checkout.js', 'api' => 'https://api.example.test/v1',
        ]));

        Http::fake(function (HttpRequest $request) {
            if (str_contains($request->url(), '/refund')) {
                return Http::response(['id' => 'rfnd_'.uniqid(), 'status' => 'processed'], 200);
            }
            $this->orders++;

            return Http::response(['id' => 'order_T'.$this->orders, 'amount' => $request['amount'], 'currency' => 'INR', 'status' => 'created'], 200);
        });
    }

    private function day(int $ahead = 3): string
    {
        return OnlineBooking::now($this->shop())->addDays($ahead)->toDateString();
    }

    private function url(string $name, array $params = []): string
    {
        return route($name, array_merge(['shop' => $this->shop()->slug], $params));
    }

    private function book(Customer $guest, string $time = '19:00', int $party = 2, ?string $date = null)
    {
        return $this->actingAs($guest, 'customer')->postJson($this->url('shop.book.store'), [
            'date' => $date ?? $this->day(), 'time' => $time, 'party_size' => $party,
            'name' => 'Riya Sharma', 'mobile' => '98765 43210', 'notes' => 'Window seat',
        ]);
    }

    private function payBrowser(Customer $guest, array $held, string $paymentId = 'pay_OK1')
    {
        $orderId = $held['checkout']['order_id'];

        return $this->actingAs($guest, 'customer')->postJson($held['confirm_url'], [
            'reference' => $held['reference'],
            'razorpay_order_id' => $orderId,
            'razorpay_payment_id' => $paymentId,
            'razorpay_signature' => hash_hmac('sha256', $orderId.'|'.$paymentId, self::SECRET),
        ]);
    }

    private function postWebhook(string $orderId, string $paymentId, int $paise)
    {
        $body = json_encode(['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => [
            'id' => $paymentId, 'order_id' => $orderId, 'status' => 'captured', 'amount' => $paise,
        ]]]]);

        return $this->call('POST', '/payments/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_RAZORPAY_SIGNATURE' => hash_hmac('sha256', $body, self::WEBHOOK_SECRET),
        ], $body);
    }

    /* --------------------------------------------------------------- page */

    public function test_the_page_says_so_when_booking_is_off(): void
    {
        $this->get($this->url('shop.book'))->assertOk()->assertSee('not available right now');
        $this->getJson($this->url('shop.book.slots', ['date' => $this->day(), 'party_size' => 2]))->assertStatus(422);
    }

    public function test_slots_show_free_and_full_times_and_refuse_bad_dates(): void
    {
        $this->enable();
        $this->table('1', 4);
        $this->live();

        $this->get($this->url('shop.book'))->assertOk()->assertSee('Book a table')->assertSee('data-book', false);

        $res = $this->getJson($this->url('shop.book.slots', ['date' => $this->day(), 'party_size' => 2]))->assertOk();
        $this->assertTrue(collect($res->json('data.slots'))->firstWhere('time', '19:00')['available']);
        $this->assertEquals(200, $res->json('data.deposit'));

        $this->getJson($this->url('shop.book.slots', ['date' => $this->day(-1), 'party_size' => 2]))->assertStatus(422);
        $this->getJson($this->url('shop.book.slots', ['date' => $this->day(60), 'party_size' => 2]))->assertStatus(422);
        $this->getJson($this->url('shop.book.slots', ['date' => $this->day(), 'party_size' => 20]))->assertStatus(422)->assertJsonPath('message', 'For more than 10 guests, please call the restaurant.');
    }

    public function test_a_party_too_big_for_every_table_sees_no_free_time(): void
    {
        $this->enable();
        $this->table('1', 2);

        $res = $this->getJson($this->url('shop.book.slots', ['date' => $this->day(), 'party_size' => 6]))->assertOk();
        $this->assertFalse(collect($res->json('data.slots'))->contains('available', true));
    }

    /* ----------------------------------------------------- hold and pay */

    public function test_booking_needs_a_signed_in_guest(): void
    {
        $this->enable();
        $this->table('1', 4);

        $this->postJson($this->url('shop.book.store'), ['date' => $this->day(), 'time' => '19:00', 'party_size' => 2, 'name' => 'X Y', 'mobile' => '9876543210'])
            ->assertUnauthorized();
    }

    public function test_a_deposit_holds_the_table_then_a_signed_payment_confirms_it(): void
    {
        $this->enable();
        $this->table('1', 4);
        $this->live();
        $guest = $this->guest();

        $held = $this->book($guest)->assertOk()->assertJsonPath('data.paid', false)->json('data');
        $this->assertEquals(200, $held['amount']);

        $reservation = Reservation::allShops()->findOrFail($held['booking']);
        $this->assertSame(Reservation::REQUESTED, $reservation->status);
        $this->assertTrue($reservation->awaitingPayment());
        $this->assertSame('online', $reservation->source);
        $this->assertSame('9876543210', $reservation->guest_mobile, 'the number typed for the booking, spaces removed');

        // The held table is not offered to anyone else.
        $slots = $this->getJson($this->url('shop.book.slots', ['date' => $this->day(), 'party_size' => 2]))->json('data.slots');
        $this->assertFalse(collect($slots)->firstWhere('time', '19:00')['available']);

        $this->payBrowser($guest, $held)->assertOk();

        $reservation->refresh();
        $this->assertSame(Reservation::CONFIRMED, $reservation->status);
        $this->assertNull($reservation->hold_expires_at);
        $this->assertNotNull($reservation->confirmed_at);
        Mail::assertQueued(TableBookedMail::class);
        $this->assertDatabaseHas('alerts', ['type' => 'reservation', 'shop_id' => $this->shop()->id]);
    }

    public function test_a_forged_signature_confirms_nothing(): void
    {
        $this->enable();
        $this->table('1', 4);
        $this->live();
        $guest = $this->guest();
        $held = $this->book($guest)->json('data');

        $this->actingAs($guest, 'customer')->postJson($held['confirm_url'], [
            'reference' => $held['reference'], 'razorpay_order_id' => $held['checkout']['order_id'],
            'razorpay_payment_id' => 'pay_FAKE', 'razorpay_signature' => str_repeat('a', 64),
        ])->assertStatus(422);

        $this->assertSame(Reservation::REQUESTED, Reservation::allShops()->find($held['booking'])->status);
    }

    public function test_the_webhook_confirms_when_the_browser_never_came_back(): void
    {
        $this->enable();
        $this->table('1', 4);
        $this->live();
        $held = $this->book($this->guest())->json('data');

        $this->postWebhook($held['checkout']['order_id'], 'pay_WH1', 20000)->assertOk();

        $this->assertSame(Reservation::CONFIRMED, Reservation::allShops()->find($held['booking'])->status);
    }

    public function test_paying_twice_confirms_once_and_refunds_the_second(): void
    {
        $this->enable();
        $this->table('1', 4);
        $this->live();
        $guest = $this->guest();
        $first = $this->book($guest)->json('data');
        $this->payBrowser($guest, $first, 'pay_A')->assertOk();

        // A second tab paid a fresh order for the same booking.
        $reservation = Reservation::allShops()->find($first['booking']);
        $reservation->forceFill(['status' => Reservation::REQUESTED, 'hold_expires_at' => now()->addMinutes(5)])->save();
        $second = $this->actingAs($guest, 'customer')->postJson($this->url('shop.bookings.pay', ['booking' => $reservation->id]))->json('data');
        $reservation->forceFill(['status' => Reservation::CONFIRMED, 'hold_expires_at' => null])->save();
        $this->payBrowser($guest, $second, 'pay_B');

        $this->assertSame(PaymentIntent::REFUNDED, PaymentIntent::allShops()->where('provider_payment_id', 'pay_B')->first()->status);
        $this->assertSame(PaymentIntent::PAID, PaymentIntent::allShops()->where('provider_payment_id', 'pay_A')->first()->status);
    }

    public function test_two_guests_cannot_take_the_last_table(): void
    {
        $this->enable();
        $this->table('1', 4);
        $this->live();

        $this->book($this->guest('a@example.test'))->assertOk();
        $this->book($this->guest('b@example.test'))->assertStatus(409)->assertJsonPath('message', 'Sorry, that time was just taken. Please choose another.');
    }

    public function test_the_smallest_fitting_table_is_used_so_big_tables_stay_free(): void
    {
        $this->enable();
        $big = $this->table('Big', 8);
        $small = $this->table('Small', 2);
        $this->live();

        $held = $this->book($this->guest(), '19:00', 2)->json('data');
        $this->assertSame($small->id, (int) Reservation::allShops()->find($held['booking'])->restaurant_table_id);

        $slots = $this->getJson($this->url('shop.book.slots', ['date' => $this->day(), 'party_size' => 6]))->json('data.slots');
        $this->assertTrue(collect($slots)->firstWhere('time', '19:00')['available']);
        $this->assertNotNull($big);
    }

    public function test_no_advance_means_confirmed_at_once(): void
    {
        $this->enable(['deposit_mode' => OnlineBooking::DEPOSIT_NONE, 'deposit_amount' => 0]);
        $this->table('1', 4);
        app(GatewayManager::class)->swap(new OfflineGateway);

        $res = $this->book($this->guest())->assertOk()->assertJsonPath('data.paid', true);
        $reservation = Reservation::allShops()->latest('id')->first();
        $this->assertSame(Reservation::CONFIRMED, $reservation->status);
        $this->assertStringEndsWith('/bookings/'.$reservation->id, $res->json('redirect'));
        Http::assertNothingSent();
    }

    public function test_an_advance_without_online_payment_is_refused(): void
    {
        $this->enable();
        $this->table('1', 4);
        app(GatewayManager::class)->swap(new OfflineGateway);

        $this->book($this->guest())->assertStatus(409);
        $this->assertSame(0, Reservation::allShops()->count());
    }

    public function test_per_person_advance_scales_with_the_party(): void
    {
        $this->enable(['deposit_mode' => OnlineBooking::DEPOSIT_PER_PERSON, 'deposit_amount' => 100]);
        $this->table('1', 6);
        $this->live();

        $this->book($this->guest(), '20:00', 5)->assertOk()->assertJsonPath('data.amount', 500);
    }

    /* --------------------------------------------- failure, retry, expiry */

    public function test_a_failed_payment_keeps_the_hold_and_pay_now_opens_a_new_attempt(): void
    {
        $this->enable();
        $this->table('1', 4);
        $this->live();
        $guest = $this->guest();
        $held = $this->book($guest)->json('data');

        $this->actingAs($guest, 'customer')->postJson($held['failed_url'], ['reference' => $held['reference'], 'reason' => 'Bank declined'])->assertOk();
        $this->assertSame(PaymentIntent::FAILED, PaymentIntent::allShops()->where('reference', $held['reference'])->first()->status);

        $again = $this->actingAs($guest, 'customer')->postJson($this->url('shop.bookings.pay', ['booking' => $held['booking']]))->assertOk()->json('data');
        $this->assertNotSame($held['reference'], $again['reference']);
        $this->payBrowser($guest, $again)->assertOk();
        $this->assertSame(Reservation::CONFIRMED, Reservation::allShops()->find($held['booking'])->status);
    }

    public function test_an_unpaid_hold_expires_and_the_table_opens_again(): void
    {
        $this->enable();
        $this->table('1', 4);
        $this->live();
        $guest = $this->guest();
        $held = $this->book($guest)->json('data');

        $this->travel(15)->minutes();
        $this->artisan('reservations:expire-holds')->assertSuccessful();

        $reservation = Reservation::allShops()->find($held['booking']);
        $this->assertSame(Reservation::CANCELLED, $reservation->status);
        $this->assertSame(OnlineBookingService::EXPIRED_NOTE, $reservation->outcome_note);
        $this->assertSame(PaymentIntent::FAILED, PaymentIntent::allShops()->where('reference', $held['reference'])->first()->status);

        $slots = $this->getJson($this->url('shop.book.slots', ['date' => $this->day(), 'party_size' => 2]))->json('data.slots');
        $this->assertTrue(collect($slots)->firstWhere('time', '19:00')['available']);
        $this->actingAs($guest, 'customer')->postJson($this->url('shop.bookings.pay', ['booking' => $held['booking']]))->assertStatus(409);
    }

    public function test_money_arriving_after_expiry_keeps_a_still_free_table(): void
    {
        $this->enable();
        $this->table('1', 4);
        $this->live();
        $held = $this->book($this->guest())->json('data');
        $this->travel(15)->minutes();
        $this->artisan('reservations:expire-holds');

        $this->postWebhook($held['checkout']['order_id'], 'pay_LATE', 20000)->assertOk();

        $this->assertSame(Reservation::CONFIRMED, Reservation::allShops()->find($held['booking'])->status);
    }

    public function test_money_arriving_after_the_table_was_rebooked_is_refunded(): void
    {
        $this->enable();
        $this->table('1', 4);
        $this->live();
        $held = $this->book($this->guest('a@example.test'))->json('data');
        $this->travel(15)->minutes();
        $this->artisan('reservations:expire-holds');
        $this->book($this->guest('b@example.test'))->assertOk();

        $this->postWebhook($held['checkout']['order_id'], 'pay_LATE', 20000)->assertOk();

        $late = Reservation::allShops()->find($held['booking']);
        $this->assertSame(Reservation::CANCELLED, $late->status);
        $this->assertStringContainsString('refund', $late->outcome_note);
        $this->assertSame(PaymentIntent::REFUNDED, PaymentIntent::allShops()->where('provider_payment_id', 'pay_LATE')->first()->status);
    }

    /* ----------------------------------------------------------- cancel */

    public function test_cancelling_in_time_refunds_the_advance_and_frees_the_table(): void
    {
        $this->enable(['cancel_hours' => 24]);
        $this->table('1', 4);
        $this->live();
        $guest = $this->guest();
        $held = $this->book($guest)->json('data');
        $this->payBrowser($guest, $held);

        $this->actingAs($guest, 'customer')->postJson($this->url('shop.bookings.cancel', ['booking' => $held['booking']]))
            ->assertOk()->assertJsonPath('message', 'Booking cancelled. Your advance has been refunded.');

        $this->assertSame(Reservation::CANCELLED, Reservation::allShops()->find($held['booking'])->status);
        $this->assertSame(PaymentIntent::REFUNDED, PaymentIntent::allShops()->where('provider_payment_id', 'pay_OK1')->first()->status);
        $slots = $this->getJson($this->url('shop.book.slots', ['date' => $this->day(), 'party_size' => 2]))->json('data.slots');
        $this->assertTrue(collect($slots)->firstWhere('time', '19:00')['available']);
    }

    public function test_cancelling_too_late_is_refused(): void
    {
        $this->enable(['cancel_hours' => 24]);
        $this->table('1', 4);
        $this->live();
        $guest = $this->guest();
        $tomorrow = OnlineBooking::now($this->shop())->addHours(5);
        if ($tomorrow->format('H:i') > '23:30') {
            $tomorrow->setTime(23, 0);
        }
        $time = $tomorrow->copy()->minute($tomorrow->minute < 30 ? 30 : 0)->addHour($tomorrow->minute < 30 ? 0 : 1)->format('H:i');
        $held = $this->book($guest, $time, 2, $tomorrow->toDateString())->assertOk()->json('data');
        $this->payBrowser($guest, $held);

        $this->actingAs($guest, 'customer')->postJson($this->url('shop.bookings.cancel', ['booking' => $held['booking']]))
            ->assertStatus(409);
        $this->assertSame(Reservation::CONFIRMED, Reservation::allShops()->find($held['booking'])->status);
    }

    public function test_a_guest_cannot_see_or_cancel_someone_elses_booking(): void
    {
        $this->enable();
        $this->table('1', 4);
        $this->live();
        $held = $this->book($this->guest('a@example.test'))->json('data');
        $other = $this->guest('b@example.test');

        $this->actingAs($other, 'customer')->get($this->url('shop.bookings.show', ['booking' => $held['booking']]))->assertNotFound();
        $this->actingAs($other, 'customer')->postJson($this->url('shop.bookings.cancel', ['booking' => $held['booking']]))->assertNotFound();
        $this->actingAs($other, 'customer')->postJson($held['confirm_url'], ['reference' => $held['reference']])->assertNotFound();
    }

    public function test_the_guest_sees_their_bookings(): void
    {
        $this->enable();
        $this->table('1', 4);
        $this->live();
        $guest = $this->guest();
        $held = $this->book($guest)->json('data');

        $this->actingAs($guest, 'customer')->get($this->url('shop.bookings.index'))->assertOk()->assertSee('Awaiting payment')->assertSee('Pay now');
        $this->actingAs($guest, 'customer')->get($this->url('shop.bookings.show', ['booking' => $held['booking']]))->assertOk()->assertSee('Booking #'.$held['booking']);
    }

    /* ------------------------------------------------------------ admin */

    private function staff(array $permissions): User
    {
        $shop = $this->shop();
        $user = User::factory()->create(['tenant_id' => $shop->tenant_id, 'is_admin' => true]);
        $user->shops()->syncWithoutDetaching([$shop->id => ['is_default' => true]]);
        $user->forceFill(['current_shop_id' => $shop->id, 'all_shops_view' => false])->save();
        $user->givePermissionTo(array_merge(['dining.reservations.view'], $permissions));

        return $user->fresh();
    }

    public function test_the_restaurant_cancelling_refunds_the_advance_by_default(): void
    {
        $this->enable();
        $this->table('1', 4);
        $this->live();
        $guest = $this->guest();
        $held = $this->book($guest)->json('data');
        $this->payBrowser($guest, $held);
        $staff = $this->staff(['dining.reservations.adjust']);

        $this->actingAs($staff)->get(route('admin.reservations.show', $held['booking']))
            ->assertOk()->assertSee('Refund the advance')->assertSee('Paid');

        $this->actingAs($staff)->putJson(route('admin.reservations.cancel', $held['booking']), ['outcome_note' => 'Kitchen closed'])
            ->assertOk()->assertJsonPath('message', 'Booking cancelled and the advance refunded. The table is free again.');

        $this->assertSame(Reservation::CANCELLED, Reservation::allShops()->find($held['booking'])->status);
        $this->assertSame(PaymentIntent::REFUNDED, PaymentIntent::allShops()->where('provider_payment_id', 'pay_OK1')->first()->status);

        $this->actingAs($staff)->get(route('admin.reservations.show', $held['booking']))->assertOk()->assertSee('Refunded');
    }

    public function test_the_restaurant_can_cancel_and_keep_the_advance(): void
    {
        $this->enable();
        $this->table('1', 4);
        $this->live();
        $guest = $this->guest();
        $held = $this->book($guest)->json('data');
        $this->payBrowser($guest, $held);

        $this->actingAs($this->staff(['dining.reservations.adjust']))
            ->putJson(route('admin.reservations.cancel', $held['booking']), ['refund' => 0])
            ->assertOk()->assertJsonPath('message', 'Booking cancelled. The table is free again.');

        $this->assertSame(PaymentIntent::PAID, PaymentIntent::allShops()->where('provider_payment_id', 'pay_OK1')->first()->status);
    }

    public function test_cancelling_an_unpaid_online_booking_closes_its_payment(): void
    {
        $this->enable();
        $this->table('1', 4);
        $this->live();
        $held = $this->book($this->guest())->json('data');

        $this->actingAs($this->staff(['dining.reservations.adjust']))
            ->putJson(route('admin.reservations.cancel', $held['booking']))->assertOk();

        $this->assertSame(PaymentIntent::FAILED, PaymentIntent::allShops()->where('reference', $held['reference'])->first()->status);
        $this->assertNull(Reservation::allShops()->find($held['booking'])->hold_expires_at);
    }

    public function test_a_phone_booking_cancels_as_before_with_no_refund_attempt(): void
    {
        $this->enable();
        $this->table('1', 4);
        $this->live();
        $reservation = Reservation::allShops()->create([
            'shop_id' => $this->shop()->id, 'guest_name' => 'Walk Phone', 'party_size' => 2,
            'reserved_for' => now()->addDays(2), 'duration_minutes' => 90, 'status' => Reservation::CONFIRMED, 'source' => 'phone',
        ]);

        $this->actingAs($this->staff(['dining.reservations.adjust']))
            ->putJson(route('admin.reservations.cancel', $reservation->id))->assertOk()
            ->assertJsonPath('message', 'Booking cancelled. The table is free again.');
        Http::assertNothingSent();
    }

    public function test_the_owner_saves_the_rules_and_an_advance_needs_online_payment(): void
    {
        $shop = $this->shop();
        $owner = User::query()->withoutGlobalScopes()->where('tenant_id', $shop->tenant_id)->whereHas('roles', fn ($q) => $q->where('name', 'Tenant Owner'))->first()
            ?? User::query()->withoutGlobalScopes()->where('is_admin', true)->orderBy('id')->first();
        $owner->givePermissionTo('dining.reservations.edit');
        $owner->forceFill(['current_shop_id' => $shop->id])->save();

        $form = ['enabled' => 1, 'opens' => '12:00', 'closes' => '22:00', 'days' => [1, 2, 3, 4, 5, 6], 'slot_minutes' => 30, 'duration_minutes' => 90,
            'max_party' => 8, 'lead_minutes' => 60, 'window_days' => 30, 'deposit_mode' => 'fixed', 'deposit_amount' => 250, 'cancel_hours' => 12, 'hold_minutes' => 10];

        app(GatewayManager::class)->swap(new OfflineGateway);
        $this->actingAs($owner)->putJson(route('admin.reservations.online.update'), $form)->assertStatus(422);

        $this->live();
        $this->actingAs($owner)->putJson(route('admin.reservations.online.update'), $form)->assertOk();
        $saved = OnlineBooking::settings($shop->fresh());
        $this->assertTrue($saved['enabled']);
        $this->assertEquals(250, $saved['deposit_amount']);
        $this->assertSame(8, $saved['max_party']);

        $this->actingAs($owner)->get(route('admin.reservations.online'))->assertOk()->assertSee('Take bookings online');
    }
}
