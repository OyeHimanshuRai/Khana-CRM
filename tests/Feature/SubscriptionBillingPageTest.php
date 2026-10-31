<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Shop;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentShop;
use App\Support\CurrentTenant;
use App\Support\Modules;
use App\Support\PlanAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The customer-facing billing screen: what it shows, who may act, and the
 * receipt. The money arithmetic is covered in SubscriptionBillingTest.
 */
class SubscriptionBillingPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');
        config(['payments.platform.key' => 'rzp_test', 'payments.platform.secret' => 'sek']);

        $this->seed();
        $this->resetState();
        Carbon::setTestNow('2026-10-01 12:00:00');
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

    private function shop(): Shop
    {
        return Shop::query()->withoutGlobalScopes()->orderBy('id')->firstOrFail();
    }

    private function subscription(): Subscription
    {
        $plan = Plan::create([
            'name' => 'Basic', 'code' => 'basic'.uniqid(), 'slug' => 'basic'.uniqid(),
            'monthly_price' => 1000, 'yearly_price' => 10000, 'currency' => 'INR', 'is_active' => true,
        ]);

        return Subscription::create([
            'tenant_id' => $this->shop()->tenant_id,
            'plan_id' => $plan->id,
            'billing_period' => 'monthly',
            'price' => 1000,
            'currency' => 'INR',
            'starts_at' => now()->subDays(5),
            'ends_at' => now()->addDays(25),
            'grace_days' => 3,
        ]);
    }

    private function payment(Subscription $sub, float $amount = 1000, ?int $tenantId = null): SubscriptionPayment
    {
        return SubscriptionPayment::create([
            'subscription_id' => $sub->id,
            'tenant_id' => $tenantId ?? $sub->tenant_id,
            'reference' => 'SUB-T'.uniqid(),
            'amount' => $amount,
            'currency' => 'INR',
            'method' => 'razorpay',
            'provider_reference' => 'pay_ABC123',
            'paid_at' => now(),
            'period_start' => now()->subDays(5),
            'period_end' => now()->addDays(25),
        ]);
    }

    private function user(array $permissions): User
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
        $user->givePermissionTo(array_merge(['dashboard.overview.view', 'settings.subscriptions.view'], $permissions));

        return $user->fresh();
    }

    /* ------------------------------------------------------------- the page */

    public function test_an_owner_sees_plans_with_buy_buttons(): void
    {
        $this->subscription();

        $this->actingAs($this->user(['settings.subscriptions.pay']))
            ->get('/superadmin/subscription')
            ->assertOk()
            ->assertSee('data-billing', false)
            ->assertSee('data-plan-select', false)
            ->assertSee('Renew');
    }

    public function test_someone_without_the_pay_permission_sees_plans_but_no_buttons(): void
    {
        $this->subscription();

        $this->actingAs($this->user([]))
            ->get('/superadmin/subscription')
            ->assertOk()
            ->assertSee('Ask your account owner')
            ->assertDontSee('data-plan-select', false);
    }

    public function test_the_page_says_so_when_online_payment_is_not_set_up(): void
    {
        config(['payments.platform.key' => null, 'payments.platform.secret' => null]);
        $this->subscription();

        $this->actingAs($this->user(['settings.subscriptions.pay']))
            ->get('/superadmin/subscription')
            ->assertOk()
            ->assertSee('Online payment is not set up yet')
            ->assertDontSee('data-plan-select', false);
    }

    public function test_a_receipt_link_appears_for_a_payment(): void
    {
        $payment = $this->payment($this->subscription());

        $this->actingAs($this->user([]))
            ->get('/superadmin/subscription')
            ->assertSee(route('admin.subscription.invoice', $payment), false);
    }

    /* ------------------------------------------------------------- receipts */

    public function test_a_receipt_shows_the_payment(): void
    {
        $payment = $this->payment($this->subscription());

        $this->actingAs($this->user([]))
            ->get(route('admin.subscription.invoice', $payment))
            ->assertOk()
            ->assertSee($payment->reference)
            ->assertSee('1,000.00')
            ->assertSee('pay_ABC123');
    }

    public function test_another_businesss_receipt_is_not_found(): void
    {
        $sub = $this->subscription();
        $other = Tenant::create(['name' => 'Rival Co', 'code' => 'rival-'.uniqid(), 'slug' => 'rival-'.uniqid()]);
        $payment = $this->payment($sub, 1000, $other->id);

        $this->actingAs($this->user([]))
            ->get(route('admin.subscription.invoice', $payment))
            ->assertNotFound();
    }

    public function test_a_refund_has_no_receipt(): void
    {
        $payment = $this->payment($this->subscription(), -500);

        $this->actingAs($this->user([]))
            ->get(route('admin.subscription.invoice', $payment))
            ->assertNotFound();
    }

    /* ------------------------------------------------------ cancel / resume */

    public function test_an_owner_can_cancel_at_term_end_and_change_their_mind(): void
    {
        $sub = $this->subscription();
        $owner = $this->user(['settings.subscriptions.pay']);

        $this->actingAs($owner)->putJson('/superadmin/subscription/cancel')->assertOk();
        $this->assertTrue($sub->fresh()->cancelled_at->equalTo($sub->ends_at));
        $this->assertTrue($sub->fresh()->isUsable());

        PlanAccess::forget();
        $this->actingAs($owner)->putJson('/superadmin/subscription/cancel')->assertStatus(422);

        PlanAccess::forget();
        $this->actingAs($owner)->putJson('/superadmin/subscription/resume')->assertOk();
        $this->assertNull($sub->fresh()->cancelled_at);
    }

    public function test_cancelling_needs_the_pay_permission(): void
    {
        $sub = $this->subscription();

        $this->actingAs($this->user([]))->putJson('/superadmin/subscription/cancel')->assertForbidden();

        $this->assertNull($sub->fresh()->cancelled_at);
    }
}
