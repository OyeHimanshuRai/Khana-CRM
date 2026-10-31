<?php

namespace Tests\Feature;

use App\Mail\GuestThanksMail;
use App\Mail\SubscriptionPaidMail;
use App\Models\Alert;
use App\Models\Customer;
use App\Models\DeviceToken;
use App\Models\FirebaseSetting;
use App\Models\Floor;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\RestaurantTable;
use App\Models\Shop;
use App\Models\Subscription;
use App\Models\TableSession;
use App\Models\User;
use App\Services\AlertPushService;
use App\Services\AlertService;
use App\Services\EventNotifier;
use App\Services\FcmService;
use App\Services\SubscriptionService;
use App\Services\TableBillService;
use App\Services\TableQrService;
use App\Support\CurrentShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Who is told what, as it happens: the live bell, the push to a phone, the
 * receipt and the guest's thank-you, and the guest's own order going from
 * Placed to Ready without a reload.
 */
class LiveNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');

        $this->seed();
        Mail::fake();
    }

    /* ---------------------------------------------------------- fixtures */

    private function shop(): Shop
    {
        return Shop::query()->withoutGlobalScopes()->orderBy('id')->firstOrFail();
    }

    private function staff(array $permissions, ?string $email = null): User
    {
        $shop = $this->shop();

        $user = User::create([
            'tenant_id' => $shop->tenant_id,
            'name' => 'Staff '.uniqid(),
            'email' => $email ?? 'staff'.uniqid().'@example.test',
            'password' => 'staff-password-1',
            'is_admin' => true,
            'is_active' => true,
        ]);

        $user->shops()->syncWithoutDetaching([$shop->id => ['is_default' => true]]);
        $user->forceFill(['current_shop_id' => $shop->id, 'all_shops_view' => false])->save();
        $user->givePermissionTo(array_merge(['dashboard.overview.view'], $permissions));

        return $user->fresh();
    }

    private function admin(): User
    {
        return User::query()->withoutGlobalScopes()->where('email', 'admin@erp.test')->firstOrFail();
    }

    private function table(): RestaurantTable
    {
        $floor = Floor::create(['shop_id' => $this->shop()->id, 'name' => 'Ground Floor', 'code' => 'GF', 'is_active' => true]);

        $table = RestaurantTable::create([
            'shop_id' => $floor->shop_id, 'floor_id' => $floor->id, 'name' => '4', 'code' => 'GF-04',
            'capacity' => 4, 'status' => RestaurantTable::AVAILABLE, 'is_active' => true,
        ]);

        app(TableQrService::class)->issue($table);

        return $table->fresh(['activeQr']);
    }

    private function dish(string $name = 'Paneer Tikka'): Product
    {
        return Product::create([
            'name' => $name, 'slug' => Product::uniqueSlug($name), 'sku' => Product::generateSku($name),
            'selling_price' => 280, 'is_active' => true, 'is_made_to_order' => true,
        ]);
    }

    /** Scan, add, send - the way a guest's phone does. */
    private function guestOrders(RestaurantTable $table, Product $dish): Order
    {
        $this->get('/t/'.$table->activeQr->token)->assertRedirect(route('table.show'));
        $this->post('/t/cart', ['product_id' => $dish->id])->assertRedirect();
        $this->post('/t/order', ['guest_name' => 'Asha', 'guest_mobile' => '9876543210']);

        return Order::allShops()->latest('id')->firstOrFail();
    }

    private function firebase(): FirebaseSetting
    {
        $setting = new FirebaseSetting();
        $setting->forceFill([
            'is_enabled' => true,
            'project_id' => 'demo-pos',
            'api_key' => 'AIzaTestKey',
            'messaging_sender_id' => '1234567890',
            'app_id' => '1:1234567890:web:abc',
            'vapid_key' => 'BTestVapidKey',
            'service_account' => [
                'client_email' => 'push@demo-pos.iam.gserviceaccount.com',
                'private_key' => $this->privateKey(),
                'token_uri' => 'https://oauth2.googleapis.com/token',
            ],
        ])->save();

        return $setting;
    }

    /** A throwaway RSA key, for signing the JWT in a test. */
    private function privateKey(): string
    {
        foreach ([[], ['config' => 'C:\\xampp\\php\\extras\\ssl\\openssl.cnf'], ['config' => '/etc/ssl/openssl.cnf']] as $extra) {
            $key = @openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA] + $extra);

            if ($key !== false && @openssl_pkey_export($key, $pem, null, $extra) && filled($pem)) {
                return $pem;
            }
        }

        $this->markTestSkipped('OpenSSL cannot generate a key on this machine.');
    }

    /* ------------------------------------------------------- the live bell */

    public function test_a_guest_order_rings_the_bell_for_staff_who_handle_orders(): void
    {
        $order = $this->guestOrders($this->table(), $this->dish());

        $alert = Alert::allShops()->where('type', Alert::ORDER_PLACED)->firstOrFail();

        $this->assertSame('order:'.$order->id, $alert->dedupe_key);
        $this->assertStringContainsString('Table 4', $alert->title);
        $this->assertStringContainsString('280.00', (string) $alert->body);

        $this->assertTrue($alert->isVisibleTo($this->staff(['sales.orders.view'])));
        $this->assertFalse($alert->isVisibleTo($this->staff(['pos.terminal.view'])), 'not every cashier needs every ticket');
    }

    public function test_the_feed_gives_the_count_and_only_what_is_new(): void
    {
        Auth::logout();
        $manager = $this->staff(['sales.orders.view']);

        $this->guestOrders($this->table(), $this->dish());
        Auth::logout();
        CurrentShop::forget();

        $first = $this->actingAs($manager)->getJson(route('admin.alerts.feed', ['after' => 0]))
            ->assertOk()
            ->assertJsonPath('success', true);

        $latest = $first->json('data.latest_id');
        $this->assertGreaterThan(0, $first->json('data.unread'));
        $this->assertContains('order_placed', array_column($first->json('data.items'), 'type'));

        // Nothing new since: no toasts, the same count.
        $this->actingAs($manager)->getJson(route('admin.alerts.feed', ['after' => $latest]))
            ->assertOk()
            ->assertJsonPath('data.items', [])
            ->assertJsonPath('data.unread', $first->json('data.unread'));
    }

    public function test_the_header_renders_the_count_and_where_to_poll(): void
    {
        $manager = $this->staff(['sales.orders.view']);

        $this->actingAs($manager)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('data-alert-bell', false)
            ->assertSee(route('admin.alerts.feed'), false)
            ->assertSee('data-alert-count', false)
            ->assertSee('alerts-live.js', false)
            // Firebase is not configured, so no config and no device button.
            ->assertDontSee('id="firebase-config"', false);
    }

    public function test_a_low_rating_is_a_louder_alert(): void
    {
        $table = $this->table();
        $this->guestOrders($table, $this->dish());

        $this->post('/t/feedback', ['rating' => 2, 'comment' => 'Cold food']);

        $alert = Alert::allShops()->where('type', Alert::FEEDBACK)->firstOrFail();
        $this->assertSame('warning', $alert->level);
        $this->assertSame('crm.feedback.view', $alert->can);

        // Changing their mind updates the one alert rather than adding another.
        $this->post('/t/feedback', ['rating' => 5]);
        $this->assertSame(1, Alert::allShops()->where('type', Alert::FEEDBACK)->count());
    }

    public function test_a_signup_is_for_the_platform_and_not_for_the_new_owner(): void
    {
        $this->postJson('/register', [
            'business_name' => 'Spice Garden', 'name' => 'Asha Rao', 'email' => 'asha@spicegarden.test',
            'mobile' => '+91 98765 43210', 'password' => 'Secret123', 'password_confirmation' => 'Secret123',
            'plan' => 'restaurant', 'period' => 'monthly', 'terms' => '1',
        ])->assertOk();

        $owner = User::query()->withoutGlobalScopes()->where('email', 'asha@spicegarden.test')->firstOrFail();
        $alert = Alert::allShops()->where('type', Alert::SIGNUP)->firstOrFail();

        $this->assertTrue($alert->isVisibleTo($this->admin()));
        $this->assertFalse($alert->isVisibleTo($owner));
    }

    /* ------------------------------------------------------------- email */

    public function test_paying_for_a_plan_emails_the_owner_a_receipt_and_tells_the_platform(): void
    {
        $this->postJson('/register', [
            'business_name' => 'Spice Garden', 'name' => 'Asha Rao', 'email' => 'asha@spicegarden.test',
            'mobile' => '+91 98765 43210', 'password' => 'Secret123', 'password_confirmation' => 'Secret123',
            'plan' => 'restaurant', 'period' => 'monthly', 'terms' => '1',
        ])->assertOk();
        Auth::logout();

        $owner = User::query()->withoutGlobalScopes()->where('email', 'asha@spicegarden.test')->firstOrFail();
        $subscription = Subscription::query()->where('tenant_id', $owner->tenant_id)->latest('id')->firstOrFail();

        $payment = app(SubscriptionService::class)->recordPayment($subscription, 1999, 'manual');

        Mail::assertQueued(SubscriptionPaidMail::class, fn ($mail) => $mail->hasTo('asha@spicegarden.test')
            && $mail->payment->is($payment));

        $alert = Alert::allShops()->where('type', Alert::SUBSCRIPTION_PAID)->firstOrFail();
        $this->assertTrue($alert->isVisibleTo($this->admin()));
        $this->assertFalse($alert->isVisibleTo($owner));

        $html = (new SubscriptionPaidMail($payment, 'Asha Rao'))->render();
        $this->assertStringContainsString($payment->reference, $html);
        $this->assertStringContainsString('1,999.00', $html);
    }

    public function test_the_guest_is_thanked_for_a_first_visit_and_welcomed_back_after(): void
    {
        $customer = new Customer([
            'shop_id' => $this->shop()->id, 'code' => 'C-1', 'name' => 'Asha Verma',
            'mobile' => '9811111111', 'email' => 'asha@guest.test', 'type' => 'regular',
        ]);
        $customer->saveQuietly();

        $table = $this->table();
        $dish = $this->dish();

        foreach ([true, false] as $expectFirst) {
            $this->guestOrders($table, $dish);
            Auth::login($this->admin());

            $session = TableSession::allShops()->live()->latest('id')->firstOrFail();
            $session->forceFill(['customer_id' => $customer->id])->save();

            app(TableBillService::class)->settle($session->fresh(), [
                'payments' => [['method' => 'cash', 'amount' => 280]],
            ]);

            Mail::assertQueued(GuestThanksMail::class, fn ($mail) => $mail->hasTo('asha@guest.test')
                && $mail->firstVisit === $expectFirst);

            Auth::logout();
            CurrentShop::forget();
            $this->flushSession();
        }

        $this->assertStringContainsString('first visit', (new GuestThanksMail(Invoice::allShops()->firstOrFail(), true))->render());
    }

    /* -------------------------------------------------------------- push */

    public function test_a_new_alert_is_pushed_once_to_the_devices_of_people_who_may_see_it(): void
    {
        $this->firebase();
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::response(['name' => 'projects/demo-pos/messages/1']),
        ]);

        $manager = $this->staff(['sales.orders.view']);
        $cashier = $this->staff(['pos.terminal.view']);

        foreach ([$manager, $cashier] as $person) {
            DeviceToken::query()->withoutGlobalScopes()->create([
                'shop_id' => $this->shop()->id, 'user_id' => $person->id, 'kind' => DeviceToken::FCM,
                'endpoint' => 'fcm-token-'.$person->id.str_repeat('x', 40),
                'endpoint_hash' => DeviceToken::hashFor('fcm-token-'.$person->id.str_repeat('x', 40)),
            ]);
        }

        $alert = app(AlertService::class)->raise(
            $this->shop(), Alert::ORDER_PLACED, 'New order T-1 · Table 4', 'order:test',
            can: 'sales.orders.view',
        );

        $sent = Http::recorded(fn ($request) => str_contains($request->url(), 'fcm.googleapis.com'))->values();
        $this->assertCount(1, $sent, 'the manager, not the cashier');
        $this->assertSame('fcm-token-'.$manager->id.str_repeat('x', 40), $sent[0][0]['message']['token']);
        $this->assertSame('New order T-1 · Table 4', $sent[0][0]['message']['notification']['title']);

        // The same alert raised again is not news: no second buzz.
        app(AlertService::class)->raise($this->shop(), Alert::ORDER_PLACED, 'New order T-1 · Table 4', 'order:test', can: 'sales.orders.view');
        $this->assertCount(1, Http::recorded(fn ($request) => str_contains($request->url(), 'fcm.googleapis.com')));

        $this->assertSame(1, app(AlertPushService::class)->push($alert->fresh()));
    }

    public function test_a_person_who_switched_off_phone_notifications_for_a_kind_is_not_pushed(): void
    {
        $this->firebase();
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.test']),
            'fcm.googleapis.com/*' => Http::response(['name' => 'ok']),
        ]);

        $manager = $this->staff(['sales.orders.view']);
        $manager->forceFill(['notification_preferences' => [
            'types' => [Alert::ORDER_PLACED => ['alert' => true, 'sound' => true, 'push' => false]],
        ]])->save();

        DeviceToken::query()->withoutGlobalScopes()->create([
            'shop_id' => $this->shop()->id, 'user_id' => $manager->id, 'kind' => DeviceToken::FCM,
            'endpoint' => str_repeat('m', 60), 'endpoint_hash' => DeviceToken::hashFor(str_repeat('m', 60)),
        ]);

        $alert = app(AlertService::class)->raise($this->shop(), Alert::ORDER_PLACED, 'New order', 'order:quiet', can: 'sales.orders.view');

        $this->assertSame([], app(AlertPushService::class)->devicesFor($alert)->all());
        $this->assertCount(0, Http::recorded(fn ($request) => str_contains($request->url(), 'fcm.googleapis.com')));
    }

    public function test_the_kitchen_board_says_which_table_a_ticket_is_for(): void
    {
        $this->guestOrders($this->table(), $this->dish());
        Auth::logout();
        CurrentShop::forget();

        $chef = $this->staff(['kitchen.tickets.view', 'kitchen.tickets.advance']);

        $this->actingAs($chef)->get(route('admin.kitchen.index'))
            ->assertOk()
            ->assertSee('data-kds-where="Table 4"', false);
    }

    public function test_a_token_google_says_is_gone_is_deleted(): void
    {
        $this->firebase();
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.test']),
            'fcm.googleapis.com/*' => Http::response(['error' => [
                'status' => 'NOT_FOUND',
                'details' => [['errorCode' => 'UNREGISTERED']],
            ]], 404),
        ]);

        $device = DeviceToken::query()->withoutGlobalScopes()->create([
            'shop_id' => $this->shop()->id, 'user_id' => $this->admin()->id, 'kind' => DeviceToken::FCM,
            'endpoint' => str_repeat('d', 60), 'endpoint_hash' => DeviceToken::hashFor(str_repeat('d', 60)),
        ]);

        $this->assertSame(0, app(FcmService::class)->send(collect([$device]), 'Hi', 'There'));
        $this->assertNull(DeviceToken::query()->withoutGlobalScopes()->find($device->id));
    }

    public function test_the_jwt_google_receives_is_signed_by_the_service_account(): void
    {
        $settings = $this->firebase();

        $jwt = app(FcmService::class)->assertion($settings->service_account);
        [$head, $claims, $signature] = explode('.', $jwt);

        $payload = json_decode(base64_decode(strtr($claims, '-_', '+/')), true);
        $this->assertSame('push@demo-pos.iam.gserviceaccount.com', $payload['iss']);
        $this->assertSame('https://www.googleapis.com/auth/firebase.messaging', $payload['scope']);

        $public = openssl_pkey_get_details(openssl_pkey_get_private($settings->service_account['private_key']))['key'];
        $raw = base64_decode(strtr($signature, '-_', '+/').str_repeat('=', (4 - strlen($signature) % 4) % 4));
        $this->assertSame(1, openssl_verify($head.'.'.$claims, $raw, $public, OPENSSL_ALGO_SHA256));
    }

    /* ---------------------------------------------------- firebase setup */

    public function test_firebase_settings_are_the_platforms_alone(): void
    {
        $owner = $this->staff(['settings.firebase.view', 'settings.firebase.edit']);

        // Even granted by hand, a restaurant's staff are refused.
        $this->actingAs($owner)->get(route('admin.settings.firebase'))->assertForbidden();

        $this->actingAs($this->admin())->get(route('admin.settings.firebase'))
            ->assertOk()
            ->assertSee('Firebase Notifications');
    }

    public function test_saving_settings_keeps_the_key_secret_and_rejects_a_bad_file(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->putJson(route('admin.settings.firebase.update'), [
            'project_id' => 'demo-pos',
            'service_account' => '{"not": "a service account"}',
        ])->assertStatus(422)->assertJsonValidationErrors('service_account');

        $key = $this->privateKey();

        $this->actingAs($admin)->putJson(route('admin.settings.firebase.update'), [
            'is_enabled' => 1,
            'project_id' => 'demo-pos', 'api_key' => 'AIzaX', 'messaging_sender_id' => '123',
            'app_id' => '1:123:web:x', 'vapid_key' => 'BKey',
            'service_account' => json_encode(['client_email' => 'push@demo-pos.iam.gserviceaccount.com', 'private_key' => $key]),
        ])->assertOk()->assertJsonPath('message', 'Saved. Firebase notifications are on.');

        $settings = FirebaseSetting::current();
        $this->assertTrue($settings->isReady());

        // Encrypted at rest, and never rendered back.
        $raw = (string) \Illuminate\Support\Facades\DB::table('firebase_settings')->value('service_account');
        $this->assertStringNotContainsString('PRIVATE KEY', $raw);
        $this->actingAs($admin)->get(route('admin.settings.firebase'))
            ->assertSee('push@demo-pos.iam.gserviceaccount.com')
            ->assertDontSee('PRIVATE KEY');

        // Saving the rest of the form with the box empty keeps the key.
        $this->actingAs($admin)->putJson(route('admin.settings.firebase.update'), [
            'is_enabled' => 1, 'project_id' => 'demo-pos', 'api_key' => 'AIzaY', 'messaging_sender_id' => '123',
            'app_id' => '1:123:web:x', 'vapid_key' => 'BKey',
        ])->assertOk();
        $this->assertTrue(FirebaseSetting::current()->hasServiceAccount());
    }

    public function test_a_device_registers_its_token_once(): void
    {
        $manager = $this->staff(['sales.orders.view']);
        $token = str_repeat('t', 152);

        $this->actingAs($manager)->postJson(route('admin.devices.fcm'), ['token' => $token])->assertOk();
        $this->actingAs($manager)->postJson(route('admin.devices.fcm'), ['token' => $token])->assertOk();

        $this->assertSame(1, DeviceToken::query()->withoutGlobalScopes()->where('kind', DeviceToken::FCM)->count());
        $this->assertSame($manager->id, DeviceToken::query()->withoutGlobalScopes()->value('user_id'));
    }

    public function test_the_header_carries_the_firebase_config_once_it_is_ready(): void
    {
        $this->firebase();

        $this->actingAs($this->admin())->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('id="firebase-config"', false)
            ->assertSee('demo-pos', false)
            ->assertSee('data-push-enable', false)
            // The private half never reaches the page.
            ->assertDontSee('PRIVATE KEY', false);
    }

    /* --------------------------------------------- the guest's live order */

    public function test_the_guest_sees_each_dish_move_without_a_reload(): void
    {
        $order = $this->guestOrders($this->table(), $this->dish());

        $this->get(route('table.orders'))
            ->assertOk()
            ->assertSee('data-live-orders', false)
            ->assertSee('table-live.js', false);

        $line = $order->items()->firstOrFail();
        $this->getJson(route('table.orders.live'))
            ->assertOk()
            ->assertJsonPath('data.orders.0.id', $order->id)
            ->assertJsonPath('data.orders.0.lines.0.id', $line->id)
            ->assertJsonPath('data.orders.0.lines.0.label', 'Placed');

        // The kitchen bumps it.
        $line->forceFill(['kitchen_status' => Order::READY])->save();
        $order->forceFill(['status' => Order::READY])->save();

        $this->getJson(route('table.orders.live'))
            ->assertJsonPath('data.orders.0.status', 'ready')
            ->assertJsonPath('data.orders.0.lines.0.status', 'ready')
            ->assertJsonPath('data.orders.0.lines.0.label', Order::STATUSES[Order::READY]);
    }

    public function test_a_finished_sitting_tells_the_page_to_stop(): void
    {
        $this->getJson(route('table.orders.live'))
            ->assertStatus(410)
            ->assertJsonPath('redirect', route('table.expired'));
    }

    public function test_the_notifier_never_breaks_what_it_reports_on(): void
    {
        // A broken mailer must not turn a paid bill into an error.
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP down'));

        $invoice = Invoice::allShops()->first() ?? new Invoice(['shop_id' => $this->shop()->id]);
        $session = new TableSession(['shop_id' => $this->shop()->id, 'guest_mobile' => '9800000000']);

        app(EventNotifier::class)->billSettled($session, $invoice);

        $this->assertTrue(true);
    }
}
