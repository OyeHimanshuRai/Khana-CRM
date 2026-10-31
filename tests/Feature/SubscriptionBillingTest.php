<?php

namespace Tests\Feature;

use App\Models\BillingOrder;
use App\Models\Plan;
use App\Models\Shop;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Services\SubscriptionBillingService;
use App\Support\CurrentShop;
use App\Support\CurrentTenant;
use App\Support\Modules;
use App\Support\PlanAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Tests\TestCase;

/**
 * A restaurant paying the platform online: pricing, credit, and the two ways
 * a payment is confirmed. Every case here is about money, so each asserts the
 * term moved exactly as far as one payment justifies - never twice, never on
 * an unsigned claim.
 */
class SubscriptionBillingTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'platform-secret';

    private const WEBHOOK_SECRET = 'platform-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');

        config([
            'payments.platform.key' => 'rzp_test_platform',
            'payments.platform.secret' => self::SECRET,
            'payments.platform.webhook_secret' => self::WEBHOOK_SECRET,
            'payments.gateways.razorpay.webhook_secret' => 'restaurant-webhook-secret',
            // The arithmetic of credit and capture, before tax; GST has its own test (SubscriptionGstTest).
            'payments.invoice.gst_rate' => 0,
        ]);

        $this->seed();
        $this->resetState();

        Carbon::setTestNow('2026-10-01 12:00:00');
        Http::fake(['api.razorpay.com/v1/orders' => Http::response(['id' => 'order_PLAT1'])]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->resetState();

        parent::tearDown();
    }

    private function resetState(): void
    {
        CurrentShop::forget();
        CurrentTenant::forget();
        PlanAccess::forget();
        Modules::forget();
    }

    /* ---------------------------------------------------------- fixtures */

    private function shop(): Shop
    {
        return Shop::query()->withoutGlobalScopes()->orderBy('id')->firstOrFail();
    }

    private function plan(string $name, float $monthly, ?float $yearly = null): Plan
    {
        return Plan::create([
            'name' => $name, 'code' => $name.uniqid(), 'slug' => $name.uniqid(),
            'monthly_price' => $monthly, 'yearly_price' => $yearly, 'currency' => 'INR',
            'is_active' => true,
        ]);
    }

    /** A paid, running blanket subscription that was bought `$daysAgo` days ago for 30 days. */
    private function running(Plan $plan, float $paid, int $daysAgo = 0): Subscription
    {
        $start = now()->subDays($daysAgo);

        $subscription = Subscription::create([
            'tenant_id' => $this->shop()->tenant_id,
            'shop_id' => null,
            'plan_id' => $plan->id,
            'billing_period' => 'monthly',
            'price' => $paid,
            'currency' => 'INR',
            'starts_at' => $start,
            'ends_at' => $start->copy()->addDays(30),
            'grace_days' => 3,
        ]);

        SubscriptionPayment::create([
            'subscription_id' => $subscription->id,
            'tenant_id' => $subscription->tenant_id,
            'reference' => 'SUB-T'.uniqid(),
            'amount' => $paid,
            'currency' => 'INR',
            'method' => 'manual',
            'paid_at' => $start,
            'period_start' => $start,
            'period_end' => $start->copy()->addDays(30),
        ]);

        return $subscription->fresh();
    }

    private function service(): SubscriptionBillingService
    {
        return app(SubscriptionBillingService::class);
    }

    private function sign(string $orderId, string $paymentId, string $secret = self::SECRET): string
    {
        return hash_hmac('sha256', $orderId.'|'.$paymentId, $secret);
    }

    /** @return array{0: string, 1: array<string, string>} */
    private function webhook(string $orderId, string $paymentId, int $paise, string $secret = self::WEBHOOK_SECRET): array
    {
        $body = json_encode([
            'event' => 'payment.captured',
            'payload' => ['payment' => ['entity' => [
                'id' => $paymentId, 'order_id' => $orderId, 'status' => 'captured', 'amount' => $paise,
            ]]],
        ]);

        return [$body, ['x-razorpay-signature' => [hash_hmac('sha256', $body, $secret)]]];
    }

    private function staff(array $permissions): User
    {
        $shop = $this->shop();

        $user = User::create([
            'tenant_id' => $shop->tenant_id,
            'name' => 'Owner',
            'email' => 'owner'.uniqid().'@example.test',
            'password' => 'owner-password-1',
            'is_admin' => true,
        ]);

        $user->shops()->syncWithoutDetaching([$shop->id => ['is_default' => true]]);
        $user->forceFill(['current_shop_id' => $shop->id, 'all_shops_view' => false])->save();
        $user->givePermissionTo($permissions);

        return $user->fresh();
    }

    /* -------------------------------------------------------------- quotes */

    public function test_renewing_the_same_plan_costs_its_list_price_and_extends_the_term(): void
    {
        $plan = $this->plan('Basic', 1000);
        $sub = $this->running($plan, 1000, 10);

        $quote = $this->service()->quote($sub, $plan, 'monthly');

        $this->assertTrue($quote['renewal']);
        $this->assertSame(1000.0, $quote['amount']);
        $this->assertSame(0.0, $quote['credit']);
        $this->assertTrue($quote['new_ends_at']->equalTo($sub->ends_at->copy()->addMonth()));
    }

    public function test_upgrading_credits_the_unused_part_of_what_was_paid(): void
    {
        $sub = $this->running($this->plan('Basic', 1000), 1000, 15);
        $pro = $this->plan('Pro', 3000);

        $quote = $this->service()->quote($sub, $pro, 'monthly');

        $this->assertFalse($quote['renewal']);
        $this->assertSame(500.0, $quote['credit']);
        $this->assertSame(2500.0, $quote['amount']);
    }

    public function test_a_trial_has_nothing_to_credit(): void
    {
        $plan = $this->plan('Basic', 1000);
        $sub = $this->running($plan, 1000, 5);
        $sub->forceFill(['trial_ends_at' => now()->addDays(3)])->save();

        $quote = $this->service()->quote($sub->fresh(), $this->plan('Pro', 3000), 'monthly');

        $this->assertSame(0.0, $quote['credit']);
        $this->assertSame(3000.0, $quote['amount']);
    }

    public function test_a_plan_no_longer_on_sale_cannot_be_bought(): void
    {
        $sub = $this->running($this->plan('Basic', 1000), 1000);
        $retired = $this->plan('Old', 500);
        $retired->forceFill(['is_active' => false])->save();

        $this->expectException(RuntimeException::class);

        $this->service()->quote($sub, $retired, 'monthly');
    }

    /* ------------------------------------------------------------ checkout */

    public function test_checkout_opens_a_provider_order_for_the_plans_price(): void
    {
        $plan = $this->plan('Basic', 1000);
        $sub = $this->running($plan, 1000, 5);

        $order = $this->service()->checkout($sub, $plan, 'monthly', null);

        $this->assertSame('order_PLAT1', $order->provider_order_id);
        $this->assertSame('1000.00', $order->amount);
        $this->assertSame(BillingOrder::PENDING, $order->status);
        $this->assertSame(100000, $order->payload['amount']);
        $this->assertSame('rzp_test_platform', $order->payload['key']);

        // Asking again hands back the same order rather than opening another.
        $this->assertTrue($order->is($this->service()->checkout($sub, $plan, 'monthly', null)));
        $this->assertSame(1, BillingOrder::query()->count());
    }

    public function test_checkout_refuses_politely_when_online_billing_is_not_configured(): void
    {
        config(['payments.platform.key' => null, 'payments.platform.secret' => null]);
        app()->forgetInstance(\App\Services\Payments\GatewayManager::class);

        $plan = $this->plan('Basic', 1000);
        $sub = $this->running($plan, 1000);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not set up');

        $this->service()->checkout($sub, $plan, 'monthly', null);
    }

    public function test_a_downgrade_the_credit_covers_is_applied_without_any_payment(): void
    {
        $sub = $this->running($this->plan('Pro', 3000), 3000, 1);
        $basic = $this->plan('Basic', 1000);

        $order = $this->service()->checkout($sub, $basic, 'monthly', null);

        $this->assertSame(BillingOrder::PAID, $order->status);
        $this->assertSame('0.00', $order->amount);
        Http::assertNothingSent();

        PlanAccess::forget();
        $current = Subscription::query()->where('tenant_id', $sub->tenant_id)->latest('id')->first();
        $this->assertSame($basic->id, $current->plan_id);
        $this->assertTrue($current->ends_at->isFuture());
        $this->assertNotNull($sub->fresh()->cancelled_at);
    }

    /* ------------------------------------------------- confirming (browser) */

    public function test_a_signed_browser_confirmation_extends_the_term_once(): void
    {
        $plan = $this->plan('Basic', 1000);
        $sub = $this->running($plan, 1000, 25);
        $order = $this->service()->checkout($sub, $plan, 'monthly', null);
        $endsBefore = $sub->ends_at->copy();

        $payload = [
            'razorpay_order_id' => 'order_PLAT1',
            'razorpay_payment_id' => 'pay_A1',
            'razorpay_signature' => $this->sign('order_PLAT1', 'pay_A1'),
        ];

        $this->service()->confirmFromBrowser($order, $payload);
        $this->service()->confirmFromBrowser($order->fresh(), $payload);

        $this->assertSame(BillingOrder::PAID, $order->fresh()->status);
        $this->assertTrue($sub->fresh()->ends_at->equalTo($endsBefore->copy()->addMonth()));
        $this->assertSame(2, SubscriptionPayment::query()->where('subscription_id', $sub->id)->count());
        $this->assertSame('razorpay', SubscriptionPayment::query()->latest('id')->first()->method);
    }

    public function test_a_forged_browser_signature_changes_nothing(): void
    {
        $plan = $this->plan('Basic', 1000);
        $sub = $this->running($plan, 1000, 25);
        $order = $this->service()->checkout($sub, $plan, 'monthly', null);
        $endsBefore = $sub->ends_at->copy();

        try {
            $this->service()->confirmFromBrowser($order, [
                'razorpay_order_id' => 'order_PLAT1',
                'razorpay_payment_id' => 'pay_A1',
                'razorpay_signature' => $this->sign('order_PLAT1', 'pay_A1', 'guessed-secret'),
            ]);
            $this->fail('A forged signature was accepted.');
        } catch (RuntimeException) {
        }

        $this->assertSame(BillingOrder::FAILED, $order->fresh()->status);
        $this->assertTrue($sub->fresh()->ends_at->equalTo($endsBefore));
    }

    public function test_a_valid_signature_for_a_different_order_is_refused(): void
    {
        $plan = $this->plan('Basic', 1000);
        $sub = $this->running($plan, 1000, 25);
        $order = $this->service()->checkout($sub, $plan, 'monthly', null);

        $this->expectException(RuntimeException::class);

        // Genuinely signed by the platform secret, but for someone else's order.
        $this->service()->confirmFromBrowser($order, [
            'razorpay_order_id' => 'order_SOMEONE_ELSE',
            'razorpay_payment_id' => 'pay_B1',
            'razorpay_signature' => $this->sign('order_SOMEONE_ELSE', 'pay_B1'),
        ]);
    }

    /* --------------------------------------------------------- the webhook */

    public function test_a_signed_webhook_captures_and_a_replay_does_nothing(): void
    {
        $plan = $this->plan('Basic', 1000);
        $sub = $this->running($plan, 1000, 25);
        $this->service()->checkout($sub, $plan, 'monthly', null);
        $endsBefore = $sub->ends_at->copy();

        [$body, $headers] = $this->webhook('order_PLAT1', 'pay_W1', 100000);

        $this->assertNotNull($this->service()->confirmFromWebhook($body, $headers));
        $this->service()->confirmFromWebhook($body, $headers);

        $this->assertTrue($sub->fresh()->ends_at->equalTo($endsBefore->copy()->addMonth()));
        $this->assertSame(2, SubscriptionPayment::query()->where('subscription_id', $sub->id)->count());
    }

    public function test_a_webhook_signed_with_the_restaurants_secret_is_ignored(): void
    {
        $plan = $this->plan('Basic', 1000);
        $sub = $this->running($plan, 1000, 25);
        $this->service()->checkout($sub, $plan, 'monthly', null);

        [$body, $headers] = $this->webhook('order_PLAT1', 'pay_W2', 100000, 'restaurant-webhook-secret');

        $this->assertNull($this->service()->confirmFromWebhook($body, $headers));
        $this->assertSame(BillingOrder::PENDING, BillingOrder::query()->first()->status);
    }

    public function test_a_webhook_for_the_wrong_amount_is_ignored(): void
    {
        $plan = $this->plan('Basic', 1000);
        $sub = $this->running($plan, 1000, 25);
        $this->service()->checkout($sub, $plan, 'monthly', null);

        [$body, $headers] = $this->webhook('order_PLAT1', 'pay_W3', 200);

        $this->assertNull($this->service()->confirmFromWebhook($body, $headers));
        $this->assertSame(BillingOrder::PENDING, BillingOrder::query()->first()->status);
    }

    public function test_a_webhook_still_lands_after_a_failed_browser_check(): void
    {
        $plan = $this->plan('Basic', 1000);
        $sub = $this->running($plan, 1000, 25);
        $order = $this->service()->checkout($sub, $plan, 'monthly', null);

        try {
            $this->service()->confirmFromBrowser($order, ['razorpay_order_id' => 'x', 'razorpay_payment_id' => 'y', 'razorpay_signature' => 'z']);
        } catch (RuntimeException) {
        }

        [$body, $headers] = $this->webhook('order_PLAT1', 'pay_W4', 100000);

        $this->assertNotNull($this->service()->confirmFromWebhook($body, $headers));
        $this->assertSame(BillingOrder::PAID, $order->fresh()->status);
    }

    public function test_the_webhook_endpoint_always_answers_200_and_needs_no_csrf_token(): void
    {
        $this->post('/billing/webhook', ['junk' => 1], ['X-Razorpay-Signature' => 'nope'])->assertOk();
    }

    public function test_the_endpoint_applies_a_signed_event(): void
    {
        $plan = $this->plan('Basic', 1000);
        $sub = $this->running($plan, 1000, 25);
        $this->service()->checkout($sub, $plan, 'monthly', null);

        [$body, $headers] = $this->webhook('order_PLAT1', 'pay_W5', 100000);

        $this->call('POST', '/billing/webhook', [], [], [], [
            'HTTP_X-Razorpay-Signature' => $headers['x-razorpay-signature'][0],
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertOk();

        $this->assertSame(BillingOrder::PAID, BillingOrder::query()->first()->status);
    }

    /* ------------------------------------------------------- plan changes */

    public function test_a_paid_upgrade_opens_a_new_subscription_from_today(): void
    {
        $sub = $this->running($this->plan('Basic', 1000), 1000, 15);
        $pro = $this->plan('Pro', 3000);
        $order = $this->service()->checkout($sub, $pro, 'monthly', null);

        $this->assertSame('2500.00', $order->amount);

        [$body, $headers] = $this->webhook('order_PLAT1', 'pay_U1', 250000);
        $this->service()->confirmFromWebhook($body, $headers);

        PlanAccess::forget();
        $current = Subscription::query()->where('tenant_id', $sub->tenant_id)->latest('id')->first();

        $this->assertSame($pro->id, $current->plan_id);
        $this->assertTrue($current->ends_at->equalTo(now()->addMonth()));
        $this->assertNotNull($sub->fresh()->cancelled_at);
        $this->assertSame($pro->id, PlanAccess::subscriptionFor($sub->tenant_id)->plan_id);
    }

    /* ---------------------------------------------------------- permissions */

    public function test_paying_needs_its_own_permission(): void
    {
        $plan = $this->plan('Basic', 1000);
        $this->running($plan, 1000, 5);

        $viewer = $this->staff(['dashboard.overview.view', 'settings.subscriptions.view']);

        $this->actingAs($viewer)
            ->postJson('/superadmin/subscription/checkout', ['plan_id' => $plan->id, 'period' => 'monthly'])
            ->assertForbidden();
    }

    public function test_an_owner_can_quote_and_check_out_but_the_amount_is_not_theirs_to_set(): void
    {
        $plan = $this->plan('Basic', 1000);
        $this->running($plan, 1000, 5);
        $owner = $this->staff(['dashboard.overview.view', 'settings.subscriptions.view', 'settings.subscriptions.pay']);

        $this->actingAs($owner)
            ->getJson('/superadmin/subscription/quote?plan_id='.$plan->id.'&period=monthly')
            ->assertOk()
            ->assertJsonPath('data.amount', 1000);

        $this->actingAs($owner)
            ->postJson('/superadmin/subscription/checkout', ['plan_id' => $plan->id, 'period' => 'monthly', 'amount' => 1])
            ->assertOk()
            ->assertJsonPath('data.amount', '1000.00');
    }

    public function test_one_business_cannot_confirm_anothers_order(): void
    {
        $plan = $this->plan('Basic', 1000);
        $sub = $this->running($plan, 1000, 5);
        $order = $this->service()->checkout($sub, $plan, 'monthly', null);
        $other = \App\Models\Tenant::create(['name' => 'Another Restaurant Co', 'code' => 'other-'.uniqid(), 'slug' => 'other-'.uniqid()]);
        $order->forceFill(['tenant_id' => $other->id])->saveQuietly();

        $owner = $this->staff(['dashboard.overview.view', 'settings.subscriptions.pay']);

        $this->actingAs($owner)->postJson('/superadmin/subscription/verify', [
            'reference' => $order->reference,
            'razorpay_order_id' => 'order_PLAT1',
            'razorpay_payment_id' => 'pay_X',
            'razorpay_signature' => $this->sign('order_PLAT1', 'pay_X'),
        ])->assertNotFound();
    }
}
