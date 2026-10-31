<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Shop;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\SubscriptionService;
use App\Support\CurrentShop;
use App\Support\CurrentTenant;
use App\Support\Modules;
use App\Support\PlanAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Self-service sign-up: the form, what it provisions, who it keeps out, and
 * what happens to the new owner when the trial ends.
 */
class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');
        Carbon::setTestNow('2026-10-01 12:00:00');

        $this->seed();
        $this->resetState();
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

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'business_name' => 'Spice Garden',
            'name' => 'Asha Rao',
            'email' => 'asha@spicegarden.test',
            'mobile' => '+91 98765 43210',
            'password' => 'Secret123',
            'password_confirmation' => 'Secret123',
            'plan' => 'restaurant',
            'period' => 'monthly',
            'terms' => '1',
        ], $overrides);
    }

    private function plan(string $slug): Plan
    {
        return Plan::query()->where('slug', $slug)->firstOrFail();
    }

    private function counts(): array
    {
        return [Tenant::count(), Shop::query()->withoutGlobalScopes()->count(), User::count(), Subscription::count()];
    }

    private function enableOnlineBilling(): void
    {
        config(['payments.platform.key' => 'rzp_test', 'payments.platform.secret' => 'sek']);
        app()->forgetInstance(\App\Services\Payments\GatewayManager::class);
    }

    /**
     * The owner who just signed up, with their email already confirmed: what
     * these tests are about comes after that step (see SignupEmailVerificationTest).
     */
    private function newOwner(): User
    {
        $owner = User::query()->where('email', 'asha@spicegarden.test')->firstOrFail();
        $owner->forceFill(['email_verified_at' => $owner->email_verified_at ?? now()])->saveQuietly();
        // The signed-in session re-reads the account, as the next real request would.
        Auth::forgetGuards();

        return $owner;
    }

    private function signOut(): void
    {
        Auth::guard('web')->logout();
        $this->flushSession();
        $this->resetState();
    }

    /* ------------------------------------------------------ the landing CTAs */

    public function test_every_plans_get_started_button_opens_the_register_form(): void
    {
        $page = $this->get('/')->assertOk();

        foreach (['starter', 'restaurant', 'chain'] as $slug) {
            $page->assertSee('href="'.route('register', ['plan' => $slug]).'"', false);
        }

        $page->assertDontSee('href="#contact"', false);
    }

    public function test_the_landing_page_advertises_a_seven_day_trial_on_every_plan(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertSame(3, substr_count($html, '7-day free trial'));
    }

    /* ----------------------------------------------------------- the form */

    public function test_the_form_offers_every_active_plan_and_preselects_the_requested_one(): void
    {
        $this->get('/register?plan=chain')
            ->assertOk()
            ->assertSee('value="starter"', false)
            ->assertSee('value="restaurant"', false)
            ->assertSee('value="chain" selected', false)
            ->assertDontSee('value="starter" selected', false);
    }

    public function test_an_unknown_plan_falls_back_to_the_popular_one(): void
    {
        $this->get('/register?plan=bogus')
            ->assertOk()
            ->assertSee('value="restaurant" selected', false);
    }

    public function test_a_plan_that_is_off_sale_is_not_offered(): void
    {
        $this->plan('chain')->forceFill(['is_active' => false])->save();

        $this->get('/register')->assertOk()->assertDontSee('value="chain"', false);
    }

    public function test_with_nothing_for_sale_there_is_no_signup_page(): void
    {
        Plan::query()->update(['is_active' => false]);

        $this->get('/register')->assertNotFound();
    }

    public function test_someone_already_signed_in_is_sent_to_their_dashboard(): void
    {
        $admin = User::query()->where('email', 'admin@erp.test')->firstOrFail();

        $this->actingAs($admin)->get('/register')->assertRedirect(route('admin.dashboard'));
    }

    public function test_the_form_is_one_card_with_dropdowns_and_the_india_code(): void
    {
        $this->get('/register')->assertOk()
            ->assertSee('<select id="r-plan" name="plan"', false)
            ->assertSee('<select id="r-period" name="period"', false)
            ->assertSee('₹1,999/month', false)
            ->assertSee('+91')
            ->assertSee('data-toggle-icon', false)
            ->assertSee('name="address_line1"', false)
            ->assertSee('data-get-location', false)
            ->assertSee('name="latitude"', false)
            ->assertSee('name="logo"', false)
            ->assertSee('Already have an account?')
            ->assertDontSee('type="radio"', false);
    }

    public function test_registration_saves_business_address_gps_coordinates_and_uploaded_logo(): void
    {
        Storage::fake('public');

        $this->post('/register', $this->payload([
            'address_line1' => '12 Market Road',
            'address_line2' => 'Near Central Park',
            'city' => 'Jaipur',
            'state' => 'Rajasthan',
            'pincode' => '302001',
            'latitude' => '26.9124000',
            'longitude' => '75.7873000',
            'logo' => UploadedFile::fake()->image('business.png')->size(256),
        ]))->assertRedirect(route('admin.dashboard'));

        $tenant = $this->newOwner()->tenant;
        $shop = Shop::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();

        $this->assertSame('12 Market Road', $tenant->address_line1);
        $this->assertSame('Near Central Park', $tenant->address_line2);
        $this->assertSame('Jaipur', $tenant->city);
        $this->assertSame('Rajasthan', $tenant->state);
        $this->assertSame('302001', $tenant->pincode);
        $this->assertEqualsWithDelta(26.9124, (float) $tenant->latitude, 0.0000001);
        $this->assertEqualsWithDelta(75.7873, (float) $tenant->longitude, 0.0000001);
        $this->assertNotNull($tenant->logo_path);
        Storage::disk('public')->assertExists($tenant->logo_path);

        $this->assertSame($tenant->address_line1, $shop->address_line1);
        $this->assertSame($tenant->city, $shop->city);
        $this->assertEqualsWithDelta((float) $tenant->latitude, (float) $shop->latitude, 0.0000001);
        $this->assertEqualsWithDelta((float) $tenant->longitude, (float) $shop->longitude, 0.0000001);
    }

    /** The form shows +91 beside the box; a bare ten-digit mobile is stored with it. */
    public function test_a_ten_digit_mobile_is_stored_with_the_india_code(): void
    {
        $this->post('/register', $this->payload(['mobile' => '9876543210']))
            ->assertRedirect(route('admin.dashboard'));

        $this->assertSame('+91 9876543210', $this->newOwner()->mobile);
    }

    /* -------------------------------------------------------- provisioning */

    public function test_signing_up_creates_a_business_ready_to_trade_and_signs_the_owner_in(): void
    {
        $this->post('/register', $this->payload())
            ->assertRedirect(route('admin.dashboard'))
            ->assertSessionHas('status');

        $owner = $this->newOwner();
        $tenant = $owner->tenant;
        $shop = Shop::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();

        $this->assertSame('Spice Garden', $tenant->name);
        $this->assertTrue($tenant->is_active);
        $this->assertSame($tenant->id, $shop->tenant_id);
        $this->assertTrue($shop->is_active);

        $this->assertTrue($owner->hasRole('Tenant Owner'));
        $this->assertFalse($owner->isSuperAdmin());
        $this->assertTrue($owner->is_admin);
        $this->assertSame($shop->id, $owner->current_shop_id);
        $this->assertTrue($owner->shops()->whereKey($shop->id)->exists());

        $this->assertNotNull(Warehouse::allShops()->where('shop_id', $shop->id)->first());

        $this->assertAuthenticatedAs($owner);
        $this->get('/superadmin/dashboard')->assertOk();
    }

    public function test_the_trial_is_exactly_seven_days_with_no_grace_and_full_price_after(): void
    {
        $this->post('/register', $this->payload());

        $subscription = Subscription::query()->where('tenant_id', $this->newOwner()->tenant_id)->firstOrFail();

        $this->assertSame($this->plan('restaurant')->id, $subscription->plan_id);
        $this->assertNull($subscription->shop_id);
        $this->assertSame(Subscription::TRIALING, $subscription->state());
        $this->assertTrue($subscription->trial_ends_at->equalTo(now()->addDays(7)));
        $this->assertTrue($subscription->ends_at->equalTo(now()->addDays(7)));
        $this->assertSame(0, $subscription->grace_days);
        $this->assertSame('1999.00', $subscription->price);
        $this->assertSame('monthly', $subscription->billing_period);
    }

    public function test_the_chosen_plan_period_and_trial_length_are_honoured(): void
    {
        $this->plan('starter')->forceFill(['trial_days' => 10])->save();

        $this->post('/register', $this->payload(['plan' => 'starter', 'period' => 'yearly']));

        $subscription = Subscription::query()->where('tenant_id', $this->newOwner()->tenant_id)->firstOrFail();

        $this->assertSame($this->plan('starter')->id, $subscription->plan_id);
        $this->assertSame('yearly', $subscription->billing_period);
        $this->assertSame('7990.00', $subscription->price);
        $this->assertTrue($subscription->ends_at->equalTo(now()->addDays(10)));
    }

    public function test_a_new_account_sees_only_its_own_company(): void
    {
        $originalShopId = Shop::query()->withoutGlobalScopes()->orderBy('id')->value('id');
        $this->post('/register', $this->payload());

        $owner = $this->newOwner();
        $this->resetState();

        $this->actingAs($owner);

        $this->assertSame($owner->tenant_id, CurrentTenant::id());
        $this->assertSame([$owner->current_shop_id], CurrentShop::accessibleIds());
        $this->assertNotContains($originalShopId, CurrentShop::accessibleIds());
    }

    public function test_the_email_is_stored_in_lower_case(): void
    {
        $this->post('/register', $this->payload(['email' => '  Asha@SpiceGarden.TEST ']))
            ->assertRedirect(route('admin.dashboard'));

        $this->assertSame('asha@spicegarden.test', $this->newOwner()->email);
    }

    public function test_two_businesses_with_the_same_name_get_distinct_codes_and_slugs(): void
    {
        $this->post('/register', $this->payload());
        $this->signOut();
        $this->post('/register', $this->payload(['email' => 'second@spicegarden.test']));

        $tenants = Tenant::query()->where('name', 'Spice Garden')->get();

        $this->assertCount(2, $tenants);
        $this->assertCount(2, $tenants->pluck('code')->unique());
        $this->assertCount(2, $tenants->pluck('slug')->unique());
    }

    /* ----------------------------------------------------------- no trial */

    public function test_a_plan_with_no_trial_cannot_be_started_without_online_payment(): void
    {
        $this->plan('starter')->forceFill(['trial_days' => 0])->save();
        $before = $this->counts();

        $this->from('/register')
            ->post('/register', $this->payload(['plan' => 'starter']))
            ->assertRedirect('/register')
            ->assertSessionHasErrors('plan');

        $this->assertSame($before, $this->counts());
        $this->assertGuest();
    }

    public function test_a_plan_with_no_trial_lands_the_owner_on_the_billing_screen(): void
    {
        $this->enableOnlineBilling();
        $this->plan('starter')->forceFill(['trial_days' => 0])->save();

        $this->post('/register', $this->payload(['plan' => 'starter']))
            ->assertRedirect(route('admin.subscription.mine'));

        $subscription = Subscription::query()->where('tenant_id', $this->newOwner()->tenant_id)->firstOrFail();

        $this->assertFalse($subscription->isUsable());
        $this->assertNotNull($subscription->ends_at);

        $this->resetState();
        $this->get('/superadmin/subscription')->assertOk();
        $this->get('/superadmin/dashboard')->assertRedirect(route('admin.subscription.mine'));
    }

    /* ---------------------------------------------------------- validation */

    public function test_bad_input_is_refused_and_creates_nothing(): void
    {
        $before = $this->counts();

        $cases = [
            'no business name' => [['business_name' => ''], 'business_name'],
            'no name' => [['name' => ''], 'name'],
            'bad email' => [['email' => 'not-an-email'], 'email'],
            'email already used' => [['email' => 'admin@erp.test'], 'email'],
            'email used in other case' => [['email' => 'ADMIN@erp.test'], 'email'],
            'bad mobile' => [['mobile' => 'abc'], 'mobile'],
            'short password' => [['password' => 'a1', 'password_confirmation' => 'a1'], 'password'],
            'password without a number' => [['password' => 'onlyletters', 'password_confirmation' => 'onlyletters'], 'password'],
            'passwords differ' => [['password_confirmation' => 'Different123'], 'password'],
            'terms not accepted' => [['terms' => ''], 'terms'],
            'unknown plan' => [['plan' => 'platinum'], 'plan'],
            'bad period' => [['period' => 'weekly'], 'period'],
        ];

        foreach ($cases as $label => [$override, $field]) {
            $this->from('/register')
                ->post('/register', $this->payload($override))
                ->assertSessionHasErrors($field, null, 'default');

            $this->assertSame($before, $this->counts(), $label.' created records');
            $this->assertGuest();
        }
    }

    public function test_a_plan_that_went_off_sale_cannot_be_chosen(): void
    {
        $this->plan('chain')->forceFill(['is_active' => false])->save();
        $before = $this->counts();

        $this->from('/register')
            ->post('/register', $this->payload(['plan' => 'chain']))
            ->assertSessionHasErrors('plan');

        $this->assertSame($before, $this->counts());
    }

    public function test_the_password_is_never_sent_back_in_the_form(): void
    {
        $this->from('/register')
            ->post('/register', $this->payload(['email' => 'bad']));

        $this->get('/register')
            ->assertOk()
            ->assertDontSee('Secret123');
    }

    /* ------------------------------------------------------------- abuse */

    public function test_the_honeypot_creates_nothing(): void
    {
        $before = $this->counts();

        $this->post('/register', $this->payload(['website' => 'http://spam.test']))
            ->assertRedirect(route('landing'));

        $this->assertSame($before, $this->counts());
        $this->assertGuest();
    }

    public function test_one_address_cannot_mint_unlimited_accounts(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->post('/register', $this->payload(['email' => "owner{$i}@spicegarden.test"]))
                ->assertRedirect(route('admin.dashboard'));
            $this->signOut();
        }

        $before = $this->counts();

        $this->from('/register')
            ->post('/register', $this->payload(['email' => 'owner6@spicegarden.test']))
            ->assertSessionHasErrors('email');

        $this->assertSame($before, $this->counts());
    }

    public function test_typos_do_not_count_against_the_limit(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $this->from('/register')->post('/register', $this->payload(['email' => 'nope']));
        }

        $this->post('/register', $this->payload())->assertRedirect(route('admin.dashboard'));
    }

    /* ----------------------------------------------------------- atomicity */

    public function test_without_the_owner_role_nothing_is_created(): void
    {
        Role::query()->where('name', 'Tenant Owner')->delete();
        $before = $this->counts();

        $this->from('/register')
            ->post('/register', $this->payload())
            ->assertSessionHasErrors('email');

        $this->assertSame($before, $this->counts());
        $this->assertGuest();
    }

    public function test_a_failure_part_way_leaves_no_trace(): void
    {
        $this->mock(SubscriptionService::class, function ($mock) {
            $mock->shouldReceive('subscribe')->andThrow(new RuntimeException('boom'));
        });
        $before = $this->counts();

        $this->from('/register')
            ->post('/register', $this->payload())
            ->assertSessionHasErrors('email');

        $this->assertSame($before, $this->counts());
        $this->assertGuest();
    }

    /* ------------------------------------------- a lapsed account, and paying */

    private function ownerOfLapsedAccount(): User
    {
        $this->post('/register', $this->payload());

        $owner = $this->newOwner();

        // Eight days on: the seven-day trial is over.
        Carbon::setTestNow(now()->addDays(8));
        $this->resetState();

        return $owner;
    }

    public function test_an_owner_who_can_pay_online_is_kept_in_and_shown_the_billing_screen(): void
    {
        $this->enableOnlineBilling();
        $owner = $this->ownerOfLapsedAccount();

        $this->get('/superadmin/dashboard')
            ->assertRedirect(route('admin.subscription.mine'))
            ->assertSessionHas('error');

        $this->assertAuthenticatedAs($owner);

        $this->resetState();
        $this->get('/superadmin/subscription')->assertOk()->assertSee('Renew');

        $this->resetState();
        $this->getJson('/superadmin/dashboard')->assertForbidden();
    }

    public function test_a_lapsed_owner_can_still_sign_out(): void
    {
        $this->enableOnlineBilling();
        $this->ownerOfLapsedAccount();

        $this->post('/superadmin/logout')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_without_online_billing_the_owner_is_still_kept_in_on_the_billing_screen(): void
    {
        // Signing them out left nowhere to renew from; see LapsedAccountAccessTest.
        $owner = $this->ownerOfLapsedAccount();

        $this->get('/superadmin/dashboard')
            ->assertRedirect(route('admin.subscription.mine'))
            ->assertSessionHas('error');

        $this->assertAuthenticatedAs($owner);
    }

    public function test_staff_who_cannot_pay_are_signed_out_even_when_online_billing_works(): void
    {
        $this->enableOnlineBilling();
        $owner = $this->ownerOfLapsedAccount();

        $cashier = User::create([
            'tenant_id' => $owner->tenant_id,
            'name' => 'Counter Staff',
            'email' => 'counter@spicegarden.test',
            'password' => 'Counter123',
            'is_admin' => true,
            'current_shop_id' => $owner->current_shop_id,
        ]);
        $cashier->shops()->attach($owner->current_shop_id, ['is_default' => true]);
        $cashier->givePermissionTo('dashboard.overview.view');

        $this->signOut();
        $this->actingAs($cashier)->get('/superadmin/dashboard')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_a_company_suspended_by_a_person_signs_everyone_out_owners_included(): void
    {
        $this->enableOnlineBilling();
        $this->post('/register', $this->payload());
        $owner = $this->newOwner();
        $owner->tenant->suspend('Chargeback dispute under review');
        $this->resetState();

        $this->get('/superadmin/subscription')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_a_company_paused_for_billing_can_pay_its_way_back_in(): void
    {
        $this->enableOnlineBilling();
        $owner = $this->ownerOfLapsedAccount();
        $owner->tenant->suspend('Subscription expired on 8 Oct 2026');
        $this->resetState();

        $this->get('/superadmin/dashboard')->assertRedirect(route('admin.subscription.mine'));
        $this->resetState();
        $this->get('/superadmin/subscription')->assertOk();

        // The payment the provider confirmed: records it, extends the term
        // and lifts the pause.
        $subscription = Subscription::query()->where('tenant_id', $owner->tenant_id)->firstOrFail();
        app(SubscriptionService::class)->recordPayment($subscription, 1999, 'razorpay', 'pay_after_trial');

        $this->resetState();
        $this->assertTrue($owner->tenant->fresh()->is_active);
        $this->get('/superadmin/dashboard')->assertOk();
    }
}
