<?php

namespace Tests\Feature;

use App\Models\License;
use App\Models\LicenseLog;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\LicenseService;
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
 * One-time licence registration, with the Super Admin in control.
 *
 * Covers the whole journey: sign-up, the first sign-in that lands on the
 * licence page, the wrong key, the right key waiting for approval, approval,
 * a second sign-in that does not ask again, a key that is somebody else's, a
 * key used twice, expiry, block, revoke, reset, renew, plan changes - and
 * that nothing a customer sends can change a status, a plan or a date.
 */
class LicenseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');
        $this->seed();
        config(['license.enforce' => true]);
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
        // actingAs() points the default guard at the platform's; a customer signs in on the default.
        Auth::shouldUse((string) config('auth.defaults.guard'));
    }

    /** A customer who has signed up (not signed in yet). */
    private function register(string $name = 'Spice Garden', string $email = 'asha@spicegarden.test'): User
    {
        $this->post('/register', [
            'business_name' => $name, 'name' => 'Asha Rao', 'email' => $email,
            'mobile' => '+91 98765 43210', 'password' => 'Secret123', 'password_confirmation' => 'Secret123',
            'plan' => 'restaurant', 'period' => 'monthly', 'terms' => '1',
        ])->assertRedirect();

        User::query()->where('email', $email)->update(['email_verified_at' => now()]);
        $this->signOut();

        return User::query()->where('email', $email)->firstOrFail();
    }

    private function signIn(string $email = 'asha@spicegarden.test'): void
    {
        $this->signOut();
        $this->post('/login', ['email' => $email, 'password' => 'Secret123'])->assertRedirect();
        $this->resetState();
    }

    private function superAdmin(): User
    {
        return User::query()->withoutGlobalScopes()->where('email', 'admin@erp.test')->firstOrFail();
    }

    /** Act as the Super Admin for the next calls. */
    private function asSuperAdmin(): void
    {
        $this->signOut();
        $this->actingAs($this->superAdmin());
    }

    /** A key nobody is assigned to yet (the first customer to type it gets it). @return array{0: License, 1: string} */
    private function issue(?Tenant $for = null, int $days = 365): array
    {
        $this->asSuperAdmin();

        return app(LicenseService::class)->generate([
            'plan_id' => Plan::query()->where('code', 'restaurant')->firstOrFail()->id,
            'validity_days' => $days,
            'tenant_id' => $for?->id,
        ], $this->superAdmin());
    }

    private function tenantOf(string $email): Tenant
    {
        return User::query()->withoutGlobalScopes()->where('email', $email)->firstOrFail()->tenant()->withoutGlobalScopes()->firstOrFail();
    }

    /** The license that was created for this customer when they registered. */
    private function registrationLicense(string $email = 'asha@spicegarden.test'): License
    {
        return License::query()->where('tenant_id', $this->tenantOf($email)->id)->latest('id')->firstOrFail();
    }

    /**
     * The Super Admin issues the company's key (shown once), for the customer to type.
     *
     * @return array{0: License, 1: string}
     */
    private function keyFor(string $email = 'asha@spicegarden.test'): array
    {
        $this->asSuperAdmin();
        $license = $this->registrationLicense($email);

        $response = $this->postJson(route('admin.licenses.act', [$license, 'rotate']))->assertOk();

        return [$license->fresh(), $response->json('data.key')];
    }

    /* ------------------------------------------------- registration & login */

    public function test_a_new_customer_registers_and_cannot_use_the_product_without_a_licence(): void
    {
        $this->register();
        $tenant = $this->tenantOf('asha@spicegarden.test');

        $this->assertFalse((bool) $tenant->license_exempt);

        // The license is created at registration for the company: not activated, nobody holds it yet.
        $license = $this->registrationLicense();
        $this->assertSame(License::ISSUED, $license->status);
        $this->assertSame($tenant->id, $license->tenant_id);
        $this->assertNull($license->holder_tenant_id);
        $this->assertSame('restaurant', $license->plan->code);

        $this->signIn();

        $this->get('/dashboard')->assertRedirect('/license');
        $this->get('/products')->assertRedirect('/license');
        $this->getJson('/dashboard')->assertStatus(403)->assertJsonPath('code', 'license_required');
    }

    public function test_the_first_login_lands_on_the_page_that_asks_for_the_key(): void
    {
        $this->register();
        $this->signIn();

        $this->get('/license')
            ->assertOk()
            ->assertSee('Activate your license')
            ->assertSee('license_key', false)
            ->assertSee('Nothing can be added');

        // And nothing else opens until it is entered and approved.
        $this->get('/dashboard')->assertRedirect('/license');
        $this->get('/products')->assertRedirect('/license');
        $this->postJson('/products', ['name' => 'Naan'])->assertStatus(403)->assertJsonPath('code', 'license_required');
    }

    public function test_an_invalid_key_is_refused_and_nothing_changes(): void
    {
        $this->register();
        $this->signIn();

        $this->postJson('/license/activate', ['license_key' => 'TSW-2026-NOPE-NOPE-NOP'])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame(License::ISSUED, $this->registrationLicense()->status);
        $this->get('/dashboard')->assertRedirect('/license');
    }

    public function test_a_valid_key_waits_for_the_super_admin_then_the_dashboard_opens(): void
    {
        $this->register();
        [$license, $key] = $this->keyFor();

        $this->signIn();

        $this->postJson('/license/activate', ['license_key' => $key])
            ->assertOk()
            ->assertJsonPath('data.state', 'pending');

        // Pending approval: still shut out, and told so.
        $this->get('/dashboard')->assertRedirect('/license');
        $this->get('/license')->assertOk()->assertSee('Waiting for approval');
        $this->getJson('/license/status')->assertJsonPath('data.state', 'pending');

        // The Super Admin approves.
        $this->asSuperAdmin();
        $this->postJson(route('admin.licenses.act', [$license, 'approve']))->assertOk();

        $license->refresh();
        $this->assertSame(License::ACTIVE, $license->status);
        $this->assertNotNull($license->activated_at);
        $this->assertNotNull($license->expires_at);

        // The plan and the term came from the licence, not from the customer.
        $sub = $this->tenantOf('asha@spicegarden.test')->subscription()->first();
        $this->assertSame($license->plan_id, $sub->plan_id);
        $this->assertTrue($sub->ends_at->isSameDay($license->expires_at));

        // ...and the next sign-in goes straight to the dashboard, without the licence page.
        $this->signIn();
        $this->get('/dashboard')->assertOk()->assertSee('Plan &amp; License', false);
    }

    public function test_the_second_login_never_asks_for_the_key_again(): void
    {
        $this->register();
        [$license, $key] = $this->keyFor();
        $this->signIn();
        $this->postJson('/license/activate', ['license_key' => $key])->assertOk();
        $this->asSuperAdmin();
        $this->postJson(route('admin.licenses.act', [$license, 'approve']))->assertOk();

        foreach ([1, 2] as $_) {
            $this->signIn();
            $this->get('/dashboard')->assertOk();
            $this->get('/license')->assertOk()->assertDontSee('Activate your license');
            $this->postJson('/license/activate', ['license_key' => $key])->assertStatus(422);
        }
    }

    /* ------------------------------------------------------ duplicates & owners */

    public function test_a_key_cannot_be_activated_twice(): void
    {
        $this->register('Spice Garden', 'asha@spicegarden.test');
        $this->register('Curry House', 'bala@curryhouse.test');
        $this->keyFor('asha@spicegarden.test');
        $this->keyFor('bala@curryhouse.test');
        [$license, $key] = $this->issue();

        $this->signIn('asha@spicegarden.test');
        $this->postJson('/license/activate', ['license_key' => $key])->assertOk();

        $this->signIn('bala@curryhouse.test');
        $this->postJson('/license/activate', ['license_key' => $key])->assertStatus(422);

        $this->assertSame($this->tenantOf('asha@spicegarden.test')->id, $license->fresh()->holder_tenant_id);
    }

    public function test_a_key_assigned_to_another_customer_is_refused(): void
    {
        $this->register('Spice Garden', 'asha@spicegarden.test');
        $this->register('Curry House', 'bala@curryhouse.test');
        $this->keyFor('asha@spicegarden.test');
        $this->keyFor('bala@curryhouse.test');
        [$license, $key] = $this->issue($this->tenantOf('asha@spicegarden.test'));

        $this->signIn('bala@curryhouse.test');
        $this->postJson('/license/activate', ['license_key' => $key])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame(License::ISSUED, $license->fresh()->status);

        // The one it was assigned to can.
        $this->signIn('asha@spicegarden.test');
        $this->postJson('/license/activate', ['license_key' => $key])->assertOk();
    }

    public function test_one_customer_cannot_hold_two_licences(): void
    {
        $this->register();
        [, $first] = $this->keyFor();
        [, $second] = $this->issue();

        $this->signIn();
        $this->postJson('/license/activate', ['license_key' => $first])->assertOk();
        $this->postJson('/license/activate', ['license_key' => $second])->assertStatus(422);
    }

    public function test_keys_are_stored_only_as_a_hash(): void
    {
        [$license, $key] = $this->issue();

        $this->assertMatchesRegularExpression('/^TSW-\d{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{3}$/', $key);
        $this->assertNotSame($key, $license->key_hash);
        $this->assertSame(LicenseService::hashKey($key), $license->key_hash);
        $this->assertStringNotContainsString(substr($key, 9, 9), $license->key_hint);
        $this->assertDatabaseMissing('licenses', ['key_hash' => $key]);

        // However it is typed.
        $this->assertSame($license->key_hash, LicenseService::hashKey(strtolower(str_replace('-', ' ', $key))));
    }

    public function test_keys_are_unique(): void
    {
        $keys = collect(range(1, 12))->map(fn () => $this->issue()[1]);

        $this->assertSame(12, $keys->unique()->count());
    }

    /* -------------------------------------------------------- customer powers */

    public function test_a_customer_cannot_touch_the_licence_desk_or_change_their_own_licence(): void
    {
        $this->register();
        $license = $this->approved();
        $this->signIn();

        // There is no licenses page to open at all; only the actions exist, and they are forbidden.
        $this->get('/licenses')->assertStatus(405);
        $this->postJson('/licenses', ['plan_id' => 1, 'validity_days' => 30])->assertForbidden();
        $this->postJson('/licenses/'.$license->id.'/approve')->assertForbidden();
        $this->postJson('/licenses/'.$license->id.'/extend', ['days' => 999])->assertForbidden();
        $this->postJson('/licenses/'.$license->id.'/revoke')->assertForbidden();

        // Nothing a customer sends changes a registered licence.
        $this->postJson('/license/activate', ['license_key' => 'TSW-2026-ABCD-EFGH-JKM', 'status' => 'revoked', 'plan_id' => 99, 'expires_at' => '2099-01-01'])
            ->assertStatus(422);

        $this->assertSame(License::ACTIVE, $license->fresh()->status);
        $this->assertTrue($license->fresh()->expires_at->lt(now()->addYears(2)));
    }

    public function test_staff_who_are_not_the_owner_are_turned_away_with_a_reason(): void
    {
        $owner = $this->register();

        $cashier = User::create([
            'tenant_id' => $owner->tenant_id, 'name' => 'Counter Staff', 'email' => 'counter@spicegarden.test',
            'password' => 'Secret123', 'is_admin' => true, 'is_active' => true, 'email_verified_at' => now(),
        ]);
        $cashier->givePermissionTo('dashboard.overview.view');

        $this->signOut();
        $this->post('/login', ['email' => 'counter@spicegarden.test', 'password' => 'Secret123'])
            ->assertSessionHasErrors('email');
    }

    /* ---------------------------------------------------- super admin controls */

    /** The whole journey to an approved license: key issued, typed by the customer, approved. */
    private function approved(string $email = 'asha@spicegarden.test', int $days = 365): License
    {
        [$license, $key] = $this->keyFor($email);

        $this->signIn($email);
        $this->postJson('/license/activate', ['license_key' => $key])->assertOk();

        $this->asSuperAdmin();

        if ($days !== 365) {
            app(LicenseService::class)->setExpiry($license, now()->addDays($days), $this->superAdmin());
        }

        $this->postJson(route('admin.licenses.act', [$license, 'approve']))->assertOk();

        return $license->fresh();
    }

    public function test_an_expired_licence_locks_the_customer_out(): void
    {
        $this->register();
        $license = $this->approved(days: 10);

        $this->signIn();
        $this->get('/dashboard')->assertOk();

        Carbon::setTestNow(now()->addDays(12));
        $this->resetState();

        $this->get('/dashboard')->assertRedirect();
        $this->get('/license')->assertOk()->assertSee('expired', false);
    }

    public function test_a_customer_sees_a_warning_before_expiry(): void
    {
        $this->register();
        $this->approved(days: 5);

        $this->signIn();
        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Renewal reminder')
            ->assertSee('Expiring Soon');
    }

    public function test_blocking_and_unblocking_a_licence(): void
    {
        $this->register();
        $license = $this->approved();

        $this->asSuperAdmin();
        $this->postJson(route('admin.licenses.act', [$license, 'block']), ['reason' => 'chargeback'])->assertOk();
        $this->assertSame(License::BLOCKED, $license->fresh()->status);

        $this->signIn();
        $this->get('/dashboard')->assertRedirect('/license');
        $this->get('/license')->assertSee('blocked', false);

        $this->asSuperAdmin();
        $this->postJson(route('admin.licenses.act', [$license, 'unblock']))->assertOk();
        $this->assertSame(License::ACTIVE, $license->fresh()->status);

        $this->signIn();
        $this->get('/dashboard')->assertOk();
    }

    public function test_deactivating_and_activating_a_licence(): void
    {
        $this->register();
        $license = $this->approved();

        $this->asSuperAdmin();
        $this->postJson(route('admin.licenses.act', [$license, 'deactivate']))->assertOk();
        $this->assertSame(License::INACTIVE, $license->fresh()->status);

        $this->signIn();
        $this->get('/dashboard')->assertRedirect('/license');

        $this->asSuperAdmin();
        $this->postJson(route('admin.licenses.act', [$license, 'reactivate']))->assertOk();

        $this->signIn();
        $this->get('/dashboard')->assertOk();
    }

    public function test_a_revoked_licence_can_never_be_used_and_the_customer_must_register_again(): void
    {
        $this->register();
        $license = $this->approved();
        $key = null;

        $this->asSuperAdmin();
        $this->postJson(route('admin.licenses.act', [$license, 'revoke']), ['reason' => 'fraud'])->assertOk();

        $license->refresh();
        $this->assertSame(License::REVOKED, $license->status);
        $this->assertNull($license->holder_tenant_id);

        $this->signIn();
        $this->get('/dashboard')->assertRedirect('/license');
        $this->get('/license')->assertSee('Activate your license')->assertSee('withdrawn');

        // A fresh key works for the same customer; the revoked one never does.
        [$new, $newKey] = $this->issue();
        $this->signIn();
        $this->postJson('/license/activate', ['license_key' => $newKey])->assertOk();
    }

    public function test_resetting_an_activation_asks_the_customer_for_the_key_again(): void
    {
        $this->register();
        $license = $this->approved();

        $this->asSuperAdmin();
        $this->postJson(route('admin.licenses.act', [$license, 'reset']), ['reason' => 'new device'])->assertOk();
        $this->assertSame(License::ISSUED, $license->fresh()->status);

        // A reset license has to be given a key to type; nothing else can be rotated.
        $this->postJson(route('admin.licenses.act', [$license, 'rotate']))->assertOk()->assertJsonStructure(['data' => ['key']]);

        $this->signIn();
        $this->get('/dashboard')->assertRedirect('/license');
        $this->get('/license')->assertSee('Activate your license');
    }

    public function test_a_new_key_can_only_be_issued_before_activation(): void
    {
        $this->register();
        [$license, $key] = $this->keyFor();
        $first = $license->fresh()->key_hash;

        // Issued again before anybody has typed it: the old key stops working.
        $this->asSuperAdmin();
        $this->postJson(route('admin.licenses.act', [$license, 'rotate']))->assertOk();
        $this->assertNotSame($first, $license->fresh()->key_hash);

        $this->signIn();
        $this->postJson('/license/activate', ['license_key' => $key])->assertStatus(422);
    }

    public function test_a_key_cannot_be_reissued_once_the_customer_has_entered_it(): void
    {
        $this->register();
        [$license, $key] = $this->keyFor();
        $this->signIn();
        $this->postJson('/license/activate', ['license_key' => $key])->assertOk();

        $this->asSuperAdmin();
        $this->postJson(route('admin.licenses.act', [$license, 'rotate']))->assertStatus(422);
    }

    public function test_rejecting_an_activation_lets_the_key_be_entered_again(): void
    {
        $this->register();
        [$license, $key] = $this->keyFor();
        $this->signIn();
        $this->postJson('/license/activate', ['license_key' => $key])->assertOk();

        $this->asSuperAdmin();
        $this->postJson(route('admin.licenses.act', [$license, 'reject']), ['reason' => 'wrong shop'])->assertOk();

        $this->assertSame(License::ISSUED, $license->fresh()->status);
        $this->assertNull($license->fresh()->holder_tenant_id);

        $this->signIn();
        $this->get('/license')->assertSee('Activate your license');
        $this->postJson('/license/activate', ['license_key' => $key])->assertOk();
    }

    public function test_renewing_and_extending_move_the_licence_and_the_subscription_together(): void
    {
        $this->register();
        $license = $this->approved(days: 30);
        $before = $license->expires_at->copy();

        $this->asSuperAdmin();
        $this->postJson(route('admin.licenses.act', [$license, 'renew']), ['days' => 100])->assertOk();
        $this->assertTrue($license->fresh()->expires_at->isSameDay($before->copy()->addDays(100)));

        $this->postJson(route('admin.licenses.act', [$license, 'extend']), ['days' => 10])->assertOk();
        $this->assertTrue($license->fresh()->expires_at->isSameDay($before->copy()->addDays(110)));

        $sub = $this->tenantOf('asha@spicegarden.test')->subscription()->first();
        $this->assertTrue($sub->ends_at->isSameDay($license->fresh()->expires_at));

        $date = now()->addDays(400)->toDateString();
        $this->postJson(route('admin.licenses.act', [$license, 'expiry']), ['expires_at' => $date])->assertOk();
        $this->assertSame($date, $license->fresh()->expires_at->toDateString());

        // Nonsense is refused.
        $this->postJson(route('admin.licenses.act', [$license, 'extend']), ['days' => 0])->assertStatus(422);
        $this->postJson(route('admin.licenses.act', [$license, 'expiry']), ['expires_at' => '2001-01-01'])->assertStatus(422);
    }

    public function test_changing_the_plan_upgrades_and_downgrades_the_subscription(): void
    {
        $this->register();
        $license = $this->approved();

        $plans = Plan::query()->orderBy('monthly_price')->get();
        $cheap = $plans->first();
        $dear = $plans->last();
        $this->assertNotSame($cheap->id, $dear->id);

        $this->asSuperAdmin();
        $this->postJson(route('admin.licenses.act', [$license, 'plan']), ['plan_id' => $dear->id])->assertOk();
        $this->assertSame($dear->id, $this->tenantOf('asha@spicegarden.test')->subscription()->first()->plan_id);

        $this->postJson(route('admin.licenses.act', [$license, 'plan']), ['plan_id' => $cheap->id])->assertOk();
        $this->assertSame($cheap->id, $this->tenantOf('asha@spicegarden.test')->subscription()->first()->plan_id);

        $actions = LicenseLog::query()->where('license_id', $license->id)->pluck('action')->all();
        $this->assertContains('plan_upgraded', $actions);
        $this->assertContains('plan_downgraded', $actions);
    }

    public function test_a_customer_can_be_blocked_and_unblocked_and_a_subscription_suspended(): void
    {
        $this->register();
        $license = $this->approved();
        $tenant = $this->tenantOf('asha@spicegarden.test');

        $this->asSuperAdmin();
        $this->postJson(route('admin.licenses.act', [$license, 'block-customer']), ['reason' => 'dispute'])->assertOk();
        $this->assertTrue($tenant->fresh()->isSuspended());

        $this->signOut();
        $this->post('/login', ['email' => 'asha@spicegarden.test', 'password' => 'Secret123'])->assertSessionHasErrors('email');

        $this->asSuperAdmin();
        $this->postJson(route('admin.licenses.act', [$license, 'unblock-customer']))->assertOk();
        $this->assertFalse($tenant->fresh()->isSuspended());

        $this->putJson(route('admin.subscriptions.suspend', $tenant), ['reason' => 'audit'])->assertOk();
        $this->assertTrue($tenant->fresh()->isSuspended());
        $this->putJson(route('admin.subscriptions.reactivate', $tenant))->assertOk();
        $this->assertFalse($tenant->fresh()->isSuspended());
    }

    public function test_subscription_extend_and_validity_for_the_super_admin(): void
    {
        $this->register();
        $license = $this->approved(days: 30);
        $tenant = $this->tenantOf('asha@spicegarden.test');

        $this->asSuperAdmin();
        $end = $tenant->subscription()->first()->ends_at->copy();

        $this->putJson(route('admin.subscriptions.extend', $tenant), ['days' => 15])->assertOk();
        $this->assertTrue($tenant->subscription()->first()->ends_at->isSameDay($end->copy()->addDays(15)));

        // A licence that granted the term follows it.
        $this->assertTrue($license->fresh()->expires_at->isSameDay($end->copy()->addDays(15)));

        $date = now()->addDays(200)->toDateString();
        $this->putJson(route('admin.subscriptions.validity', $tenant), ['ends_at' => $date])->assertOk();
        $this->assertSame($date, $tenant->subscription()->first()->ends_at->toDateString());
        $this->assertSame($date, $license->fresh()->expires_at->toDateString());
    }

    public function test_an_expired_subscription_is_refused_even_with_a_valid_licence(): void
    {
        $this->register();
        $license = $this->approved(days: 60);
        $tenant = $this->tenantOf('asha@spicegarden.test');

        // The term is cut short without touching the licence.
        $this->asSuperAdmin();
        $sub = $tenant->subscription()->first();
        $sub->forceFill(['ends_at' => now()->subDays(10), 'grace_days' => 0])->save();
        PlanAccess::forget();

        $this->signIn();
        $this->get('/dashboard')->assertRedirect('/subscription');
    }

    public function test_a_customer_without_an_assigned_plan_is_refused(): void
    {
        $this->register();
        $this->approved();
        $tenant = $this->tenantOf('asha@spicegarden.test');

        Subscription::query()->where('tenant_id', $tenant->id)->delete();
        PlanAccess::forget();

        $this->signIn();
        $this->get('/dashboard')->assertRedirect('/license');
        $this->get('/license')->assertOk()->assertSee('No plan is assigned');
    }

    public function test_exempt_customers_are_not_asked_for_a_licence(): void
    {
        $this->register();
        $tenant = $this->tenantOf('asha@spicegarden.test');
        $tenant->forceFill(['license_exempt' => true])->save();

        $this->signIn();
        $this->get('/dashboard')->assertOk();
    }

    public function test_enforcement_can_be_switched_off(): void
    {
        $this->register();
        config(['license.enforce' => false]);

        $this->signIn();
        $this->get('/dashboard')->assertOk();
    }

    /* ------------------------------------------------------------ audit & ui */

    public function test_every_change_is_written_to_the_licence_history(): void
    {
        $this->register();
        $license = $this->approved();

        $this->postJson(route('admin.licenses.act', [$license, 'extend']), ['days' => 5])->assertOk();
        $this->postJson(route('admin.licenses.act', [$license, 'deactivate']))->assertOk();

        $actions = LicenseLog::query()->where('license_id', $license->id)->pluck('action')->all();

        foreach (['generated', 'registered', 'approved', 'extended', 'deactivated'] as $expected) {
            $this->assertContains($expected, $actions);
        }

        $this->assertDatabaseHas('activity_logs', ['event' => 'license.approved']);
    }

    public function test_registration_creates_the_license_and_it_shows_in_the_companies_list(): void
    {
        $this->register();
        $license = $this->registrationLicense();

        $this->asSuperAdmin();

        $listed = fn (string $state) => $this->get(route('admin.tenants.index', ['license' => $state]))->assertOk()->viewData('tenants')->pluck('name')->all();

        $this->get(route('admin.tenants.index'))->assertOk()->assertSee('Spice Garden')->assertSee('Not activated');
        $this->assertContains('Spice Garden', $listed('issued'));
        $this->assertNotContains('Spice Garden', $listed('pending'));
        $this->assertNotContains('Spice Garden', $listed('none'));

        // The company's license panel is a modal inside Companies; it offers the key, never shows one.
        $this->get(route('admin.tenants.license', $license->tenant_id))
            ->assertOk()
            ->assertSee($license->key_hint)
            ->assertSee('Generate key');

        // Issued and typed by the customer: now it is waiting for approval, from the same list.
        [, $key] = $this->keyFor();
        $this->signIn();
        $this->postJson('/license/activate', ['license_key' => $key])->assertOk();
        $this->asSuperAdmin();

        $this->get(route('admin.tenants.index'))->assertOk()->assertSee('Pending Approval')->assertSee('Approve');
        $this->assertContains('Spice Garden', $listed('pending'));

        $this->postJson(route('admin.licenses.act', [$license, 'approve']))->assertOk();
        $this->assertContains('Spice Garden', $listed('active'));
    }

    public function test_there_is_no_separate_licenses_page_or_menu_entry(): void
    {
        $this->asSuperAdmin();

        $this->get('/superadmin/licenses')->assertStatus(405);
        $this->get('/superadmin/licenses/history')->assertNotFound();
        $this->get(route('admin.tenants.index'))->assertOk()->assertDontSee('Generate License');
    }

    public function test_the_super_admin_can_generate_a_license_for_a_company_and_the_key_is_shown_once(): void
    {
        $this->register();
        $tenant = $this->tenantOf('asha@spicegarden.test');
        $tenant->forceFill(['license_exempt' => true])->save();
        License::query()->where('tenant_id', $tenant->id)->delete();

        $this->asSuperAdmin();
        $this->get(route('admin.tenants.license', $tenant))->assertOk()->assertSee('Generate license');

        $response = $this->postJson(route('admin.licenses.store'), [
            'tenant_id' => $tenant->id,
            'plan_id' => Plan::query()->firstOrFail()->id,
            'validity_days' => 90,
        ])->assertOk()->assertJsonStructure(['data' => ['key', 'id', 'hint']]);

        $key = $response->json('data.key');
        $license = License::query()->findOrFail($response->json('data.id'));

        $this->assertSame($tenant->id, $license->tenant_id);
        $this->get(route('admin.tenants.license', $tenant))->assertOk()->assertSee($license->key_hint)->assertDontSee($key);
        $this->get(route('admin.tenants.index'))->assertOk()->assertDontSee($key);
    }

    public function test_generating_validates_the_form(): void
    {
        $this->asSuperAdmin();

        $this->postJson(route('admin.licenses.store'), [])->assertStatus(422)->assertJsonValidationErrors(['plan_id', 'tenant_id']);

        $plan = Plan::query()->firstOrFail();
        $tenant = Tenant::query()->firstOrFail();
        $this->postJson(route('admin.licenses.store'), ['plan_id' => $plan->id, 'tenant_id' => $tenant->id])->assertStatus(422);
        $this->postJson(route('admin.licenses.store'), ['plan_id' => $plan->id, 'tenant_id' => $tenant->id, 'expires_at' => '2001-01-01'])->assertStatus(422);
    }
}
