<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\BlockedIp;
use App\Models\Faq;
use App\Models\Setting;
use App\Models\Shop;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Support\CurrentShop;
use App\Support\CurrentTenant;
use App\Support\Modules;
use App\Support\PlanAccess;
use App\Support\PlatformAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * One company's staff against another company's data.
 *
 * The scenario that matters now that anybody can sign up: company A is an
 * established customer (a seeded company with a Super Admin above it), and
 * company B has just registered through the public form. B's owner must be
 * able to run B and must not be able to see, change or delete anything of A's
 * - or of the platform's.
 *
 * Every write is checked by reading the row back, not by trusting the status
 * code: a refused request that changed the data anyway is the failure mode.
 */
class CrossTenantAccessTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shopA;

    private User $superAdmin;

    private User $ownerA;

    private User $ownerB;

    private Shop $shopB;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');
        $this->seed();
        $this->resetState();

        $this->shopA = Shop::query()->withoutGlobalScopes()->orderBy('id')->firstOrFail();
        $this->superAdmin = User::query()->where('email', 'admin@erp.test')->firstOrFail();

        // Company A's own owner.
        $this->ownerA = User::create([
            'tenant_id' => $this->shopA->tenant_id,
            'name' => 'Owner Of A',
            'email' => 'owner-a@example.test',
            'password' => 'Owner-a-1234',
            'is_admin' => true,
            'current_shop_id' => $this->shopA->id,
        ]);
        $this->ownerA->shops()->attach($this->shopA->id, ['is_default' => true]);
        $this->ownerA->assignRole('Tenant Owner');

        // Company B, through the public form - the real way in.
        $this->post('/register', [
            'business_name' => 'Rival Cafe',
            'name' => 'Owner Of B',
            'email' => 'owner-b@example.test',
            'mobile' => '9999999999',
            'password' => 'Secret123',
            'password_confirmation' => 'Secret123',
            'plan' => 'restaurant',
            'period' => 'monthly',
            'terms' => '1',
        ])->assertRedirect(route('admin.dashboard'));

        $this->ownerB = User::query()->withoutGlobalScopes()->where('email', 'owner-b@example.test')->firstOrFail();
        // Confirmed, as a real owner would be before doing any of this.
        $this->ownerB->forceFill(['email_verified_at' => now()])->saveQuietly();
        $this->shopB = Shop::query()->withoutGlobalScopes()->where('tenant_id', $this->ownerB->tenant_id)->firstOrFail();

        Auth::guard('web')->logout();
        $this->flushSession();
        $this->resetState();
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

    /** Sign in as somebody, with the per-request memory cleared. */
    private function as(User $user): static
    {
        $this->resetState();

        return $this->actingAs($user);
    }

    /* ------------------------------------------------------------ branches */

    public function test_another_companys_branch_cannot_be_opened_or_changed(): void
    {
        $this->as($this->ownerB)->get('/superadmin/shops/'.$this->shopA->id)->assertNotFound();
        $this->as($this->ownerB)->get('/superadmin/shops/'.$this->shopA->id.'/edit')->assertNotFound();

        $this->as($this->ownerB)
            ->putJson('/superadmin/shops/'.$this->shopA->id, ['name' => 'HACKED', 'code' => 'HACK1', 'is_active' => 1])
            ->assertNotFound();

        $this->as($this->ownerB)->putJson('/superadmin/shops/'.$this->shopA->id.'/status')->assertNotFound();
        $this->as($this->ownerB)->deleteJson('/superadmin/shops/'.$this->shopA->id)->assertNotFound();

        $fresh = Shop::query()->withoutGlobalScopes()->findOrFail($this->shopA->id);
        $this->assertSame($this->shopA->name, $fresh->name);
        $this->assertTrue($fresh->is_active);
        $this->assertNull($fresh->deleted_at);
    }

    public function test_a_company_sees_only_its_own_branches_in_the_list(): void
    {
        // A name that cannot appear anywhere else on the page (the seeded
        // branch shares the platform's brand name, which is in every title).
        $this->shopA->forceFill(['name' => 'Zulu Alpha Branch'])->save();

        $this->as($this->ownerB)
            ->get('/superadmin/shops')
            ->assertOk()
            ->assertSee($this->shopB->name)
            ->assertDontSee('Zulu Alpha Branch');

        $this->as($this->ownerA)->get('/superadmin/shops')->assertOk()->assertSee('Zulu Alpha Branch');
    }

    public function test_a_company_still_manages_its_own_branch(): void
    {
        $this->as($this->ownerB)->get('/superadmin/shops/'.$this->shopB->id)->assertOk();
        $this->as($this->ownerA)->get('/superadmin/shops/'.$this->shopA->id)->assertOk();
        $this->as($this->ownerA)->get('/superadmin/shops/'.$this->shopB->id)->assertNotFound();
    }

    /* --------------------------------------------------------------- users */

    public function test_the_platforms_own_accounts_cannot_be_reached_by_a_company(): void
    {
        foreach ([$this->superAdmin, $this->ownerA] as $target) {
            $this->as($this->ownerB)->get('/superadmin/users/'.$target->id.'/edit')->assertNotFound();
            $this->as($this->ownerB)->get('/superadmin/users/'.$target->id.'/security')->assertNotFound();
            $this->as($this->ownerB)
                ->putJson('/superadmin/users/'.$target->id, ['name' => 'Pwned', 'email' => 'pwned@example.test', 'is_active' => 1])
                ->assertNotFound();
            $this->as($this->ownerB)->putJson('/superadmin/users/'.$target->id.'/status')->assertNotFound();
            $this->as($this->ownerB)->deleteJson('/superadmin/users/'.$target->id)->assertNotFound();
        }

        $admin = User::query()->withoutGlobalScopes()->findOrFail($this->superAdmin->id);
        $this->assertSame('admin@erp.test', $admin->email);
        $this->assertTrue($admin->is_active);

        $this->assertNotNull(User::query()->withoutGlobalScopes()->find($this->ownerA->id));
    }

    public function test_the_user_list_shows_only_the_companys_own_people(): void
    {
        $this->as($this->ownerB)
            ->get('/superadmin/users')
            ->assertOk()
            ->assertSee('owner-b@example.test')
            ->assertDontSee('admin@erp.test')
            ->assertDontSee('owner-a@example.test');

        $this->as($this->ownerA)
            ->get('/superadmin/users')
            ->assertOk()
            ->assertSee('owner-a@example.test')
            ->assertDontSee('owner-b@example.test')
            ->assertDontSee('admin@erp.test');
    }

    public function test_a_super_admin_still_sees_and_reaches_everyone(): void
    {
        $this->as($this->superAdmin)->get('/superadmin/users')
            ->assertOk()
            ->assertSee('owner-a@example.test')
            ->assertSee('owner-b@example.test');

        $this->as($this->superAdmin)->get('/superadmin/shops/'.$this->shopA->id)->assertOk();
        $this->as($this->superAdmin)->get('/superadmin/shops/'.$this->shopB->id)->assertOk();
        $this->as($this->superAdmin)->get('/superadmin/users/'.$this->ownerB->id.'/edit')->assertOk();
    }

    /* ------------------------------------------- the platform's own content */

    public function test_a_company_cannot_edit_the_platforms_landing_content(): void
    {
        $this->as($this->ownerB)->postJson('/superadmin/faqs', ['question' => 'Hacked?', 'answer' => 'yes'])->assertForbidden();
        $this->as($this->ownerB)->get('/superadmin/faqs')->assertForbidden();
        $this->as($this->ownerB)->get('/superadmin/sliders')->assertForbidden();
        $this->as($this->ownerB)->get('/superadmin/demo-requests')->assertForbidden();
        $this->as($this->ownerB)->get('/superadmin/pages')->assertForbidden();

        $this->assertSame(0, Faq::query()->count());
    }

    public function test_a_company_cannot_touch_the_platforms_settings_templates_or_plans(): void
    {
        $name = Setting::get('company_name');

        $this->as($this->ownerB)->get('/superadmin/settings/company')->assertForbidden();
        $this->as($this->ownerB)
            ->putJson('/superadmin/settings/company/company', ['company_name' => 'Hacked Inc'])
            ->assertForbidden();
        $this->as($this->ownerB)->get('/superadmin/email/templates')->assertForbidden();
        $this->as($this->ownerB)->get('/superadmin/email/logs')->assertForbidden();
        $this->as($this->ownerB)->get('/superadmin/plans')->assertForbidden();
        $this->as($this->ownerB)->get('/superadmin/roles')->assertForbidden();

        $this->assertSame($name, Setting::get('company_name'));
    }

    public function test_the_platforms_menu_entries_are_not_offered_to_a_company(): void
    {
        $this->as($this->ownerB)
            ->get('/superadmin/dashboard')
            ->assertOk()
            ->assertDontSee('Landing Page')
            ->assertDontSee('Site Pages')
            ->assertDontSee('Company Settings');

        $this->as($this->superAdmin)->get('/superadmin/dashboard')->assertOk()->assertSee('Landing Page');
    }

    public function test_shared_units_and_tax_slabs_can_be_read_but_not_changed(): void
    {
        $unit = Unit::query()->firstOrFail();

        $this->as($this->ownerB)->get('/superadmin/units')->assertOk();
        $this->as($this->ownerB)->get('/superadmin/taxes')->assertOk();

        $this->as($this->ownerB)->postJson('/superadmin/units', ['name' => 'Plate', 'code' => 'PLT'])->assertForbidden();
        $this->as($this->ownerB)->putJson('/superadmin/units/'.$unit->id, ['name' => 'Hacked', 'code' => $unit->code])->assertForbidden();
        $this->as($this->ownerB)->deleteJson('/superadmin/units/'.$unit->id)->assertForbidden();
        $this->as($this->ownerB)->postJson('/superadmin/taxes', ['name' => 'Free', 'rate' => 0])->assertForbidden();

        $this->assertSame($unit->name, Unit::query()->findOrFail($unit->id)->name);
        $this->assertNull(Unit::query()->where('code', 'PLT')->first());
    }

    /* ------------------------------------------------- logs and IP blocks */

    public function test_a_company_reads_only_its_own_activity(): void
    {
        ActivityLog::create(['user_id' => $this->ownerA->id, 'user_name' => 'Owner Of A', 'event' => 'x.a', 'description' => 'Secret thing A did']);
        ActivityLog::create(['user_id' => $this->ownerB->id, 'user_name' => 'Owner Of B', 'event' => 'x.b', 'description' => 'Thing B did']);
        ActivityLog::create(['user_id' => null, 'user_name' => 'System', 'event' => 'x.s', 'description' => 'Platform housekeeping']);

        $this->as($this->ownerB)
            ->get('/superadmin/activity')
            ->assertOk()
            ->assertSee('Thing B did')
            ->assertDontSee('Secret thing A did')
            ->assertDontSee('Platform housekeeping');

        $this->as($this->superAdmin)
            ->get('/superadmin/activity')
            ->assertSee('Secret thing A did')
            ->assertSee('Thing B did');
    }

    public function test_a_company_cannot_block_an_address_for_everybody(): void
    {
        $this->as($this->ownerB)
            ->postJson('/superadmin/users/'.$this->ownerB->id.'/blocked-ips', [
                'ip_addresses' => '203.0.113.9', 'scope' => 'global', 'duration' => 'permanent',
            ])
            ->assertForbidden();

        $this->assertSame(0, BlockedIp::query()->withoutGlobalScopes()->count());

        // Stopping an address for one of its own people is fine.
        $this->as($this->ownerB)
            ->postJson('/superadmin/users/'.$this->ownerB->id.'/blocked-ips', [
                'ip_addresses' => '203.0.113.9', 'scope' => 'user', 'duration' => 'permanent',
            ])
            ->assertOk();

        $this->assertSame(1, BlockedIp::query()->withoutGlobalScopes()->count());
    }

    public function test_a_company_cannot_lift_an_address_blocked_for_everybody(): void
    {
        $block = BlockedIp::create([
            'ip_address' => '198.51.100.7', 'user_id' => null, 'status' => BlockedIp::ACTIVE, 'blocked_at' => now(),
        ]);

        $this->as($this->ownerB)
            ->deleteJson('/superadmin/users/'.$this->ownerB->id.'/blocked-ips/'.$block->id)
            ->assertNotFound();

        $this->assertSame(BlockedIp::ACTIVE, BlockedIp::query()->withoutGlobalScopes()->findOrFail($block->id)->status);
    }

    /* ----------------------------------------------- the rule on its own */

    public function test_the_platform_only_rule(): void
    {
        $companyStaff = new User(['tenant_id' => 5]);
        $platformStaff = new User(['tenant_id' => null]);

        // Not a Super Admin (no role), so only tenant_id decides.
        foreach (['content.faqs.edit', 'content.demo_requests.view', 'email.templates.view', 'settings.general.edit',
            'settings.pages.view', 'settings.plans.edit', 'settings.roles.view', 'settings.subscriptions.edit',
            'inventory.units.edit', 'inventory.units.create', 'inventory.units.delete', 'finance.taxes.edit'] as $ability) {
            $this->assertTrue(PlatformAbilities::deniedFor($companyStaff, $ability), "$ability should be denied to a company");
            $this->assertFalse(PlatformAbilities::deniedFor($platformStaff, $ability), "$ability should be open to platform staff");
        }

        foreach (['inventory.products.edit', 'inventory.units.view', 'finance.taxes.view', 'sales.invoices.create',
            'settings.users.edit', 'settings.shops.edit', 'settings.subscriptions.pay', 'settings.subscriptions.view',
            'settings.tenants.edit', 'dashboard.overview.view'] as $ability) {
            $this->assertFalse(PlatformAbilities::deniedFor($companyStaff, $ability), "$ability should stay open to a company");
        }
    }

    public function test_a_company_owner_keeps_everything_a_restaurant_needs(): void
    {
        foreach (['/superadmin/dashboard', '/superadmin/products', '/superadmin/categories', '/superadmin/customers', '/superadmin/invoices',
            '/superadmin/pos', '/superadmin/tables', '/superadmin/kitchen', '/superadmin/subscription', '/superadmin/users', '/superadmin/shops'] as $path) {
            $this->as($this->ownerB)->get($path)->assertOk();
        }
    }
}
