<?php

namespace Tests\Feature;

use App\Models\BillingOrder;
use App\Models\Plan;
use App\Models\Shop;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SubscriptionBillingService;
use App\Services\SubscriptionService;
use App\Services\TenantProvisioningService;
use App\Support\CurrentShop;
use App\Support\CurrentTenant;
use App\Support\Modules;
use App\Support\PlanAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The plan and admin rules, tested against what the code promises in its own
 * comments: an outlet's lapse is that outlet's problem, a free plan cannot be
 * left to lapse, a custom plan is for its client alone.
 */
class PlanAdminAuditTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private Tenant $tenant;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');
        $this->seed();
        $this->resetState();

        $this->shop = Shop::query()->withoutGlobalScopes()->orderBy('id')->firstOrFail();
        $this->tenant = Tenant::query()->findOrFail($this->shop->tenant_id);
        $this->superAdmin = User::query()->where('email', 'admin@erp.test')->firstOrFail();
    }

    protected function tearDown(): void
    {
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

    private function plan(string $code = 'starter'): Plan
    {
        return Plan::query()->where('code', $code)->firstOrFail();
    }

    /** @param array<string, mixed> $attributes */
    private function freePlan(array $attributes = []): Plan
    {
        return Plan::create(array_merge([
            'name' => 'Free', 'code' => 'free', 'slug' => 'free', 'monthly_price' => 0,
            'currency' => 'INR', 'modules' => ['pos'], 'is_active' => true,
        ], $attributes));
    }

    /** @param array<string, mixed> $attributes */
    private function subscription(array $attributes = []): Subscription
    {
        return Subscription::create(array_merge([
            'tenant_id' => $this->tenant->id, 'plan_id' => $this->plan()->id,
            'billing_period' => 'monthly', 'price' => 799, 'currency' => 'INR',
            'starts_at' => now()->subMonth(), 'ends_at' => now()->addMonth(), 'grace_days' => 0,
        ], $attributes));
    }

    private function secondShop(): Shop
    {
        return Shop::query()->withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'name' => 'Second', 'code' => 'S2', 'slug' => 'second-branch',
        ]);
    }

    /* --------------------------------------- one outlet's lapse stays there */

    public function test_the_sweep_does_not_shut_a_business_over_one_outlets_lapse(): void
    {
        $second = $this->secondShop();

        // The healthy outlet's row is older, so the lapsed one is "newest".
        $this->subscription(['shop_id' => $this->shop->id]);
        $this->subscription(['shop_id' => $second->id, 'ends_at' => now()->subDays(2)]);

        app(SubscriptionService::class)->sweep();

        $this->assertFalse($this->tenant->fresh()->isSuspended(), 'one outlet lapsing must not suspend the company');
    }

    public function test_cancelling_one_outlet_does_not_suspend_the_company(): void
    {
        $second = $this->secondShop();

        $this->subscription(['shop_id' => $this->shop->id]);
        $lapsing = $this->subscription(['shop_id' => $second->id]);

        app(SubscriptionService::class)->cancel($lapsing, null, true);

        $this->assertFalse($this->tenant->fresh()->isSuspended());
    }

    public function test_every_outlet_is_warned_before_its_term_ends(): void
    {
        $second = $this->secondShop();

        $soon = $this->subscription(['shop_id' => $this->shop->id, 'ends_at' => now()->addDays(2)]);
        $this->subscription(['shop_id' => $second->id, 'ends_at' => now()->addMonths(6)]);

        $this->assertTrue(
            app(SubscriptionService::class)->expiringWithin(5)->contains('id', $soon->id),
            'the outlet running out must not be hidden behind a newer row for its neighbour',
        );
    }

    /* ------------------------------------------------------- the plans list */

    public function test_the_subscriber_count_counts_outlets_on_different_plans(): void
    {
        $second = $this->secondShop();

        $this->subscription(['shop_id' => $this->shop->id, 'plan_id' => $this->plan('starter')->id]);
        $this->subscription(['shop_id' => $second->id, 'plan_id' => $this->plan('restaurant')->id]);

        $this->resetState();
        $this->actingAs($this->superAdmin)
            ->get(route('admin.plans.show', $this->plan('starter')))
            ->assertOk()
            ->assertViewHas('live', 1);
    }

    public function test_a_lapsed_business_is_not_a_live_subscriber(): void
    {
        $this->subscription(['ends_at' => now()->subDays(30)]);

        $this->resetState();
        $this->actingAs($this->superAdmin)
            ->get(route('admin.plans.show', $this->plan('starter')))
            ->assertOk()
            ->assertViewHas('live', 0);
    }

    /* -------------------------------------------------------- a free plan */

    public function test_signing_up_on_a_free_plan_does_not_start_expired(): void
    {
        $plan = $this->freePlan();

        $owner = app(TenantProvisioningService::class)->register([
            'business_name' => 'Free Cafe', 'name' => 'Free Owner', 'email' => 'free@example.test',
            'mobile' => '9999999998', 'password' => 'Secret123',
        ], $plan, 'monthly');

        $this->resetState();
        $subscription = Subscription::query()->where('tenant_id', $owner->tenant_id)->firstOrFail();

        $this->assertTrue($subscription->isUsable(), 'a free plan has no payment to wait for');
        $this->assertNull($subscription->ends_at);
    }

    public function test_moving_to_a_free_plan_online_cannot_lapse_into_a_dead_end(): void
    {
        $free = $this->freePlan();
        $current = $this->subscription();

        SubscriptionPayment::unguarded(fn () => $current->payments()->create([
            'tenant_id' => $this->tenant->id, 'reference' => 'SUB-T', 'amount' => 799, 'currency' => 'INR',
            'method' => 'manual', 'paid_at' => now()->subDays(5),
            'period_start' => now()->subDays(5), 'period_end' => now()->addDays(25),
        ]));

        app(SubscriptionBillingService::class)->checkout($current, $free, 'monthly');

        $moved = Subscription::query()->where('plan_id', $free->id)->firstOrFail();

        // No renewal is possible on a free plan, so it must never run out.
        $this->assertNull($moved->ends_at);
    }

    /* ------------------------------------------------ what the owner sees */

    public function test_the_staff_meter_counts_seats_the_way_the_limit_does(): void
    {
        $plan = $this->plan();
        $plan->forceFill(['max_users' => 2])->save();
        $this->subscription();

        $before = User::query()->withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->where('is_active', true)->count();

        User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Left Last Year', 'email' => 'left@example.test',
            'password' => 'left-password-1', 'is_admin' => true, 'is_active' => false,
        ]);

        $this->resetState();
        $this->actingAs($this->superAdmin);

        $usage = (fn () => $this->usageFor(Tenant::query()->findOrFail(func_get_arg(0))))
            ->call(app(\App\Http\Controllers\Admin\SubscriptionController::class), $this->tenant->id);

        $this->assertSame($before, $usage[1]['used'], 'somebody who has left is not sitting in a seat');
    }

    public function test_a_payment_for_one_outlet_does_not_lift_the_companys_suspension(): void
    {
        $this->subscription(['ends_at' => now()->subDays(30)]);
        $outlet = $this->subscription(['shop_id' => $this->shop->id]);

        $this->tenant->suspend('Subscription expired on 1 Jan 2026');

        app(SubscriptionService::class)->recordPayment($outlet, 799);

        $this->assertTrue($this->tenant->fresh()->isSuspended());
    }

    public function test_a_lapsed_company_is_still_swept_even_with_an_outlet_row(): void
    {
        $this->subscription(['shop_id' => $this->shop->id]);
        $this->subscription(['ends_at' => now()->subDays(30)]);

        app(SubscriptionService::class)->sweep();

        $this->assertTrue($this->tenant->fresh()->isSuspended());
    }

    public function test_a_paid_plan_with_no_trial_still_starts_waiting_for_payment(): void
    {
        $plan = $this->plan();
        $plan->forceFill(['trial_days' => 0])->save();

        $owner = app(TenantProvisioningService::class)->register([
            'business_name' => 'Paid Cafe', 'name' => 'Paid Owner', 'email' => 'paid@example.test',
            'mobile' => '9999999997', 'password' => 'Secret123',
        ], $plan->fresh(), 'monthly');

        $this->resetState();
        $subscription = Subscription::query()->where('tenant_id', $owner->tenant_id)->firstOrFail();

        $this->assertNotNull($subscription->ends_at, 'choosing a paid plan must not be free forever');
        $this->assertFalse($subscription->ends_at->isFuture());
        $this->assertSame(0, $subscription->grace_days);
    }

    public function test_a_credit_covered_move_to_a_paid_plan_keeps_its_term(): void
    {
        $cheap = $this->plan();
        $cheap->forceFill(['monthly_price' => 1])->save();

        $current = $this->subscription();

        SubscriptionPayment::unguarded(fn () => $current->payments()->create([
            'tenant_id' => $this->tenant->id, 'reference' => 'SUB-C', 'amount' => 799, 'currency' => 'INR',
            'method' => 'manual', 'paid_at' => now(), 'period_start' => now(), 'period_end' => now()->addMonth(),
        ]));

        $other = $this->plan('restaurant');
        $other->forceFill(['monthly_price' => 1])->save();

        app(SubscriptionBillingService::class)->checkout($current, $other->fresh(), 'monthly');

        $moved = Subscription::query()->where('plan_id', $other->id)->firstOrFail();

        $this->assertNotNull($moved->ends_at);
        $this->assertTrue($moved->ends_at->isFuture());
    }

    public function test_a_stale_renewal_of_the_same_plan_extends_the_current_row(): void
    {
        $old = $this->subscription(['ends_at' => now()->addDays(20)]);

        $stale = BillingOrder::create([
            'tenant_id' => $this->tenant->id, 'subscription_id' => $old->id, 'plan_id' => $old->plan_id,
            'billing_period' => 'monthly', 'list_price' => 799, 'credit' => 0, 'amount' => 799, 'currency' => 'INR',
            'reference' => 'BILL-SAME', 'provider' => 'razorpay', 'status' => BillingOrder::PENDING,
        ]);

        // A newer row on the same plan: the renewal belongs on it, not on a new one.
        $newer = $this->subscription(['ends_at' => now()->addDays(20)]);

        app(SubscriptionBillingService::class)->capture($stale, 'pay_same', 'webhook');

        $this->assertSame(2, Subscription::count());
        $this->assertGreaterThan(now()->addDays(45), $newer->fresh()->ends_at);
    }

    public function test_a_stale_checkout_paid_after_a_plan_change_does_not_vanish(): void
    {
        $current = $this->subscription(['ends_at' => now()->addDays(20)]);

        // Two tabs: a renewal opened, then a plan change completed first.
        $stale = BillingOrder::create([
            'tenant_id' => $this->tenant->id, 'subscription_id' => $current->id, 'plan_id' => $current->plan_id,
            'billing_period' => 'monthly', 'list_price' => 799, 'credit' => 0, 'amount' => 799, 'currency' => 'INR',
            'reference' => 'BILL-STALE', 'provider' => 'razorpay', 'status' => BillingOrder::PENDING,
        ]);

        $newer = app(SubscriptionService::class)->subscribe($this->tenant, $this->plan('restaurant'));
        $newer->forceFill(['ends_at' => now()->addDays(20)])->save();

        app(SubscriptionBillingService::class)->capture($stale, 'pay_stale', 'webhook');

        $this->resetState();
        $this->assertGreaterThan(
            now()->addDays(25),
            PlanAccess::subscriptionFor($this->tenant->id)->ends_at,
            'money taken must extend the subscription the business is actually on',
        );
    }

    /* ---------------------------------------------------- the custom plans */

    public function test_nobody_else_can_buy_or_ask_for_a_clients_custom_plan(): void
    {
        $other = Tenant::create(['name' => 'Rival', 'code' => 'RIVAL2', 'slug' => 'rival-two']);
        $custom = Plan::create([
            'tenant_id' => $other->id, 'name' => 'Rival Deal', 'code' => 'rival-deal', 'slug' => 'rival-deal',
            'monthly_price' => 1, 'currency' => 'INR', 'modules' => ['pos'], 'is_active' => true,
        ]);

        $current = $this->subscription();

        $this->expectException(\RuntimeException::class);
        app(SubscriptionBillingService::class)->quote($current, $custom, 'monthly');
    }

    public function test_the_price_list_never_shows_a_custom_plan(): void
    {
        $other = Tenant::create(['name' => 'Rival', 'code' => 'RIVAL3', 'slug' => 'rival-three']);
        Plan::create([
            'tenant_id' => $other->id, 'name' => 'Secret Deal', 'code' => 'secret-deal', 'slug' => 'secret-deal',
            'monthly_price' => 1, 'currency' => 'INR', 'modules' => ['pos'], 'is_active' => true,
        ]);

        $this->get('/pricing')->assertOk()->assertDontSee('Secret Deal');
        $this->get('/register')->assertOk()->assertDontSee('Secret Deal');
    }

    /* -------------------------------------------------------- the matrix */

    public function test_unticking_everything_on_a_plan_really_empties_it(): void
    {
        $plan = $this->plan();

        $this->resetState();
        $this->actingAs($this->superAdmin)
            ->putJson(route('admin.plans.modules.update'), ['grants' => [$plan->id => ['']]])
            ->assertOk();

        $this->assertSame([], $plan->fresh()->moduleKeys());
    }

    public function test_saving_the_matrix_unchanged_touches_nothing(): void
    {
        $plan = $this->plan();
        $before = $plan->moduleKeys();

        $this->resetState();
        $this->actingAs($this->superAdmin)
            ->putJson(route('admin.plans.modules.update'), ['grants' => [$plan->id => array_reverse($before)]])
            ->assertOk()
            ->assertJsonPath('message', 'Nothing to change.');
    }

    public function test_the_custom_plan_migration_rolls_back_and_forward(): void
    {
        $migration = require database_path('migrations/2026_10_30_000500_add_custom_plans_and_name_every_module.php');

        $migration->down();
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('plans', 'tenant_id'));

        $migration->up();
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('plans', 'tenant_id'));
    }
}
