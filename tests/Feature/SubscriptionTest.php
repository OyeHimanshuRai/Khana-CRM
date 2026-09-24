<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Shop;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SubscriptionService;
use App\Support\CurrentShop;
use App\Support\CurrentTenant;
use App\Support\Modules;
use App\Support\PlanAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Selling the platform (§2, §4, §21).
 *
 * The thing worth testing hardest is the decision that shapes everything
 * else: a subscription's state is computed from its dates, never stored. So
 * most of what follows moves the clock and asks what the subscription says,
 * without running a single scheduled job - because that is exactly what
 * happens in production on a night the cron fails.
 *
 * The second theme is that none of this may break an install that is one
 * restaurant rather than a SaaS. "No subscription" has to mean "no ceiling",
 * everywhere, or upgrading would silently take features away from people who
 * never asked to be sold anything.
 */
class SubscriptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');

        $this->seed();

        CurrentShop::forget();
        CurrentTenant::forget();
        PlanAccess::forget();
        Modules::forget();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        CurrentShop::forget();
        CurrentTenant::forget();
        PlanAccess::forget();
        Modules::forget();

        parent::tearDown();
    }

    /* ---------------------------------------------------------- fixtures */

    private function service(): SubscriptionService
    {
        return app(SubscriptionService::class);
    }

    private function tenant(): Tenant
    {
        return Tenant::query()->orderBy('id')->firstOrFail();
    }

    private function plan(string $code = 'starter'): Plan
    {
        return Plan::query()->where('code', $code)->firstOrFail();
    }

    private function admin(): User
    {
        return User::query()->where('email', 'admin@erp.test')->firstOrFail();
    }

    /* ------------------------------------------------------ the catalogue */

    public function test_the_seeded_catalogue_describes_three_real_restaurants(): void
    {
        $this->assertSame(3, Plan::query()->count());

        // The top plan is deliberately unrestricted rather than exhaustively
        // ticked, so a module added next year belongs to it on its own.
        $this->assertNull($this->plan('chain')->modules);
        $this->assertNull($this->plan('chain')->max_shops);

        $this->assertSame(1, $this->plan('starter')->max_shops);
    }

    public function test_seeding_the_catalogue_sells_nothing_to_anybody(): void
    {
        // The single most important property of this feature: an install that
        // is one restaurant must carry on exactly as it did.
        $this->assertSame(0, Subscription::query()->count());
        $this->assertNull(PlanAccess::modulesFor($this->tenant()->id));
        $this->assertTrue(PlanAccess::canAddShop($this->tenant()->id));
        $this->assertNull(PlanAccess::remainingShops($this->tenant()->id));
    }

    public function test_an_unrestricted_plan_grants_every_module_including_future_ones(): void
    {
        $plan = $this->plan('chain');

        $this->assertSame(Modules::keys(), $plan->moduleKeys());
        $this->assertTrue($plan->grants('kitchen'));
    }

    public function test_a_plan_stripped_to_nothing_is_not_the_same_as_an_unrestricted_one(): void
    {
        $none = Plan::create([
            'name' => 'Nothing', 'code' => 'nothing', 'slug' => 'nothing',
            'monthly_price' => 0, 'currency' => 'INR', 'modules' => [],
        ]);

        // [] and null must stay distinguishable, or "all, including future
        // ones" becomes unexpressible.
        $this->assertSame([], $none->moduleKeys());
        $this->assertFalse($none->grants('pos'));
    }

    public function test_a_yearly_price_falls_back_to_twelve_months(): void
    {
        $plan = Plan::create([
            'name' => 'Monthly only', 'code' => 'monthly-only', 'slug' => 'monthly-only',
            'monthly_price' => 100, 'yearly_price' => null, 'currency' => 'INR',
        ]);

        $this->assertSame(1200.0, $plan->priceFor(Plan::YEARLY));
        $this->assertSame(100.0, $plan->priceFor(Plan::MONTHLY));

        // A real yearly price is a discount decision, not arithmetic.
        $plan->update(['yearly_price' => 1000]);
        $this->assertSame(1000.0, $plan->fresh()->priceFor(Plan::YEARLY));
    }

    /* ------------------------------------------------- state from the clock */

    public function test_a_new_subscription_starts_on_the_plans_own_trial(): void
    {
        $sub = $this->service()->subscribe($this->tenant(), $this->plan('starter'));

        $this->assertSame(Subscription::TRIALING, $sub->state());
        $this->assertTrue($sub->onTrial());
        $this->assertTrue($sub->isUsable());
        $this->assertSame(14, $sub->daysLeft());
    }

    public function test_state_moves_through_grace_to_expired_with_nothing_running(): void
    {
        $sub = $this->service()->subscribe($this->tenant(), $this->plan('starter'));
        $sub->forceFill([
            'trial_ends_at' => null,
            'ends_at' => now()->addDay(),
            'grace_days' => 3,
        ])->save();

        $this->assertSame(Subscription::ACTIVE, $sub->state());

        // Inside grace: still trading, and loudly.
        Carbon::setTestNow(now()->addDays(2));
        $this->assertSame(Subscription::PAST_DUE, $sub->state());
        $this->assertTrue($sub->isUsable());
        $this->assertTrue($sub->needsAttention());

        // Past grace: locked out. No job has run at any point here.
        Carbon::setTestNow(now()->addDays(5));
        $this->assertSame(Subscription::EXPIRED, $sub->state());
        $this->assertFalse($sub->isUsable());
    }

    public function test_a_subscription_with_no_end_date_never_expires(): void
    {
        $sub = $this->service()->subscribe($this->tenant(), $this->plan('chain'));
        $sub->forceFill(['trial_ends_at' => null, 'ends_at' => null])->save();

        Carbon::setTestNow(now()->addYears(5));

        $this->assertSame(Subscription::ACTIVE, $sub->state());
        $this->assertNull($sub->daysLeft());
    }

    /* -------------------------------------------------------- the ceiling */

    public function test_a_plan_caps_the_modules_a_branch_may_reach(): void
    {
        $shop = Shop::query()->withoutGlobalScopes()->firstOrFail();
        $shop->forceFill(['modules' => null])->save();

        $this->service()->subscribe($this->tenant(), $this->plan('starter'));
        PlanAccess::forget();
        Modules::forget();

        // The branch asked for everything; the plan sells four.
        $this->assertSame(['retail', 'pos', 'customers', 'reports'], Modules::forShop($shop->fresh()));
    }

    public function test_the_plan_is_a_ceiling_and_never_switches_anything_on(): void
    {
        $shop = Shop::query()->withoutGlobalScopes()->firstOrFail();
        $shop->forceFill(['modules' => ['pos']])->save();

        $this->service()->subscribe($this->tenant(), $this->plan('chain'));
        PlanAccess::forget();
        Modules::forget();

        // The chain plan grants everything; the branch still only wants POS.
        $this->assertSame(['pos'], Modules::forShop($shop->fresh()));
    }

    public function test_a_super_admin_is_not_capped_by_somebody_elses_plan(): void
    {
        $shop = Shop::query()->withoutGlobalScopes()->firstOrFail();
        $shop->forceFill(['modules' => null])->save();

        $this->service()->subscribe($this->tenant(), $this->plan('starter'));

        $this->actingAs($this->admin());
        PlanAccess::forget();
        Modules::forget();

        // The person whose job is to fix the account has to be able to open
        // it - the same exemption EnsureTenantIsActive makes.
        $this->assertNull(PlanAccess::modulesFor($this->tenant()->id));
        $this->assertSame(Modules::keys(), Modules::forShop($shop->fresh()));
    }

    /* --------------------------------------------------------- the limits */

    public function test_a_branch_beyond_the_plans_allowance_is_refused_by_name(): void
    {
        $this->service()->subscribe($this->tenant(), $this->plan('starter'));
        PlanAccess::forget();

        $tenantId = $this->tenant()->id;

        $this->assertSame(0, PlanAccess::remainingShops($tenantId));
        $this->assertFalse(PlanAccess::canAddShop($tenantId));

        $message = PlanAccess::refusalFor($tenantId, 'shop');

        // Naming the plan and the number is the difference between a refusal
        // somebody can act on and one they have to ring up about.
        $this->assertStringContainsString('Starter', $message);
        $this->assertStringContainsString('1 branch,', $message);
        $this->assertStringNotContainsString('1 branches', $message);
    }

    public function test_limits_are_checked_when_adding_and_never_when_reading(): void
    {
        $this->service()->subscribe($this->tenant(), $this->plan('starter'));
        PlanAccess::forget();

        $this->actingAs($this->admin());

        // The branch that already exists is still perfectly readable, which
        // is the whole point: nobody is locked out of what they own.
        $this->get(route('admin.shops.index'))->assertOk();

        $response = $this->postJson(route('admin.shops.store'), [
            'name' => 'Second Branch',
            'code' => 'SB',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('Starter', $response->json('message'));
    }

    public function test_an_install_with_no_subscription_may_add_as_many_branches_as_it_likes(): void
    {
        $this->actingAs($this->admin());

        $this->postJson(route('admin.shops.store'), [
            'name' => 'Second Branch',
            'code' => 'SB2',
        ])->assertOk();

        $this->assertSame(2, Shop::query()->withoutGlobalScopes()->count());
    }

    /* ------------------------------------------------------- per outlet */

    /*
     | Subscriptions are sold per outlet - the pricing page has always said
     | "Per outlet", and these are the tests that make it true.
     |
     | The one that matters most is the blast radius. A group running five
     | restaurants must not lose all five because one of them fell behind,
     | and there are three separate places that could do it: the middleware,
     | the flag on the tenant row, and the nightly sweep. Each gets its own
     | test, because each would fail differently and silently.
     */

    private function secondShop(): Shop
    {
        return Shop::query()->withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant()->id,
            'name' => 'Second Branch',
            'code' => 'SB',
            'slug' => 'second-branch',
            'is_active' => true,
        ]);
    }

    public function test_an_outlet_is_sold_its_own_subscription(): void
    {
        $shop = Shop::query()->withoutGlobalScopes()->firstOrFail();

        $sub = $this->service()->subscribeShop($shop, $this->plan('restaurant'));

        $this->assertSame($shop->id, $sub->shop_id);
        $this->assertSame($this->tenant()->id, $sub->tenant_id);
        $this->assertTrue($sub->isPerShop());
    }

    public function test_an_outlets_subscription_is_not_the_businesss_subscription(): void
    {
        $shop = Shop::query()->withoutGlobalScopes()->firstOrFail();
        $this->service()->subscribeShop($shop, $this->plan('restaurant'));
        PlanAccess::forget();

        // The blanket lookup must not see it. If it did, one outlet falling
        // behind would sign every branch's staff out at the door.
        $this->assertNull(PlanAccess::subscriptionFor($this->tenant()->id));
        $this->assertNotNull(PlanAccess::subscriptionForShop($shop->id));
    }

    public function test_two_outlets_of_one_business_can_sit_on_different_plans(): void
    {
        $first = Shop::query()->withoutGlobalScopes()->firstOrFail();
        $second = $this->secondShop();

        $this->service()->subscribeShop($first, $this->plan('starter'));
        $this->service()->subscribeShop($second, $this->plan('chain'));
        PlanAccess::forget();

        $this->assertSame('starter', PlanAccess::subscriptionForShop($first->id)->plan->code);
        $this->assertSame('chain', PlanAccess::subscriptionForShop($second->id)->plan->code);

        // And the module ceiling follows the outlet, not the business - the
        // whole reason for selling per outlet in the first place.
        $this->assertNotNull(PlanAccess::modulesForShop($first->id));
        $this->assertNull(PlanAccess::modulesForShop($second->id));
    }

    public function test_an_outlet_with_no_subscription_falls_back_to_the_blanket_row(): void
    {
        $second = $this->secondShop();

        // The old shape: one row, no shop_id, covering the whole business.
        $blanket = $this->service()->subscribe($this->tenant(), $this->plan('restaurant'));
        PlanAccess::forget();

        $this->assertNull($blanket->shop_id);
        $this->assertSame($blanket->id, PlanAccess::subscriptionForShop($second->id)?->id);
    }

    public function test_an_outlets_own_row_wins_over_the_blanket_even_when_expired(): void
    {
        $shop = Shop::query()->withoutGlobalScopes()->firstOrFail();

        $this->service()->subscribe($this->tenant(), $this->plan('chain'));
        $own = $this->service()->subscribeShop($shop, $this->plan('starter'));

        $own->forceFill(['ends_at' => now()->subMonth(), 'grace_days' => 0])->save();
        PlanAccess::forget();

        /*
         | Falling back here would sell the same outlet twice: somebody has
         | moved this branch onto its own subscription, and a late invoice
         | does not put it back under the business-wide one.
         */
        $this->assertSame($own->id, PlanAccess::subscriptionForShop($shop->id)?->id);
        $this->assertFalse(PlanAccess::subscriptionForShop($shop->id)->isUsable());
    }

    public function test_one_lapsed_outlet_does_not_suspend_the_business(): void
    {
        $shop = Shop::query()->withoutGlobalScopes()->firstOrFail();
        $sub = $this->service()->subscribeShop($shop, $this->plan('restaurant'));

        $sub->forceFill(['ends_at' => now()->subMonth(), 'grace_days' => 0])->save();
        $this->service()->reflectOnTenant($sub->fresh());

        $this->assertFalse($this->tenant()->fresh()->isSuspended());
    }

    public function test_the_nightly_sweep_never_suspends_a_business_over_one_outlet(): void
    {
        $shop = Shop::query()->withoutGlobalScopes()->firstOrFail();
        $sub = $this->service()->subscribeShop($shop, $this->plan('restaurant'));
        $sub->forceFill(['ends_at' => now()->subMonth(), 'grace_days' => 0])->save();

        $this->assertCount(0, $this->service()->pendingSuspension());
        $this->assertCount(0, $this->service()->sweep());
        $this->assertFalse($this->tenant()->fresh()->isSuspended());
    }

    public function test_a_lapsed_outlet_is_blocked_without_signing_anybody_out(): void
    {
        $shop = Shop::query()->withoutGlobalScopes()->firstOrFail();
        $sub = $this->service()->subscribeShop($shop, $this->plan('restaurant'));
        $sub->forceFill(['ends_at' => now()->subMonth(), 'grace_days' => 0])->save();
        PlanAccess::forget();

        $staff = $this->staffOf($shop);
        $this->actingAs($staff);
        CurrentShop::forget();

        $response = $this->get(route('admin.products.index'));

        // Redirected, not logged out - the group's other branches are paid
        // up and mid-service.
        $response->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($staff);
        // The refusal names the branch: "subscription expired" tells an
        // operator they have a problem without saying which restaurant has it.
        $this->assertStringContainsString($shop->name, (string) session('error'));
        $this->assertStringContainsString('switch to another outlet', (string) session('error'));
    }

    public function test_an_outlet_inside_its_grace_window_keeps_trading(): void
    {
        $shop = Shop::query()->withoutGlobalScopes()->firstOrFail();
        $sub = $this->service()->subscribeShop($shop, $this->plan('restaurant'));

        // Yesterday, with three days of grace: past due, not expired.
        $sub->forceFill(['ends_at' => now()->subDay(), 'grace_days' => 3])->save();
        PlanAccess::forget();

        $this->assertSame(Subscription::PAST_DUE, $sub->fresh()->state());
        $this->assertTrue($sub->fresh()->isUsable());

        $this->actingAs($this->staffOf($shop));
        CurrentShop::forget();

        $this->get(route('admin.dashboard'))->assertOk();
    }

    public function test_an_outlet_nobody_sold_anything_to_still_works(): void
    {
        $shop = Shop::query()->withoutGlobalScopes()->firstOrFail();
        PlanAccess::forget();

        // The rule the whole feature rests on: no subscription is no ceiling,
        // so an install that predates billing is untouched by all of this.
        $this->actingAs($this->staffOf($shop));
        CurrentShop::forget();

        $this->get(route('admin.dashboard'))->assertOk();
    }

    public function test_a_payment_is_recorded_against_the_outlet_it_was_taken_for(): void
    {
        $shop = Shop::query()->withoutGlobalScopes()->firstOrFail();
        $sub = $this->service()->subscribeShop($shop, $this->plan('restaurant'));

        $payment = $this->service()->recordPayment($sub, 1999.0);

        $this->assertSame($shop->id, $payment->shop_id);
    }

    public function test_an_outlet_cannot_be_billed_to_another_business(): void
    {
        $other = Tenant::query()->create([
            'name' => 'Someone Else',
            'code' => 'ELSE',
            'slug' => 'someone-else',
            'is_active' => true,
        ]);
        $shop = Shop::query()->withoutGlobalScopes()->firstOrFail();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('different business');

        $this->service()->subscribe($other, $this->plan('starter'), 'monthly', null, null, null, $shop);
    }

    public function test_an_outlet_is_sold_a_plan_over_http(): void
    {
        $shop = $this->secondShop();
        $this->actingAs($this->admin());

        $this->postJson(route('admin.subscriptions.store', $this->tenant()), [
            'plan_id' => $this->plan('restaurant')->id,
            'shop_id' => $shop->id,
            'billing_period' => 'monthly',
        ])->assertOk();

        $sub = Subscription::query()->where('shop_id', $shop->id)->firstOrFail();

        $this->assertSame($shop->id, $sub->shop_id);
        $this->assertSame('restaurant', $sub->plan->code);

        // And the business itself is still on nothing.
        PlanAccess::forget();
        $this->assertNull(PlanAccess::subscriptionFor($this->tenant()->id));
    }

    public function test_an_outlet_belonging_to_another_business_is_refused_over_http(): void
    {
        $other = Tenant::query()->create([
            'name' => 'Another Group',
            'code' => 'AGRP',
            'slug' => 'another-group',
            'is_active' => true,
        ]);

        $theirs = Shop::query()->withoutGlobalScopes()->create([
            'tenant_id' => $other->id,
            'name' => 'Their Branch',
            'code' => 'TB',
            'slug' => 'their-branch',
            'is_active' => true,
        ]);

        $this->actingAs($this->admin());

        // Validation scopes shop_id to the tenant being billed, so a typed
        // id from another account never reaches the service at all.
        $this->postJson(route('admin.subscriptions.store', $this->tenant()), [
            'plan_id' => $this->plan('starter')->id,
            'shop_id' => $theirs->id,
            'billing_period' => 'monthly',
        ])->assertStatus(422);

        $this->assertSame(0, Subscription::query()->count());
    }

    /** An admin of one branch, for the middleware tests. */
    private function staffOf(Shop $shop): User
    {
        $user = User::query()->create([
            'tenant_id' => $shop->tenant_id,
            'name' => 'Branch Admin',
            'email' => 'branch@example.test',
            'password' => 'branch-password-1',
            'is_admin' => true,
        ]);

        $user->shops()->syncWithoutDetaching([$shop->id => ['is_default' => true]]);
        $user->forceFill(['current_shop_id' => $shop->id, 'all_shops_view' => false])->save();
        $user->givePermissionTo(['dashboard.overview.view', 'inventory.products.view']);

        return $user->fresh();
    }

    /* -------------------------------------------------------- the billing */

    public function test_a_payment_extends_the_term_ends_the_trial_and_is_referenced(): void
    {
        $sub = $this->service()->subscribe($this->tenant(), $this->plan('starter'));
        $trialEnd = $sub->ends_at->copy();

        $payment = $this->service()->recordPayment($sub, 799, 'upi', 'UTR-1');

        $sub = $sub->fresh();

        $this->assertMatchesRegularExpression('/^SUB-\d{6}$/', $payment->reference);
        $this->assertSame(Subscription::ACTIVE, $sub->state());
        $this->assertNull($sub->trial_ends_at);

        // Extended from where the trial ended, not from today: days already
        // granted are not forfeited by paying early.
        $this->assertSame(
            $trialEnd->copy()->addMonth()->toDateString(),
            $sub->ends_at->toDateString(),
        );
    }

    public function test_paying_late_does_not_buy_days_that_were_never_had(): void
    {
        $sub = $this->service()->subscribe($this->tenant(), $this->plan('starter'));
        $sub->forceFill(['trial_ends_at' => null, 'ends_at' => now()->subDays(20)])->save();

        $this->service()->recordPayment($sub, 799);

        // Extends from today, because the twenty days are gone.
        $this->assertSame(
            now()->addMonth()->toDateString(),
            $sub->fresh()->ends_at->toDateString(),
        );
    }

    public function test_a_refund_is_recorded_and_buys_nothing(): void
    {
        $sub = $this->service()->subscribe($this->tenant(), $this->plan('starter'));
        $this->service()->recordPayment($sub, 799);

        $before = $sub->fresh()->ends_at->copy();

        $refund = $this->service()->recordPayment($sub->fresh(), -200, 'bank', note: 'goodwill');

        $this->assertTrue($refund->isRefund());
        $this->assertSame($before->toDateTimeString(), $sub->fresh()->ends_at->toDateTimeString());

        // Both halves of the mistake stay visible; nothing is edited away.
        $this->assertSame(2, SubscriptionPayment::query()->count());
        $this->assertEqualsWithDelta(599, SubscriptionPayment::query()->sum('amount'), 0.01);
    }

    public function test_the_agreed_price_survives_the_plan_being_repriced(): void
    {
        $plan = $this->plan('starter');
        $sub = $this->service()->subscribe($this->tenant(), $plan, price: 500);

        $plan->update(['monthly_price' => 9999]);

        // Re-pricing the catalogue must not silently re-price the people
        // already on it.
        $this->assertEqualsWithDelta(500, (float) $sub->fresh()->price, 0.01);
    }

    /* ---------------------------------------------- changing and stopping */

    public function test_changing_plan_keeps_the_days_already_paid_for(): void
    {
        $sub = $this->service()->subscribe($this->tenant(), $this->plan('starter'));
        $this->service()->recordPayment($sub, 799);

        $paidUntil = $sub->fresh()->ends_at->copy();

        $upgraded = $this->service()->subscribe($this->tenant(), $this->plan('restaurant'));

        $this->assertSame($paidUntil->toDateTimeString(), $upgraded->ends_at->toDateTimeString());

        // History is the list of rows, and the newest speaks for the tenant.
        $this->assertSame(2, $this->tenant()->subscriptions()->count());
        $this->assertSame($upgraded->id, $this->tenant()->subscription->id);
    }

    public function test_cancelling_takes_effect_when_the_paid_term_runs_out(): void
    {
        $sub = $this->service()->subscribe($this->tenant(), $this->plan('starter'));
        $this->service()->recordPayment($sub, 799);
        $sub = $sub->fresh();

        $this->service()->cancel($sub, 'moving on');
        $sub = $sub->fresh();

        // They paid for the month; giving notice does not forfeit it.
        $this->assertTrue($sub->isUsable());
        $this->assertSame(Subscription::ACTIVE, $sub->state());

        Carbon::setTestNow(now()->addMonths(2));
        $this->assertSame(Subscription::CANCELLED, $sub->state());
        $this->assertFalse($sub->isUsable());
    }

    public function test_cancelling_immediately_is_a_different_act(): void
    {
        $sub = $this->service()->subscribe($this->tenant(), $this->plan('starter'));
        $this->service()->recordPayment($sub, 799);

        $this->service()->cancel($sub->fresh(), 'chargeback', immediately: true);

        $this->assertSame(Subscription::CANCELLED, $sub->fresh()->state());
        $this->assertTrue($this->tenant()->fresh()->isSuspended());
    }

    public function test_paying_reinstates_an_account_the_billing_engine_suspended(): void
    {
        $sub = $this->service()->subscribe($this->tenant(), $this->plan('starter'));
        $sub->forceFill(['trial_ends_at' => null, 'ends_at' => now()->subDays(30)])->save();

        $this->service()->sweep();
        $this->assertTrue($this->tenant()->fresh()->isSuspended());

        $this->service()->recordPayment($sub->fresh(), 799);

        $this->assertFalse($this->tenant()->fresh()->isSuspended());
    }

    public function test_a_tenant_suspended_by_hand_is_not_reinstated_by_a_renewal(): void
    {
        $tenant = $this->tenant();
        $sub = $this->service()->subscribe($tenant, $this->plan('starter'));

        // Somebody shut this account deliberately, for something that is not
        // a billing matter.
        $tenant->suspend('Fraud investigation');

        $this->service()->recordPayment($sub->fresh(), 799);

        $this->assertTrue($tenant->fresh()->isSuspended());
        $this->assertSame('Fraud investigation', $tenant->fresh()->suspend_reason);
    }

    /* ----------------------------------------------------------- the sweep */

    public function test_the_sweep_suspends_lapsed_accounts_and_is_idempotent(): void
    {
        $sub = $this->service()->subscribe($this->tenant(), $this->plan('starter'));
        $sub->forceFill(['trial_ends_at' => null, 'ends_at' => now()->subDays(30)])->save();

        $this->assertCount(1, $this->service()->sweep());
        $this->assertTrue($this->tenant()->fresh()->isSuspended());

        // Running it again changes nothing extra - a missed week caught up
        // in one go must not double-act.
        $this->assertCount(0, $this->service()->sweep());
    }

    public function test_the_sweep_ignores_an_old_row_left_behind_by_an_upgrade(): void
    {
        $old = $this->service()->subscribe($this->tenant(), $this->plan('starter'));
        $old->forceFill(['trial_ends_at' => null, 'ends_at' => now()->subDays(30)])->save();

        // The upgrade is current and paid; the superseded row's ends_at is in
        // the past by design. Suspending on it would shut down the accounts
        // that just gave the platform money.
        $new = $this->service()->subscribe($this->tenant(), $this->plan('restaurant'));
        $new->forceFill(['trial_ends_at' => null, 'ends_at' => now()->addMonth()])->save();

        $this->assertCount(0, $this->service()->sweep());
        $this->assertFalse($this->tenant()->fresh()->isSuspended());
    }

    public function test_the_dry_run_reports_exactly_what_the_real_run_would_do(): void
    {
        $sub = $this->service()->subscribe($this->tenant(), $this->plan('starter'));
        $sub->forceFill(['trial_ends_at' => null, 'ends_at' => now()->subDays(30)])->save();

        $this->artisan('subscriptions:sweep --dry-run')
            ->expectsOutputToContain('1 tenant(s) would be suspended.')
            ->assertSuccessful();

        // A dry run that changed something would be the worst bug on this page.
        $this->assertFalse($this->tenant()->fresh()->isSuspended());

        $this->artisan('subscriptions:sweep')->assertSuccessful();
        $this->assertTrue($this->tenant()->fresh()->isSuspended());
    }

    public function test_the_command_lists_subscriptions_about_to_run_out(): void
    {
        $sub = $this->service()->subscribe($this->tenant(), $this->plan('starter'));
        $sub->forceFill(['trial_ends_at' => null, 'ends_at' => now()->addDays(3)])->save();

        $this->artisan('subscriptions:sweep')
            ->expectsOutputToContain('Ending within 7 days:')
            ->assertSuccessful();
    }

    /* ----------------------------------------------------------- the doors */

    public function test_an_expired_subscription_locks_staff_out_without_the_sweep(): void
    {
        $sub = $this->service()->subscribe($this->tenant(), $this->plan('starter'));
        $sub->forceFill(['trial_ends_at' => null, 'ends_at' => now()->subDays(30)])->save();

        // A staff account inside the business - not the Super Admin, who is
        // exempt on purpose.
        $staff = User::factory()->create([
            'tenant_id' => $this->tenant()->id,
            'is_admin' => true,
        ]);
        $staff->assignRole('Tenant Owner');

        $this->actingAs($staff);

        $response = $this->get(route('admin.dashboard'));

        $response->assertRedirect(route('admin.login'));
        $this->assertGuest();
    }

    public function test_being_inside_the_grace_window_does_not_lock_anybody_out(): void
    {
        $sub = $this->service()->subscribe($this->tenant(), $this->plan('starter'));
        $sub->forceFill([
            'trial_ends_at' => null,
            'ends_at' => now()->subDay(),
            'grace_days' => 3,
        ])->save();

        $staff = User::factory()->create([
            'tenant_id' => $this->tenant()->id,
            'is_admin' => true,
        ]);
        $staff->assignRole('Tenant Owner');

        $this->actingAs($staff);

        // Locking a restaurant out of its own till mid-service over an
        // invoice is how a platform loses the customer rather than collects.
        $this->get(route('admin.dashboard'))->assertOk();
    }

    /* ---------------------------------------------------------- the screens */

    public function test_the_platform_list_and_the_plan_catalogue_open(): void
    {
        $this->actingAs($this->admin());

        $this->get(route('admin.plans.index'))->assertOk()->assertSee('Restaurant');
        $this->get(route('admin.subscriptions.index'))->assertOk();
        $this->get(route('admin.subscription.mine'))->assertOk();
    }

    public function test_the_customers_own_screen_says_plainly_when_there_is_no_plan(): void
    {
        $this->actingAs($this->admin());

        $this->get(route('admin.subscription.mine'))
            ->assertOk()
            ->assertSee('not on a subscription plan');
    }

    public function test_a_tenant_owner_may_read_their_subscription_but_not_change_it(): void
    {
        $this->service()->subscribe($this->tenant(), $this->plan('starter'));

        $owner = User::factory()->create([
            'tenant_id' => $this->tenant()->id,
            'is_admin' => true,
        ]);
        $owner->assignRole('Tenant Owner');

        $this->actingAs($owner);

        $this->get(route('admin.subscription.mine'))->assertOk();

        // Renewing your own subscription is not a feature.
        $this->postJson(route('admin.subscriptions.store', $this->tenant()), [
            'plan_id' => $this->plan('chain')->id,
            'billing_period' => 'monthly',
        ])->assertForbidden();
    }

    public function test_a_tenant_owner_cannot_read_another_companys_billing(): void
    {
        $other = Tenant::create([
            'name' => 'Someone Else', 'code' => 'ELSE', 'slug' => 'someone-else',
        ]);

        $owner = User::factory()->create([
            'tenant_id' => $this->tenant()->id,
            'is_admin' => true,
        ]);
        $owner->assignRole('Tenant Owner');

        $this->actingAs($owner);

        // Route model binding knows nothing about tenancy, so without the
        // guard this is somebody else's invoice history in a URL.
        $this->get(route('admin.subscriptions.show', $other))->assertNotFound();
    }

    public function test_putting_a_business_on_a_plan_over_http(): void
    {
        $this->actingAs($this->admin());

        $this->postJson(route('admin.subscriptions.store', $this->tenant()), [
            'plan_id' => $this->plan('restaurant')->id,
            'billing_period' => 'yearly',
            'note' => 'agreed on the call',
        ])->assertOk();

        $sub = $this->tenant()->subscription;

        $this->assertSame('yearly', $sub->billing_period);
        $this->assertEqualsWithDelta(19990, (float) $sub->price, 0.01);
    }

    public function test_recording_a_payment_over_http_moves_the_renewal_date(): void
    {
        $this->actingAs($this->admin());
        $this->service()->subscribe($this->tenant(), $this->plan('starter'));

        $response = $this->postJson(route('admin.subscriptions.payment.store', $this->tenant()), [
            'amount' => 799,
            'method' => 'upi',
            'provider_reference' => 'UTR-9',
        ]);

        $response->assertOk();
        $this->assertStringContainsString('runs until', $response->json('message'));
        $this->assertSame(1, SubscriptionPayment::query()->count());
    }

    public function test_a_payment_against_a_business_with_no_plan_is_refused(): void
    {
        $this->actingAs($this->admin());

        $this->postJson(route('admin.subscriptions.payment.store', $this->tenant()), [
            'amount' => 500,
            'method' => 'manual',
        ])->assertStatus(422);
    }

    public function test_a_plan_somebody_is_paying_for_cannot_be_deleted(): void
    {
        $this->actingAs($this->admin());
        $plan = $this->plan('starter');
        $this->service()->subscribe($this->tenant(), $plan);

        $response = $this->deleteJson(route('admin.plans.destroy', $plan));

        $response->assertStatus(422);
        $this->assertStringContainsString('Switch it off instead', $response->json('message'));
        $this->assertNotNull($plan->fresh());
    }

    public function test_withdrawing_a_plan_from_sale_keeps_its_subscribers(): void
    {
        $this->actingAs($this->admin());
        $plan = $this->plan('starter');
        $sub = $this->service()->subscribe($this->tenant(), $plan);

        $this->putJson(route('admin.plans.status', $plan))->assertOk();

        $this->assertFalse($plan->fresh()->is_active);
        $this->assertTrue($sub->fresh()->isUsable());
    }

    public function test_every_module_is_stored_as_no_restriction_not_a_ticked_list(): void
    {
        $this->actingAs($this->admin());

        $this->postJson(route('admin.plans.store'), [
            'name' => 'Everything', 'code' => 'everything',
            'monthly_price' => 100, 'currency' => 'INR',
            'modules_all' => 1,
            'modules' => ['pos'],
        ])->assertOk();

        // The ticked list is ignored when "all" is asked for, and null is
        // stored - so a module added next year is included on its own.
        $this->assertNull(Plan::where('code', 'everything')->firstOrFail()->modules);
    }
}
