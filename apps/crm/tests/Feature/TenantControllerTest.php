<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentShop;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The company master (SRS 10.1).
 *
 * Three separate guarantees are being asserted, and they are easy to confuse:
 *
 *   1. The CRUD behaves like every other master in this app.
 *   2. The *permission* opens the screen; CurrentTenant decides whose
 *      companies appear on it. A tenant owner holding settings.tenants.view
 *      sees one row - their own - and no amount of guessing ids gets them
 *      another.
 *   3. Creating and removing a business is a platform action. A tenant owner
 *      may edit their own company's details and nothing else, even with the
 *      permission granted, because "add a company to the platform" is not a
 *      thing a customer of the platform does.
 */
class TenantControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // APP_URL carries a sub-path, which would prefix every test request
        // and miss the routes entirely.
        URL::forceRootUrl('http://localhost');

        $this->seed();

        CurrentShop::forget();
        CurrentTenant::forget();
    }

    protected function tearDown(): void
    {
        CurrentShop::forget();
        CurrentTenant::forget();

        parent::tearDown();
    }

    /* ------------------------------------------------------------ actors */

    private function admin(): User
    {
        return User::where('is_admin', true)->firstOrFail();
    }

    private function tenant(): Tenant
    {
        return Tenant::query()->orderBy('id')->firstOrFail();
    }

    private function rival(): Tenant
    {
        return Tenant::query()->create([
            'name' => 'Rival Jewellers',
            'code' => 'RIVAL',
            'slug' => Tenant::uniqueSlug('Rival Jewellers'),
            'is_active' => true,
        ]);
    }

    /**
     * A tenant owner: full rights over companies, but pinned to their own.
     */
    private function owner(?Tenant $tenant = null): User
    {
        $user = User::create([
            'tenant_id' => ($tenant ?? $this->tenant())->id,
            'name' => 'Tenant Owner',
            'email' => 'owner@example.test',
            'password' => 'owner-password-1',
            'is_admin' => true,
        ]);

        $user->givePermissionTo([
            'settings.tenants.view',
            'settings.tenants.create',
            'settings.tenants.edit',
            'settings.tenants.delete',
        ]);

        return $user;
    }

    /* --------------------------------------------------------------- list */

    public function test_the_list_page_renders(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/tenants')
            ->assertOk()
            ->assertSee($this->tenant()->name)
            ->assertSee('Add Company');
    }

    public function test_a_fragment_request_returns_the_table_alone(): void
    {
        $this->actingAs($this->admin())
            ->withHeader('X-Fragment', '1')
            ->get('/admin/tenants')
            ->assertOk()
            ->assertSee($this->tenant()->name)
            ->assertDontSee('<body', false)
            ->assertDontSee('class="sidebar"', false)
            // Scripts inside injected markup never execute, so there are none.
            ->assertDontSee('<script', false);
    }

    /**
     * Filtering is asserted between two companies created here, never against
     * the seeded one: its name and code are also the brand in the page
     * chrome, so "not on the page" could never be true of them.
     */
    public function test_the_list_can_be_searched_and_filtered(): void
    {
        $rival = $this->rival();
        $rival->suspend('Subscription unpaid');

        Tenant::query()->create([
            'name' => 'Kanchi Goldsmiths',
            'code' => 'KANCHI',
            'slug' => Tenant::uniqueSlug('Kanchi Goldsmiths'),
            'is_active' => true,
        ]);

        /*
         | Asserted against the fragment, not the whole page. The company
         | switcher in the header lists every company this Super Admin can
         | reach, so "not on the page" is never true of any of them - only
         | "not in the table" is the claim worth making.
         */
        $this->actingAs($this->admin())
            ->withHeader('X-Fragment', '1')
            ->get('/admin/tenants?q=rival')
            ->assertOk()
            ->assertSee('Rival Jewellers')
            ->assertDontSee('Kanchi Goldsmiths');

        $this->actingAs($this->admin())
            ->withHeader('X-Fragment', '1')
            ->get('/admin/tenants?status=inactive')
            ->assertOk()
            ->assertSee('Rival Jewellers')
            ->assertSee('Subscription unpaid')
            ->assertDontSee('Kanchi Goldsmiths');
    }

    public function test_the_forms_come_back_as_bare_fragments(): void
    {
        $tenant = $this->tenant();

        $this->actingAs($this->admin())
            ->get('/admin/tenants/create')
            ->assertOk()
            ->assertSee('Create company')
            ->assertDontSee('<body', false)
            ->assertDontSee('<script', false);

        $this->actingAs($this->admin())
            ->get("/admin/tenants/{$tenant->id}/edit")
            ->assertOk()
            ->assertSee('Save changes')
            ->assertSee($tenant->code)
            ->assertDontSee('<script', false);

        $this->actingAs($this->admin())
            ->get("/admin/tenants/{$tenant->id}")
            ->assertOk()
            ->assertSee($tenant->name)
            ->assertDontSee('<script', false);
    }

    /* -------------------------------------------------------------- write */

    public function test_a_company_is_created(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/admin/tenants', [
                'name' => 'Meenakshi Jewellers',
                'code' => 'mjw',
                'gstin' => '22AAAAA0000A1Z5',
                'pan' => 'ABCDE1234F',
                'city' => 'Madurai',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            // Upper-cased on the way in, so "mjw" can never slip past "MJW".
            ->assertJsonPath('data.code', 'MJW');

        $this->assertDatabaseHas('tenants', [
            'code' => 'MJW',
            'gstin' => '22AAAAA0000A1Z5',
            'pan' => 'ABCDE1234F',
        ]);

        $this->assertDatabaseHas('activity_logs', ['event' => 'tenant.created']);
    }

    public function test_validation_errors_come_back_per_field(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/admin/tenants', [
                'name' => '',
                'code' => 'has spaces',
                'gstin' => 'nope',
                'pan' => 'nope',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['errors' => ['name', 'code', 'gstin', 'pan']]);
    }

    public function test_a_duplicate_code_is_refused(): void
    {
        $this->rival();

        $this->actingAs($this->admin())
            ->postJson('/admin/tenants', [
                'name' => 'Rival Again',
                'code' => 'rival',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['code']]);
    }

    /**
     * An ordinary save must not be able to suspend a business.
     *
     * Suspension is its own audited action with its own reason attached.
     * A save that could flip it would put a suspension in the log with no
     * explanation, which is exactly the record nobody can act on later.
     */
    public function test_an_ordinary_save_cannot_change_the_trading_status(): void
    {
        $tenant = $this->tenant();

        $this->actingAs($this->admin())
            ->putJson("/admin/tenants/{$tenant->id}", [
                'name' => $tenant->name,
                'code' => $tenant->code,
                'is_active' => 0,
            ])
            ->assertOk();

        $this->assertTrue($tenant->fresh()->is_active);
    }

    /* --------------------------------------------------------- suspension */

    public function test_suspending_records_a_reason_and_reinstating_clears_it(): void
    {
        $rival = $this->rival();

        $this->actingAs($this->admin())
            ->putJson("/admin/tenants/{$rival->id}/status", ['reason' => 'Subscription unpaid'])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $rival->refresh();

        $this->assertFalse($rival->is_active);
        $this->assertSame('Subscription unpaid', $rival->suspend_reason);
        $this->assertNotNull($rival->suspended_at);
        $this->assertDatabaseHas('activity_logs', ['event' => 'tenant.suspended']);

        $this->actingAs($this->admin())
            ->putJson("/admin/tenants/{$rival->id}/status")
            ->assertOk()
            ->assertJsonPath('data.is_active', true);

        $rival->refresh();

        $this->assertTrue($rival->is_active);
        $this->assertNull($rival->suspend_reason);
        $this->assertNull($rival->suspended_at);
    }

    /**
     * A platform with no trading company has nowhere to file anything.
     */
    public function test_the_last_active_company_cannot_be_suspended(): void
    {
        $tenant = $this->tenant();

        $this->actingAs($this->admin())
            ->putJson("/admin/tenants/{$tenant->id}/status")
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertTrue($tenant->fresh()->is_active);
    }

    /* ------------------------------------------------------------ delete */

    /**
     * The rule that keeps a branch from being orphaned.
     *
     * Every shop hangs off a tenant, and every invoice, payment and stock move
     * hangs off a shop. Removing the tenant underneath live branches would
     * surface much later as data belonging to nobody.
     */
    public function test_a_company_with_branches_cannot_be_removed(): void
    {
        $tenant = $this->tenant();

        $this->assertGreaterThan(0, Shop::query()->where('tenant_id', $tenant->id)->count());

        $this->actingAs($this->admin())
            ->deleteJson("/admin/tenants/{$tenant->id}")
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('tenants', ['id' => $tenant->id, 'deleted_at' => null]);
    }

    public function test_a_company_with_no_branches_is_soft_deleted(): void
    {
        $rival = $this->rival();

        $this->actingAs($this->admin())
            ->deleteJson("/admin/tenants/{$rival->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        // Soft: its ledger has to stay readable even once it has gone.
        $this->assertNotNull($rival->fresh()->deleted_at);
        $this->assertDatabaseHas('activity_logs', ['event' => 'tenant.deleted']);
    }

    public function test_the_only_company_cannot_be_removed(): void
    {
        $tenant = $this->tenant();

        Shop::query()->where('tenant_id', $tenant->id)->delete();

        $this->actingAs($this->admin())
            ->deleteJson("/admin/tenants/{$tenant->id}")
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    /* -------------------------------------------------------- isolation */

    /**
     * The guarantee this screen exists to keep.
     *
     * A tenant owner holds every settings.tenants.* right and still sees one
     * company: their own. The permission answers "may they open this"; the
     * data answers "whose".
     */
    public function test_a_tenant_owner_sees_only_their_own_company(): void
    {
        $rival = $this->rival();
        $owner = $this->owner();

        $this->actingAs($owner);
        CurrentTenant::forget();

        $this->actingAs($owner)
            ->get('/admin/tenants')
            ->assertOk()
            ->assertSee($this->tenant()->code)
            ->assertDontSee('Rival Jewellers');
    }

    public function test_a_tenant_owner_cannot_open_another_company(): void
    {
        $rival = $this->rival();
        $owner = $this->owner();

        $this->actingAs($owner);
        CurrentTenant::forget();

        $this->actingAs($owner)->get("/admin/tenants/{$rival->id}")->assertForbidden();
        $this->actingAs($owner)->get("/admin/tenants/{$rival->id}/edit")->assertForbidden();
        $this->actingAs($owner)
            ->putJson("/admin/tenants/{$rival->id}", ['name' => 'Taken Over', 'code' => 'RIVAL'])
            ->assertForbidden();

        $this->assertSame('Rival Jewellers', $rival->fresh()->name);
    }

    /**
     * Adding and removing a *business* is the platform's job, not a
     * customer's - even a customer holding the permission.
     */
    public function test_a_tenant_owner_cannot_add_or_remove_a_company(): void
    {
        $rival = $this->rival();
        $owner = $this->owner();

        $this->actingAs($owner);
        CurrentTenant::forget();

        $this->actingAs($owner)->get('/admin/tenants/create')->assertForbidden();
        $this->actingAs($owner)
            ->postJson('/admin/tenants', ['name' => 'Sneaky Co', 'code' => 'SNK'])
            ->assertForbidden();
        $this->actingAs($owner)->deleteJson("/admin/tenants/{$rival->id}")->assertForbidden();
        $this->actingAs($owner)
            ->putJson("/admin/tenants/{$rival->id}/status")
            ->assertForbidden();

        $this->assertDatabaseMissing('tenants', ['code' => 'SNK']);
    }

    /** A tenant owner may still put their own company's details right. */
    public function test_a_tenant_owner_may_edit_their_own_company(): void
    {
        $owner = $this->owner();
        $tenant = $this->tenant();

        $this->actingAs($owner);
        CurrentTenant::forget();

        $this->actingAs($owner)
            ->putJson("/admin/tenants/{$tenant->id}", [
                'name' => $tenant->name,
                'code' => $tenant->code,
                'phone' => '9876543210',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('9876543210', $tenant->fresh()->phone);
    }

    /* --------------------------------------------------------- switching */

    /**
     * Switching company drops the branch in context.
     *
     * A stale current_shop_id would otherwise point at a branch of the
     * company just left for the rest of the request.
     */
    public function test_switching_company_clears_the_branch_in_context(): void
    {
        $rival = $this->rival();
        $admin = $this->admin();

        $this->actingAs($admin);
        CurrentTenant::forget();
        CurrentShop::forget();

        $shop = Shop::query()->firstOrFail();
        $admin->forceFill(['current_shop_id' => $shop->id, 'all_shops_view' => false])->save();

        $this->actingAs($admin)
            ->postJson('/admin/tenants/switch', ['tenant' => $rival->id])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame($rival->id, (int) $admin->fresh()->current_tenant_id);
        $this->assertNull($admin->fresh()->current_shop_id);
        $this->assertDatabaseHas('activity_logs', ['event' => 'tenant.switched']);
    }

    public function test_switching_to_a_company_out_of_reach_is_refused(): void
    {
        $rival = $this->rival();
        $owner = $this->owner();

        $this->actingAs($owner);
        CurrentTenant::forget();

        $this->actingAs($owner)
            ->postJson('/admin/tenants/switch', ['tenant' => $rival->id])
            ->assertStatus(403)
            ->assertJsonPath('success', false);

        $this->assertNotSame($rival->id, (int) $owner->fresh()->current_tenant_id);
    }

    /* ------------------------------------------------------- permissions */

    public function test_an_admin_without_the_permission_is_forbidden(): void
    {
        $user = User::create([
            'tenant_id' => $this->tenant()->id,
            'name' => 'No Rights',
            'email' => 'norights@example.test',
            'password' => 'norights-password-1',
            'is_admin' => true,
        ]);

        $this->actingAs($user)->get('/admin/tenants')->assertForbidden();
    }

    public function test_guests_are_redirected(): void
    {
        $this->get('/admin/tenants')->assertRedirect('http://localhost/admin/login');
    }
}
