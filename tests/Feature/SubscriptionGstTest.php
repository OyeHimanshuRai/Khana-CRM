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
use Tests\TestCase;

/**
 * "GST extra", as the pricing page says: 18% on top of the plan's price.
 *
 * GST is charged on what is actually being sold - the price less any credit
 * for unused time - so a credit never earns tax back, and a credit is
 * worked out from what was paid before tax, so tax is never credited twice.
 * The receipt shows the taxable value and the tax separately.
 */
class SubscriptionGstTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');
        config([
            'payments.platform.key' => 'rzp_test_platform',
            'payments.platform.secret' => 'platform-secret',
            'payments.invoice.gst_rate' => 18,
        ]);
        $this->seed();
        CurrentShop::forget();
        CurrentTenant::forget();
        PlanAccess::forget();
        Modules::forget();

        Carbon::setTestNow('2026-10-01 12:00:00');
        Http::fake(['api.razorpay.com/v1/orders' => Http::response(['id' => 'order_GST1'])]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function shop(): Shop
    {
        return Shop::query()->withoutGlobalScopes()->orderBy('id')->firstOrFail();
    }

    private function plan(float $monthly): Plan
    {
        return Plan::create([
            'name' => 'Plan '.$monthly, 'code' => 'p'.uniqid(), 'slug' => 'p'.uniqid(),
            'monthly_price' => $monthly, 'currency' => 'INR', 'is_active' => true,
        ]);
    }

    private function running(Plan $plan, float $paid, float $tax, int $daysAgo): Subscription
    {
        $start = now()->subDays($daysAgo);
        $subscription = Subscription::create([
            'tenant_id' => $this->shop()->tenant_id, 'shop_id' => null, 'plan_id' => $plan->id,
            'billing_period' => 'monthly', 'price' => $plan->monthly_price, 'currency' => 'INR',
            'starts_at' => $start, 'ends_at' => $start->copy()->addDays(30), 'grace_days' => 3,
        ]);
        SubscriptionPayment::create([
            'subscription_id' => $subscription->id, 'tenant_id' => $subscription->tenant_id,
            'reference' => 'SUB-T'.uniqid(), 'amount' => $paid, 'tax_amount' => $tax, 'currency' => 'INR',
            'method' => 'razorpay', 'paid_at' => $start, 'period_start' => $start, 'period_end' => $start->copy()->addDays(30),
        ]);

        return $subscription->fresh();
    }

    public function test_a_renewal_adds_eighteen_percent_on_top(): void
    {
        $plan = $this->plan(1999);
        $sub = $this->running($plan, 2358.82, 359.82, 25);

        $quote = app(SubscriptionBillingService::class)->quote($sub, $plan, 'monthly');

        $this->assertSame(1999.0, $quote['taxable']);
        $this->assertSame(18.0, $quote['gst_rate']);
        $this->assertSame(359.82, $quote['gst']);
        $this->assertSame(2358.82, $quote['amount']);
    }

    public function test_gst_is_charged_on_the_price_after_credit_and_credit_excludes_old_tax(): void
    {
        $old = $this->plan(1000);
        // Bought 15 of 30 days ago: half the term is left. Paid 1180 including 180 tax.
        $sub = $this->running($old, 1180, 180, 15);
        $new = $this->plan(2000);

        $quote = app(SubscriptionBillingService::class)->quote($sub, $new, 'monthly');

        $this->assertSame(500.0, $quote['credit'], 'half of the 1000 paid before tax');
        $this->assertSame(1500.0, $quote['taxable']);
        $this->assertSame(270.0, $quote['gst']);
        $this->assertSame(1770.0, $quote['amount']);
    }

    public function test_the_order_sent_for_payment_carries_the_tax_and_the_payment_records_it(): void
    {
        $plan = $this->plan(1999);
        $sub = $this->running($plan, 2358.82, 359.82, 25);

        $order = app(SubscriptionBillingService::class)->checkout($sub, $plan, 'monthly');

        $this->assertSame('2358.82', (string) $order->amount);
        $this->assertSame('1999.00', (string) $order->taxable_amount);
        $this->assertSame('359.82', (string) $order->gst_amount);

        $signature = hash_hmac('sha256', 'order_GST1|pay_GST1', 'platform-secret');
        app(SubscriptionBillingService::class)->confirmFromBrowser($order, [
            'razorpay_order_id' => 'order_GST1', 'razorpay_payment_id' => 'pay_GST1', 'razorpay_signature' => $signature,
        ]);

        $payment = SubscriptionPayment::query()->where('provider_reference', 'pay_GST1')->firstOrFail();
        $this->assertSame('2358.82', (string) $payment->amount);
        $this->assertSame('359.82', (string) $payment->tax_amount);
    }

    public function test_the_receipt_itemises_the_tax(): void
    {
        $plan = $this->plan(1999);
        $sub = $this->running($plan, 2358.82, 359.82, 1);
        $payment = SubscriptionPayment::query()->where('subscription_id', $sub->id)->firstOrFail();

        $owner = User::create([
            'tenant_id' => $sub->tenant_id, 'name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'Owner-pass-1',
            'is_admin' => true, 'is_active' => true, 'current_shop_id' => $this->shop()->id,
        ]);
        $owner->shops()->attach($this->shop()->id, ['is_default' => true]);
        $owner->assignRole('Tenant Owner');

        $this->actingAs($owner)->get(route('admin.subscription.invoice', $payment))
            ->assertOk()
            ->assertSee('Taxable value')
            ->assertSee('1,999.00')
            ->assertSee('GST @ 18%')
            ->assertSee('359.82')
            ->assertSee('2,358.82');
    }

    public function test_the_pricing_page_states_the_outlets_and_the_tax(): void
    {
        $this->get('/pricing')
            ->assertOk()
            ->assertSee('Up to 3 outlets')
            ->assertSee('18% GST')
            ->assertDontSee('Per outlet,');
    }
}
