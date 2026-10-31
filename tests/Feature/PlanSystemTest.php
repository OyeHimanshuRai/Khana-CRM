<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Shop;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SubscriptionBillingService;
use App\Services\SubscriptionService;
use App\Support\CurrentShop;
use App\Support\CurrentTenant;
use App\Support\Modules;
use App\Support\PlanAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Tests\TestCase;

/**
 * The plan system end to end: the platform writes the catalogue, a business
 * is put on a line of it, and every screen answers to that line from the next
 * request. Reads as the list of what the platform can and cannot do to a plan.
 */
class PlanSystemTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');
        $this->seed();
        $this->resetState();

        $this->shop = Shop::query()->withoutGlobalScopes()->orderBy('id')->firstOrFail();
        $this->tenant = Tenant::query()->findOrFail($this->shop->tenant_id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function resetState(): void
    {
        CurrentShop::forget();
        CurrentTenant::forget();
        PlanAccess::forget();
        Modules::forget();
    }

    private function admin(): User
    {
        $this->resetState();

        return User::query()->withoutGlobalScopes()->where('email', 'admin@erp.test')->firstOrFail();
    }

    private function owner(): User
    {
        $this->resetState();

        $owner = User::query()->where('email', 'owner@plan.test')->first() ?? tap(User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Owner', 'email' => 'owner@plan.test',
            'password' => 'Owner-pass-1', 'is_admin' => true, 'is_active' => true, 'current_shop_id' => $this->shop->id,
        ]), function (User $user) {
            $user->forceFill(['all_shops_view' => false])->save();
            $user->shops()->attach($this->shop->id, ['is_default' => true]);
            $user->assignRole('Tenant Owner');
        });

        return $owner->fresh();
    }

    private function custom(array $attributes = []): Plan
    {
        return Plan::create([
            'name' => 'Custom', 'code' => 'custom', 'slug' => 'custom', 'monthly_price' => 1500,
            'currency' => 'INR', 'trial_days' => 0, 'is_active' => true, 'modules' => ['pos', 'retail'],
            ...$attributes,
        ]);
    }

    private function subscribed(Plan $plan, ?Carbon $endsAt = null): Subscription
    {
        $subscription = Subscription::create([
            'tenant_id' => $this->tenant->id, 'shop_id' => null, 'plan_id' => $plan->id,
            'billing_period' => 'monthly', 'price' => $plan->monthly_price, 'currency' => 'INR',
            'starts_at' => now(), 'ends_at' => $endsAt ?? now()->addMonth(), 'grace_days' => 0,
        ]);
        $this->resetState();

        return $subscription;
    }

    private function form(array $overrides = []): array
    {
        return [
            'name' => 'Bakery', 'code' => 'bakery', 'monthly_price' => 999, 'currency' => 'INR',
            'trial_days' => 14, 'is_active' => 1, 'modules' => ['pos', 'retail'],
            'max_shops' => 2, 'max_users' => 8, 'max_orders_per_month' => 500, ...$overrides,
        ];
    }

    /* ------------------------------------------- what the platform controls */

    public function test_a_new_plan_reaches_every_place_a_plan_is_sold(): void
    {
        $this->actingAs($this->admin())->postJson(route('admin.plans.store'), $this->form())->assertOk();

        $plan = Plan::query()->where('code', 'bakery')->firstOrFail();
        $this->assertEqualsCanonicalizing(['pos', 'retail'], $plan->modules);
        $this->assertSame(14, $plan->trial_days);
        $this->assertSame([2, 8, 500], [$plan->max_shops, $plan->max_users, $plan->max_orders_per_month]);

        $this->get('/pricing')->assertOk()->assertSee('Bakery')->assertSee('Up to 2 outlets');
        // The sign-up form is for visitors, not for whoever is signed in here.
        \Illuminate\Support\Facades\Auth::guard('web')->logout();
        \Illuminate\Support\Facades\Auth::forgetGuards();
        $this->get('/register')->assertOk()->assertSee('Bakery');

        $this->subscribed(Plan::query()->where('code', 'chain')->firstOrFail());
        $this->actingAs($this->owner())->get('/superadmin/subscription')->assertOk()->assertSee('Bakery');
    }

    public function test_the_three_ways_of_saying_which_modules(): void
    {
        $this->actingAs($this->admin());

        $this->postJson(route('admin.plans.store'), $this->form(['code' => 'a', 'modules_all' => 1]))->assertOk();
        $this->assertSame(Modules::keys(), Plan::query()->where('code', 'a')->firstOrFail()->modules, 'every module there is today');

        $this->postJson(route('admin.plans.store'), $this->form(['code' => 'b', 'modules' => []]))->assertOk();
        $this->assertSame([], Plan::query()->where('code', 'b')->firstOrFail()->modules, 'deliberately nothing');

        $this->postJson(route('admin.plans.store'), $this->form(['code' => 'c', 'modules' => ['pos', 'made-up']]))->assertOk();
        $this->assertSame(['pos'], Plan::query()->where('code', 'c')->firstOrFail()->modules);
    }

    public function test_a_price_of_nothing_or_less_and_a_repeated_code_are_refused_or_kept_honest(): void
    {
        $this->actingAs($this->admin());

        $this->postJson(route('admin.plans.store'), $this->form(['monthly_price' => -1]))->assertStatus(422);
        $this->postJson(route('admin.plans.store'), $this->form(['code' => 'starter']))->assertStatus(422);
        $this->postJson(route('admin.plans.store'), $this->form(['trial_days' => 400]))->assertStatus(422);
    }

    public function test_nobody_but_the_platform_touches_the_catalogue(): void
    {
        $plan = Plan::query()->where('code', 'starter')->firstOrFail();
        $owner = $this->owner();

        $this->actingAs($owner)->get(route('admin.plans.index'))->assertForbidden();
        $this->actingAs($owner)->postJson(route('admin.plans.store'), $this->form())->assertForbidden();
        $this->actingAs($owner)->putJson(route('admin.plans.update', $plan), $this->form(['code' => 'starter', 'monthly_price' => 1]))->assertForbidden();
        $this->actingAs($owner)->putJson(route('admin.plans.status', $plan))->assertForbidden();
        $this->actingAs($owner)->deleteJson(route('admin.plans.destroy', $plan))->assertForbidden();
        $this->actingAs($owner)->postJson(route('admin.subscriptions.store', $this->tenant), [
            'plan_id' => $plan->id, 'billing_period' => 'monthly',
        ])->assertForbidden();

        $this->assertSame('799.00', (string) $plan->fresh()->monthly_price);
    }

    /* ------------------------------------------- an edit reaches subscribers */

    public function test_a_module_taken_off_a_plan_goes_from_the_next_request_and_comes_back(): void
    {
        $plan = $this->custom(['modules' => ['pos', 'retail', 'kitchen']]);
        $this->subscribed($plan);
        $owner = $this->owner();

        $this->actingAs($owner)->get('/superadmin/kitchen')->assertOk();

        $this->actingAs($this->admin())->putJson(route('admin.plans.update', $plan), $this->form([
            'code' => 'custom', 'modules' => ['pos', 'retail'],
        ]))->assertOk();

        $this->resetState();
        $this->actingAs($owner->fresh())->get('/superadmin/kitchen')->assertForbidden();

        $this->actingAs($this->admin())->putJson(route('admin.plans.update', $plan), $this->form([
            'code' => 'custom', 'modules' => ['pos', 'retail', 'kitchen'],
        ]))->assertOk();

        $this->resetState();
        $this->actingAs($owner->fresh())->get('/superadmin/kitchen')->assertOk();
    }

    public function test_a_new_price_does_not_change_what_a_subscriber_agreed_to(): void
    {
        $plan = $this->custom();
        $subscription = $this->subscribed($plan);

        $this->actingAs($this->admin())->putJson(route('admin.plans.update', $plan), $this->form([
            'code' => 'custom', 'monthly_price' => 2500,
        ]))->assertOk();

        $this->assertSame('1500.00', (string) $subscription->fresh()->price);
    }

    public function test_a_limit_lowered_below_what_is_in_use_stops_growth_and_takes_nothing_away(): void
    {
        $plan = $this->custom(['max_users' => 50]);
        $this->subscribed($plan);
        $owner = $this->owner();

        $plan->forceFill(['max_users' => 1, 'max_shops' => 0])->save();
        $this->resetState();

        // Everybody who exists keeps working.
        $this->actingAs($owner)->get('/superadmin/dashboard')->assertOk();
        $this->assertSame(0, PlanAccess::remainingUsers($this->tenant->id));

        // Nobody new.
        $this->resetState();
        $this->actingAs($owner)->postJson('/superadmin/shops', ['name' => 'Second', 'code' => 'SEC'])
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'Custom plan'));
    }

    public function test_an_order_allowance_of_zero_means_no_orders(): void
    {
        $this->subscribed($this->custom(['max_orders_per_month' => 0]));

        $this->expectException(RuntimeException::class);
        PlanAccess::assertCanTakeOrder($this->shop->id);
    }

    public function test_a_plan_stripped_of_every_module_leaves_the_account_open_and_the_screens_shut(): void
    {
        $this->subscribed($this->custom(['modules' => []]));
        $owner = $this->owner();

        $this->actingAs($owner)->get('/superadmin/dashboard')->assertOk();
        $this->resetState();
        $this->actingAs($owner)->get('/superadmin/pos')->assertForbidden();
        $this->resetState();
        $this->actingAs($owner)->get('/superadmin/reports')->assertForbidden();
    }

    /* ---------------------------------------------------- the plan and the shop */

    public function test_a_plan_caps_a_branch_and_never_switches_anything_on_for_it(): void
    {
        $this->shop->forceFill(['modules' => ['pos']])->saveQuietly();
        $this->subscribed($this->custom(['modules' => ['pos', 'retail', 'kitchen']]));

        $this->assertSame(['pos'], Modules::forShop($this->shop->fresh()));
    }

    public function test_moving_down_and_back_up_restores_what_the_branch_chose(): void
    {
        $this->shop->forceFill(['modules' => ['pos', 'kitchen']])->saveQuietly();

        $this->subscribed($this->custom(['modules' => ['pos']]));
        $this->assertSame(['pos'], Modules::forShop($this->shop->fresh()));

        $this->subscribed(Plan::query()->where('code', 'chain')->firstOrFail());
        $this->assertSame(['pos', 'kitchen'], array_values(array_intersect(Modules::keys(), Modules::forShop($this->shop->fresh()))));
    }

    /* ------------------------------------------------- withdrawing and deleting */

    public function test_a_withdrawn_plan_leaves_the_shop_window_and_keeps_its_subscribers(): void
    {
        $plan = $this->custom();
        $subscription = $this->subscribed($plan);

        $this->actingAs($this->admin())->putJson(route('admin.plans.status', $plan))->assertOk();
        \Illuminate\Support\Facades\Auth::forgetGuards();

        $this->assertFalse($plan->fresh()->is_active);
        $this->assertTrue($subscription->fresh()->isUsable());
        $this->get('/pricing')->assertOk()->assertDontSee('>Custom<', false);

        $this->post('/register', [
            'business_name' => 'New', 'name' => 'N', 'email' => 'n@plan.test', 'mobile' => '9876543210',
            'password' => 'Secret123', 'password_confirmation' => 'Secret123',
            'plan' => 'custom', 'period' => 'monthly', 'terms' => '1',
        ])->assertSessionHasErrors('plan');
    }

    public function test_a_plan_that_was_sold_cannot_be_deleted_and_one_that_was_not_can(): void
    {
        $sold = $this->custom();
        $this->subscribed($sold);
        $unsold = $this->custom(['code' => 'unsold', 'slug' => 'unsold']);

        $this->actingAs($this->admin())->deleteJson(route('admin.plans.destroy', $sold))->assertStatus(422);
        $this->assertNotNull($sold->fresh());

        $this->actingAs($this->admin())->deleteJson(route('admin.plans.destroy', $unsold))->assertOk();
        $this->assertNull(Plan::query()->find($unsold->id));
    }

    /* ------------------------------------------------- one business's own deal */

    public function test_a_negotiated_price_is_kept_on_the_subscription_not_the_plan(): void
    {
        $plan = Plan::query()->where('code', 'restaurant')->firstOrFail();

        $this->actingAs($this->admin())->postJson(route('admin.subscriptions.store', $this->tenant), [
            'plan_id' => $plan->id, 'billing_period' => 'monthly', 'price' => 1200, 'trial_days' => 30,
        ])->assertOk();

        $subscription = Subscription::query()->where('tenant_id', $this->tenant->id)->latest('id')->firstOrFail();
        $this->assertSame('1200.00', (string) $subscription->price);
        $this->assertSame('1999.00', (string) $plan->fresh()->monthly_price);
        $this->assertSame(30, $subscription->daysLeft());
    }

    public function test_changing_plan_mid_term_keeps_the_days_already_bought(): void
    {
        $ends = now()->addDays(20);
        $this->subscribed($this->custom(), $ends);

        $moved = app(SubscriptionService::class)->subscribe($this->tenant, Plan::query()->where('code', 'chain')->firstOrFail(), Plan::MONTHLY, 0);

        $this->assertEqualsWithDelta($ends->timestamp, $moved->ends_at->timestamp, 5);
        $this->assertSame('chain', PlanAccess::subscriptionFor($this->tenant->id)->plan->code);
    }

    public function test_a_plan_with_room_for_more_than_a_business_uses_lets_it_move_down_past_its_limits(): void
    {
        // Limits bite on adding, never on reading: moving down is not refused
        // for being over, it only stops the business from growing further.
        $this->subscribed($this->custom(['max_users' => null]));
        $this->owner();

        $small = $this->custom(['code' => 'small', 'slug' => 'small', 'max_users' => 0]);
        $moved = app(SubscriptionService::class)->subscribe($this->tenant, $small, Plan::MONTHLY, 0);

        $this->assertSame($small->id, $moved->plan_id);
        $this->assertSame(0, PlanAccess::remainingUsers($this->tenant->id));
    }

    public function test_online_a_customer_cannot_choose_the_price_or_a_withdrawn_plan(): void
    {
        config(['payments.invoice.gst_rate' => 0]);
        $subscription = $this->subscribed(Plan::query()->where('code', 'starter')->firstOrFail());
        $withdrawn = $this->custom(['is_active' => false]);

        $this->assertSame(1999.0, app(SubscriptionBillingService::class)
            ->quote($subscription, Plan::query()->where('code', 'restaurant')->firstOrFail(), Plan::MONTHLY)['list_price']);

        $this->expectException(RuntimeException::class);
        app(SubscriptionBillingService::class)->quote($subscription, $withdrawn, Plan::MONTHLY);
    }
}
