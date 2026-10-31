<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\Plan;
use App\Models\SupportTicket;
use App\Models\User;
use App\Support\CurrentShop;
use App\Support\CurrentTenant;
use App\Support\Modules;
use App\Support\PlanAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * What happens the day after a trial ends, with or without online payment.
 *
 * The owner is never shut out of the one screen that lets them carry on:
 * they are kept signed in, shown the plans, and can pay online or - when the
 * platform takes no payment online - ask for a renewal from right there.
 * Everybody else is turned away at the sign-in form itself, with a reason,
 * rather than being let in and thrown out on the next page.
 */
class LapsedAccountAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');
        $this->seed();
        // The default for this install: no platform Razorpay keys.
        config(['payments.platform.key' => null, 'payments.platform.secret' => null]);
        app()->forgetInstance(\App\Services\Payments\GatewayManager::class);
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
        Auth::forgetGuards();
    }

    private function signOut(): void
    {
        Auth::guard('web')->logout();
        $this->flushSession();
        $this->resetState();
    }

    /** A business that signed up, and whose seven-day trial ended yesterday. */
    private function lapsedOwner(): User
    {
        $this->post('/register', [
            'business_name' => 'Spice Garden', 'name' => 'Asha Rao', 'email' => 'asha@spicegarden.test',
            'mobile' => '+91 98765 43210', 'password' => 'Secret123', 'password_confirmation' => 'Secret123',
            'plan' => 'restaurant', 'period' => 'monthly', 'terms' => '1',
        ])->assertRedirect();

        // Confirmed: what is tested here comes after that step (see SignupEmailVerificationTest).
        User::query()->where('email', 'asha@spicegarden.test')->update(['email_verified_at' => now()]);

        Carbon::setTestNow(now()->addDays(8));
        $this->resetState();

        return User::query()->where('email', 'asha@spicegarden.test')->firstOrFail();
    }

    private function cashierOf(User $owner): User
    {
        $cashier = User::create([
            'tenant_id' => $owner->tenant_id, 'name' => 'Counter Staff', 'email' => 'counter@spicegarden.test',
            'password' => 'Counter123', 'is_admin' => true, 'is_active' => true, 'current_shop_id' => $owner->current_shop_id,
        ]);
        $cashier->shops()->attach($owner->current_shop_id, ['is_default' => true]);
        $cashier->givePermissionTo('dashboard.overview.view');

        return $cashier;
    }

    /* ------------------------------------------- the owner, without online billing */

    public function test_the_owner_is_kept_in_and_shown_the_plans_even_without_online_billing(): void
    {
        $owner = $this->lapsedOwner();

        $this->get('/superadmin/dashboard')
            ->assertRedirect(route('admin.subscription.mine'))
            ->assertSessionHas('error');
        $this->assertAuthenticatedAs($owner);

        $this->resetState();
        $this->get('/superadmin/subscription')->assertOk()
            ->assertSee('Request renewal')
            ->assertSee('Restaurant');

        // Nothing else opens.
        $this->resetState();
        $this->getJson('/superadmin/pos')->assertForbidden();
        $this->resetState();
        $this->get('/superadmin/products')->assertRedirect(route('admin.subscription.mine'));
    }

    public function test_signing_in_after_the_trial_lands_the_owner_on_the_plans(): void
    {
        $owner = $this->lapsedOwner();
        $this->signOut();

        $this->post('/login', ['email' => $owner->email, 'password' => 'Secret123'])->assertRedirect();
        $this->assertAuthenticatedAs($owner);

        $this->resetState();
        $this->get('/superadmin/dashboard')->assertRedirect(route('admin.subscription.mine'));
    }

    public function test_the_owner_can_ask_for_a_renewal_from_the_billing_screen(): void
    {
        $owner = $this->lapsedOwner();
        $plan = Plan::query()->where('slug', 'chain')->firstOrFail();

        $this->postJson('/superadmin/subscription/renewal-request', ['plan_id' => $plan->id, 'period' => 'yearly'])
            ->assertOk()
            ->assertJsonPath('success', true);

        $ticket = SupportTicket::query()->withoutGlobalScopes()->where('tenant_id', $owner->tenant_id)->sole();
        $this->assertSame('billing', $ticket->category);
        $this->assertStringContainsString('Chain', $ticket->subject);
        $this->assertStringContainsString('yearly', $ticket->body);

        // The platform hears about it on its bell.
        $this->assertTrue(Alert::query()->withoutGlobalScopes()->where('type', Alert::RENEWAL_REQUEST)->exists());

        // Asking twice does not open a second ticket.
        $this->resetState();
        $this->postJson('/superadmin/subscription/renewal-request', ['plan_id' => $plan->id, 'period' => 'yearly'])
            ->assertOk();
        $this->assertSame(1, SupportTicket::query()->withoutGlobalScopes()->where('tenant_id', $owner->tenant_id)->count());
    }

    public function test_a_renewal_request_needs_the_pay_right_and_a_real_plan(): void
    {
        $owner = $this->lapsedOwner();

        $this->postJson('/superadmin/subscription/renewal-request', ['plan_id' => 999999, 'period' => 'monthly'])
            ->assertStatus(422);

        $owner->revokePermissionTo('settings.subscriptions.pay');
        $owner->roles->each(fn ($role) => $owner->removeRole($role));
        $owner->givePermissionTo(['dashboard.overview.view', 'settings.subscriptions.view']);
        $this->resetState();

        $this->postJson('/superadmin/subscription/renewal-request', ['plan_id' => Plan::query()->value('id'), 'period' => 'monthly'])
            ->assertForbidden();
        $this->assertSame(0, SupportTicket::query()->withoutGlobalScopes()->count());
    }

    /* ---------------------------------------------------- everybody else */

    public function test_staff_who_cannot_pay_are_refused_at_the_sign_in_form_with_a_reason(): void
    {
        $owner = $this->lapsedOwner();
        $cashier = $this->cashierOf($owner);
        $this->signOut();

        $this->from('/login')
            ->post('/login', ['email' => $cashier->email, 'password' => 'Counter123'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email' => 'The subscription for Spice Garden has ended. Please ask your account owner to renew it.']);

        $this->assertGuest();
    }

    public function test_staff_already_signed_in_are_signed_out_with_the_same_reason(): void
    {
        $owner = $this->lapsedOwner();
        $cashier = $this->cashierOf($owner);
        $this->signOut();

        $this->actingAs($cashier)->get('/superadmin/dashboard')
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', 'The subscription for Spice Garden has ended. Please ask your account owner to renew it.');
        $this->assertGuest();
    }

    public function test_a_company_suspended_by_a_person_is_refused_at_the_sign_in_form(): void
    {
        $owner = $this->lapsedOwner();
        Carbon::setTestNow();
        $owner->tenant->suspend('Chargeback dispute');
        $this->signOut();

        $this->from('/login')
            ->post('/login', ['email' => $owner->email, 'password' => 'Secret123'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_a_json_sign_in_that_is_refused_says_why(): void
    {
        $owner = $this->lapsedOwner();
        $cashier = $this->cashierOf($owner);
        $this->signOut();

        $this->postJson('/login', ['email' => $cashier->email, 'password' => 'Counter123'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
        $this->assertGuest();
    }

    /* ---------------------------------------------------------- cancelling */

    public function test_a_subscription_that_has_already_ended_cannot_be_cancelled(): void
    {
        $this->lapsedOwner();

        $this->putJson('/superadmin/subscription/cancel')
            ->assertStatus(422)
            ->assertJsonPath('message', 'This subscription has already ended, so there is nothing to cancel.');
    }

    /* ------------------------------------------------- two rules at once */

    public function test_a_lapsed_owner_with_a_well_known_password_is_not_bounced_in_a_loop(): void
    {
        config(['app.force_default_password_change' => true]);
        $this->lapsedOwner();
        $this->withSession(['password_is_weak' => true]);

        // The password rule sends them to their profile; the billing rule must let that page open.
        $this->get('/superadmin/subscription')->assertRedirect(route('admin.profile.edit'));
        $this->resetState();
        $this->get('/superadmin/profile')->assertOk();
    }
}
