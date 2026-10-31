<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Shop;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SubscriptionBillingService;
use App\Services\SubscriptionService;
use App\Support\CurrentShop;
use App\Support\CurrentTenant;
use App\Support\Modules;
use App\Support\PlanAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Tests\TestCase;

/**
 * Two kinds of plan, and a catalogue the platform fully controls.
 *
 * General plans are on the price list for anybody to buy. A custom plan is
 * made for one client: it is never on show, only the platform can put that
 * client on it, and from then on it renews like any other. Modules reach a
 * plan only because the platform put them there - a module added to the
 * software next year joins nothing by itself.
 */
class CustomPlanTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private Tenant $client;

    private Tenant $other;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');
        config([
            'payments.platform.key' => 'rzp_test', 'payments.platform.secret' => 'sek',
            'payments.invoice.gst_rate' => 0,
        ]);
        $this->seed();
        $this->resetState();

        $this->shop = Shop::query()->withoutGlobalScopes()->orderBy('id')->firstOrFail();
        $this->client = Tenant::query()->findOrFail($this->shop->tenant_id);
        $this->other = Tenant::create(['name' => 'Other Foods', 'code' => 'OTHER', 'slug' => 'other-foods', 'is_active' => true]);
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
        Auth::forgetGuards();

        return User::query()->withoutGlobalScopes()->where('email', 'admin@erp.test')->firstOrFail();
    }

    private function owner(): User
    {
        $this->resetState();
        Auth::forgetGuards();

        $owner = User::query()->withoutGlobalScopes()->where('email', 'owner@client.test')->first() ?? tap(User::create([
            'tenant_id' => $this->client->id, 'name' => 'Owner', 'email' => 'owner@client.test',
            'password' => 'Owner-pass-1', 'is_admin' => true, 'is_active' => true, 'current_shop_id' => $this->shop->id,
        ]), function (User $user) {
            $user->forceFill(['all_shops_view' => false])->save();
            $user->shops()->attach($this->shop->id, ['is_default' => true]);
            $user->assignRole('Tenant Owner');
        });

        return $owner->fresh();
    }

    private function plan(array $attributes = []): Plan
    {
        return Plan::create([
            'name' => 'Client Special', 'code' => 'client-special', 'slug' => 'client-special',
            'monthly_price' => 1500, 'currency' => 'INR', 'trial_days' => 0, 'is_active' => true,
            'modules' => ['pos', 'retail'], ...$attributes,
        ]);
    }

    private function customFor(Tenant $tenant, array $attributes = []): Plan
    {
        return $this->plan(['tenant_id' => $tenant->id, ...$attributes]);
    }

    private function on(Plan $plan, array $attributes = []): Subscription
    {
        $subscription = Subscription::create([
            'tenant_id' => $this->client->id, 'shop_id' => null, 'plan_id' => $plan->id,
            'billing_period' => 'monthly', 'price' => $plan->monthly_price, 'currency' => 'INR',
            'starts_at' => now(), 'ends_at' => now()->addMonth(), 'grace_days' => 0, ...$attributes,
        ]);
        $this->resetState();

        return $subscription;
    }

    private function form(array $overrides = []): array
    {
        return [
            'name' => 'Made For Them', 'code' => 'made-for-them', 'monthly_price' => 1200, 'currency' => 'INR',
            'is_active' => 1, 'modules' => ['pos'], ...$overrides,
        ];
    }

    /* ------------------------------------------------- never on public show */

    public function test_a_custom_plan_is_not_on_any_public_page(): void
    {
        $this->customFor($this->client);

        $this->get('/')->assertOk()->assertDontSee('Client Special');
        $this->get('/pricing')->assertOk()->assertDontSee('Client Special');
        $this->get('/register')->assertOk()->assertDontSee('Client Special');
    }

    public function test_nobody_can_sign_up_on_a_custom_plan_by_typing_its_slug(): void
    {
        $this->customFor($this->client);

        $this->post('/register', [
            'business_name' => 'Sneaky', 'name' => 'S', 'email' => 's@client.test', 'mobile' => '9876543210',
            'password' => 'Secret123', 'password_confirmation' => 'Secret123',
            'plan' => 'client-special', 'period' => 'monthly', 'terms' => '1',
        ])->assertSessionHasErrors('plan');

        $this->assertFalse(Tenant::query()->where('name', 'Sneaky')->exists());
    }

    public function test_a_company_being_created_is_not_offered_somebody_elses_plan(): void
    {
        $custom = $this->customFor($this->client);

        $this->actingAs($this->admin())->get('/superadmin/tenants/create')->assertOk()->assertDontSee('Client Special');
        $this->actingAs($this->admin())->postJson('/superadmin/tenants', [
            'name' => 'New Co', 'code' => 'NEWCO', 'plan_id' => $custom->id,
        ])->assertStatus(422)->assertJsonValidationErrors('plan_id');
    }

    /* ---------------------------------------------------- only for its client */

    public function test_the_admin_assigns_it_to_its_client_and_nobody_else(): void
    {
        $custom = $this->customFor($this->client);

        $this->actingAs($this->admin())->get(route('admin.subscriptions.assign', $this->client))
            ->assertOk()->assertSee('Client Special');
        $this->actingAs($this->admin())->get(route('admin.subscriptions.assign', $this->other))
            ->assertOk()->assertDontSee('Client Special');

        $this->actingAs($this->admin())->postJson(route('admin.subscriptions.store', $this->other), [
            'plan_id' => $custom->id, 'billing_period' => 'monthly',
        ])->assertStatus(422)->assertJsonValidationErrors('plan_id');

        $this->actingAs($this->admin())->postJson(route('admin.subscriptions.store', $this->client), [
            'plan_id' => $custom->id, 'billing_period' => 'monthly',
        ])->assertOk();

        $this->assertSame($custom->id, Subscription::query()->where('tenant_id', $this->client->id)->latest('id')->value('plan_id'));
    }

    public function test_a_custom_plan_shows_on_its_clients_billing_screen_only(): void
    {
        $custom = $this->customFor($this->client);
        $this->on($custom);

        $this->actingAs($this->owner())->get('/superadmin/subscription')->assertOk()
            ->assertSee('Client Special')->assertSee('Starter');

        $rivals = $this->customFor($this->other, ['name' => 'Their Secret', 'code' => 'theirs', 'slug' => 'theirs']);
        $this->actingAs($this->owner())->get('/superadmin/subscription')->assertOk()->assertDontSee('Their Secret');

        $this->expectException(RuntimeException::class);
        app(SubscriptionBillingService::class)->quote($this->on($custom), $rivals, 'monthly');
    }

    public function test_a_client_can_move_to_a_plan_made_for_them_online(): void
    {
        $custom = $this->customFor($this->client);
        $sub = $this->on(Plan::query()->where('code', 'starter')->firstOrFail());

        $this->assertSame(1500.0, app(SubscriptionBillingService::class)->quote($sub, $custom, 'monthly')['list_price']);
    }

    /* ------------------------------------------------- renews like any other */

    public function test_a_custom_plan_renews_online_like_a_general_one(): void
    {
        Http::fake(['api.razorpay.com/v1/orders' => Http::response(['id' => 'order_CUSTOM'])]);
        $custom = $this->customFor($this->client);
        $sub = $this->on($custom);

        $billing = app(SubscriptionBillingService::class);
        $quote = $billing->quote($sub, $custom, 'monthly');
        $this->assertTrue($quote['renewal']);

        $order = $billing->checkout($sub, $custom, 'monthly');
        $billing->confirmFromBrowser($order, [
            'razorpay_order_id' => 'order_CUSTOM', 'razorpay_payment_id' => 'pay_1',
            'razorpay_signature' => hash_hmac('sha256', 'order_CUSTOM|pay_1', 'sek'),
        ]);

        $this->assertEqualsWithDelta(now()->addMonths(2)->timestamp, $sub->fresh()->ends_at->timestamp, 3 * 86400);
    }

    public function test_a_client_on_a_plan_taken_off_sale_can_still_renew_it(): void
    {
        $withdrawn = $this->plan(['is_active' => false]);

        $this->assertTrue(app(SubscriptionBillingService::class)->quote($this->on($withdrawn), $withdrawn, 'monthly')['renewal']);
    }

    public function test_a_lapsed_client_can_ask_for_their_own_plan_back(): void
    {
        config(['payments.platform.key' => null, 'payments.platform.secret' => null]);
        $custom = $this->customFor($this->client);
        $this->on($custom, ['ends_at' => now()->subDay()]);

        $this->actingAs($this->owner())->postJson('/superadmin/subscription/renewal-request', [
            'plan_id' => $custom->id, 'period' => 'monthly',
        ])->assertOk();
    }

    public function test_the_agreed_price_is_what_a_renewal_charges(): void
    {
        $plan = Plan::query()->where('code', 'restaurant')->firstOrFail();

        $quote = app(SubscriptionBillingService::class)->quote($this->on($plan, ['price' => 900]), $plan, 'monthly');

        $this->assertSame(900.0, $quote['list_price']);
        $this->assertSame(900.0, $quote['amount']);
    }

    public function test_moving_to_another_plan_is_at_that_plans_price(): void
    {
        $sub = $this->on(Plan::query()->where('code', 'starter')->firstOrFail(), ['price' => 100]);

        $this->assertSame(1999.0, app(SubscriptionBillingService::class)
            ->quote($sub, Plan::query()->where('code', 'restaurant')->firstOrFail(), 'monthly')['list_price']);
    }

    /* ------------------------------------------------ making one from the screen */

    public function test_the_admin_makes_a_custom_plan_for_a_client(): void
    {
        $this->actingAs($this->admin())->postJson(route('admin.plans.store'), $this->form(['client_id' => $this->client->id]))
            ->assertOk();

        $plan = Plan::query()->where('code', 'made-for-them')->firstOrFail();
        $this->assertTrue($plan->isCustom());
        $this->assertSame($this->client->id, $plan->tenant_id);

        $this->actingAs($this->admin())->get(route('admin.plans.create'))->assertOk()->assertSee('name="client_id"', false);
        $this->actingAs($this->admin())->get(route('admin.plans.index'))->assertOk()
            ->assertSee('Custom')->assertSee($this->client->name);
    }

    public function test_a_plan_with_subscribers_cannot_change_who_it_is_for(): void
    {
        $general = $this->plan();
        $this->on($general);

        $this->actingAs($this->admin())->putJson(route('admin.plans.update', $general), $this->form([
            'code' => 'client-special', 'client_id' => $this->other->id,
        ]))->assertStatus(422)->assertJsonValidationErrors('client_id');

        $this->assertNull($general->fresh()->tenant_id);
    }

    /* ---------------------------------------------------- modules are chosen */

    public function test_no_plan_is_given_a_module_by_itself(): void
    {
        foreach (Plan::query()->get() as $plan) {
            $this->assertIsArray($plan->modules, $plan->code.' names its modules');
        }

        // A module that arrives in a later release.
        config(['modules.loyalty_cards' => ['label' => 'Loyalty cards', 'permissions' => []]]);

        foreach (Plan::query()->get() as $plan) {
            $this->assertFalse($plan->grants('loyalty_cards'), $plan->code);
        }

        $this->on(Plan::query()->where('code', 'chain')->firstOrFail());
        $this->assertNotContains('loyalty_cards', PlanAccess::modulesFor($this->client->id));
    }

    public function test_select_all_means_todays_modules_named_one_by_one(): void
    {
        $this->actingAs($this->admin())->postJson(route('admin.plans.store'), $this->form(['modules_all' => 1]))->assertOk();

        $this->assertSame(Modules::keys(), Plan::query()->where('code', 'made-for-them')->firstOrFail()->modules);
    }

    public function test_the_matrix_shows_every_module_against_every_plan_and_flags_a_new_one(): void
    {
        config(['modules.loyalty_cards' => ['label' => 'Loyalty cards', 'permissions' => []]]);
        $this->customFor($this->client);

        $this->actingAs($this->admin())->get(route('admin.plans.modules'))->assertOk()
            ->assertSee('Starter')->assertSee('Chain')->assertSee('Client Special')
            ->assertSee('Loyalty cards')->assertSee('Not in any plan');
    }

    public function test_the_matrix_decides_which_plans_and_clients_get_a_module(): void
    {
        config(['modules.loyalty_cards' => ['label' => 'Loyalty cards', 'permissions' => []]]);
        $starter = Plan::query()->where('code', 'starter')->firstOrFail();
        $chain = Plan::query()->where('code', 'chain')->firstOrFail();
        $custom = $this->customFor($this->client);

        $this->actingAs($this->admin())->putJson(route('admin.plans.modules.update'), ['grants' => [
            $starter->id => $starter->moduleKeys(),
            $chain->id => [...$chain->moduleKeys(), 'loyalty_cards'],
            $custom->id => ['pos', 'loyalty_cards'],
        ]])->assertOk();

        $this->assertFalse($starter->fresh()->grants('loyalty_cards'));
        $this->assertTrue($chain->fresh()->grants('loyalty_cards'));
        $this->assertSame(['pos', 'loyalty_cards'], array_values(array_intersect(['pos', 'loyalty_cards'], $custom->fresh()->moduleKeys())));
    }

    public function test_only_the_platform_reaches_the_matrix(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->get(route('admin.plans.modules'))->assertForbidden();
        $this->actingAs($owner)->putJson(route('admin.plans.modules.update'), ['grants' => []])->assertForbidden();
    }

    public function test_a_module_given_to_a_plan_reaches_outlets_that_named_their_modules(): void
    {
        $plan = $this->plan(['modules' => ['pos', 'retail']]);
        $this->on($plan);
        $this->shop->forceFill(['modules' => ['pos', 'retail']])->saveQuietly();

        $this->actingAs($this->admin())->putJson(route('admin.plans.update', $plan), $this->form([
            'code' => 'client-special', 'modules' => ['pos', 'retail', 'kitchen'],
        ]))->assertOk();

        $this->assertContains('kitchen', $this->shop->fresh()->modules);
        $this->resetState();
        $this->assertContains('kitchen', Modules::forShop($this->shop->fresh()));
    }

    public function test_an_outlet_on_another_plan_is_not_touched(): void
    {
        $plan = $this->plan();
        $this->on(Plan::query()->where('code', 'starter')->firstOrFail());
        $this->shop->forceFill(['modules' => ['pos']])->saveQuietly();

        $this->actingAs($this->admin())->putJson(route('admin.plans.update', $plan), $this->form([
            'code' => 'client-special', 'modules' => ['pos', 'kitchen'],
        ]))->assertOk();

        $this->assertSame(['pos'], $this->shop->fresh()->modules);
    }

    public function test_an_update_that_does_not_mention_modules_leaves_them_alone(): void
    {
        $plan = $this->plan();
        $this->on($plan);

        $data = $this->form(['code' => 'client-special', 'monthly_price' => 1600]);
        unset($data['modules']);

        $this->actingAs($this->admin())->putJson(route('admin.plans.update', $plan), $data)->assertOk();

        $this->assertEqualsCanonicalizing(['pos', 'retail'], $plan->fresh()->modules);
    }

    /* ---------------------------------------------------- the other loose ends */

    public function test_putting_a_lapsed_client_on_a_plan_lets_them_back_in(): void
    {
        $this->on($this->plan(), ['ends_at' => now()->subDays(5)]);
        $this->client->suspend('Subscription expired on 1 Oct 2026');
        $this->resetState();

        $custom = $this->customFor($this->client, ['code' => 'second', 'slug' => 'second', 'trial_days' => 30]);

        $this->actingAs($this->admin())->postJson(route('admin.subscriptions.store', $this->client), [
            'plan_id' => $custom->id, 'billing_period' => 'monthly', 'trial_days' => 30,
        ])->assertOk();

        $this->assertTrue($this->client->fresh()->is_active);
        $this->actingAs($this->owner())->get('/superadmin/dashboard')->assertOk();
    }

    public function test_a_new_plan_does_not_lift_a_suspension_a_person_made(): void
    {
        $this->on($this->plan(), ['ends_at' => now()->subDays(5)]);
        $this->client->suspend('Chargeback dispute');
        $this->resetState();

        $this->actingAs($this->admin())->postJson(route('admin.subscriptions.store', $this->client), [
            'plan_id' => Plan::query()->where('code', 'chain')->value('id'), 'billing_period' => 'monthly', 'trial_days' => 30,
        ])->assertOk();

        $this->assertFalse($this->client->fresh()->is_active);
    }

    public function test_an_unpaid_plan_does_not_suspend_a_business_at_the_moment_it_is_assigned(): void
    {
        app(SubscriptionService::class)->subscribe($this->client, $this->plan(), Plan::MONTHLY, 0);

        $this->assertTrue($this->client->fresh()->is_active);
    }

    public function test_a_free_plan_does_not_lapse(): void
    {
        $free = $this->plan(['monthly_price' => 0]);

        $sub = app(SubscriptionService::class)->subscribe($this->client, $free, Plan::MONTHLY, 0);

        $this->assertNull($sub->ends_at);
        $this->assertTrue($sub->isUsable());
    }

    public function test_a_deleted_plans_code_can_be_used_again(): void
    {
        $old = $this->plan();

        $this->actingAs($this->admin())->deleteJson(route('admin.plans.destroy', $old))->assertOk();
        $this->actingAs($this->admin())->postJson(route('admin.plans.store'), $this->form(['code' => 'client-special']))->assertOk();
    }

    public function test_a_plan_is_priced_in_the_currency_payment_is_taken_in(): void
    {
        $this->actingAs($this->admin())->postJson(route('admin.plans.store'), $this->form(['currency' => 'USD']))
            ->assertStatus(422)->assertJsonValidationErrors('currency');
    }

    public function test_a_paid_plan_cannot_be_given_away_for_a_year_by_a_zero(): void
    {
        $this->actingAs($this->admin())->postJson(route('admin.plans.store'), $this->form(['yearly_price' => 0]))
            ->assertStatus(422)->assertJsonValidationErrors('yearly_price');
    }

    public function test_a_short_payment_is_recorded_without_buying_a_term_unless_the_admin_says_so(): void
    {
        $sub = $this->on($this->plan(), ['ends_at' => now()->subDay()]);
        $ended = $sub->ends_at->timestamp;

        $this->actingAs($this->admin())->postJson(route('admin.subscriptions.payment.store', $this->client), [
            'amount' => 100, 'method' => SubscriptionPayment::MANUAL,
        ])->assertStatus(422)->assertJsonValidationErrors('amount');

        $this->assertSame($ended, $sub->fresh()->ends_at->timestamp);

        $this->actingAs($this->admin())->postJson(route('admin.subscriptions.payment.store', $this->client), [
            'amount' => 100, 'method' => SubscriptionPayment::MANUAL, 'accept_as_full' => 1,
        ])->assertOk();

        $this->assertTrue($sub->fresh()->ends_at->isFuture());
    }

    public function test_a_deactivated_member_of_staff_does_not_hold_a_seat(): void
    {
        $this->on($this->plan(['max_users' => 1]));
        User::create(['tenant_id' => $this->client->id, 'name' => 'Left', 'email' => 'left@client.test', 'password' => 'Left-1234x', 'is_admin' => true, 'is_active' => false]);
        $this->resetState();

        $this->assertSame(1, PlanAccess::remainingUsers($this->client->id));
    }

    public function test_reactivating_somebody_needs_a_free_seat(): void
    {
        $this->on($this->plan(['max_users' => 1]));
        $owner = $this->owner();
        $left = User::create(['tenant_id' => $this->client->id, 'name' => 'Left', 'email' => 'left@client.test', 'password' => 'Left-1234x', 'is_admin' => true, 'is_active' => false]);
        $this->resetState();

        $this->actingAs($this->admin())->putJson(route('admin.users.status', $left))->assertStatus(422);
        $this->assertFalse($left->fresh()->is_active);

        unset($owner);
    }
}
