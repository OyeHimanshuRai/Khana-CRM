<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Shop;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\CurrentShop;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Tenant isolation, which is the platform's first acceptance criterion.
 *
 * The SRS is blunt about it: "A user assigned to one branch cannot access
 * another branch's data without explicit permission." One tenant reading
 * another's ledger is not a bug to be fixed in the next release - it is the
 * end of the product - so the guarantee is asserted directly rather than
 * inferred from the fact that the screens look right.
 *
 * The mechanism under test: every operational table filters on shop_id, and
 * a user can only reach shops inside their own tenant. Isolation therefore
 * rests entirely on CurrentShop::accessible() being tenant-filtered, which
 * is what most of these cases poke at.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $acme;

    private Tenant $rival;

    private Shop $acmeBranch;

    private Shop $rivalBranch;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');

        $this->seed();
        CurrentShop::forget();
        CurrentTenant::forget();

        // The seeded install is one tenant with one branch; that is Acme.
        $this->acme = Tenant::query()->orderBy('id')->firstOrFail();
        $this->acmeBranch = Shop::query()->where('tenant_id', $this->acme->id)->firstOrFail();

        $this->rival = Tenant::query()->create([
            'name' => 'Rival Jewellers',
            'code' => 'RIVAL',
            'slug' => Tenant::uniqueSlug('Rival Jewellers'),
            'is_active' => true,
        ]);

        $this->rivalBranch = Shop::query()->create([
            'tenant_id' => $this->rival->id,
            'name' => 'Rival Main',
            'code' => 'RVL1',
            'slug' => Shop::uniqueSlug('Rival Main'),
            'is_active' => true,
        ]);

        Warehouse::withoutEvents(fn () => Warehouse::query()->create([
            'shop_id' => $this->rivalBranch->id,
            'name' => 'Main Store',
            'code' => 'RVLMAIN',
            'is_default' => true,
            'is_active' => true,
        ]));
    }

    protected function tearDown(): void
    {
        CurrentShop::forget();
        CurrentTenant::forget();

        parent::tearDown();
    }

    /* ------------------------------------------------------------ actors */

    private function staffOf(Tenant $tenant, Shop $branch, string $email): User
    {
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Staff of '.$tenant->name,
            'email' => $email,
            'password' => 'staff-password-1',
            'is_admin' => true,
        ]);

        $user->givePermissionTo(['crm.customers.view', 'sales.invoices.view']);
        $user->shops()->attach($branch->id, ['is_default' => true]);
        $user->forceFill(['current_shop_id' => $branch->id])->save();

        return $user;
    }

    private function superAdmin(): User
    {
        return User::query()->whereNull('tenant_id')->where('is_admin', true)->firstOrFail();
    }

    /* --------------------------------------------------------- the rules */

    public function test_staff_only_ever_see_their_own_tenants_branches(): void
    {
        $this->actingAs($this->staffOf($this->acme, $this->acmeBranch, 'acme@example.test'));
        CurrentShop::forget();
        CurrentTenant::forget();

        $reachable = CurrentShop::accessibleIds();

        $this->assertContains($this->acmeBranch->id, $reachable);
        $this->assertNotContains($this->rivalBranch->id, $reachable);
    }

    public function test_a_branch_of_another_tenant_cannot_be_switched_into(): void
    {
        $this->actingAs($this->staffOf($this->acme, $this->acmeBranch, 'acme2@example.test'));
        CurrentShop::forget();
        CurrentTenant::forget();

        // Even asked for by id directly - the switcher is a suggestion, this
        // is the guard.
        $this->assertFalse(CurrentShop::canAccess($this->rivalBranch->id));
        $this->assertFalse(CurrentShop::set($this->rivalBranch->id));
        $this->assertSame($this->acmeBranch->id, CurrentShop::id());
    }

    public function test_one_tenants_customers_are_invisible_to_another(): void
    {
        Customer::query()->create([
            'shop_id' => $this->rivalBranch->id,
            'name' => 'Rival Customer',
            'is_active' => true,
        ]);

        Customer::query()->create([
            'shop_id' => $this->acmeBranch->id,
            'name' => 'Acme Customer',
            'is_active' => true,
        ]);

        $this->actingAs($this->staffOf($this->acme, $this->acmeBranch, 'acme3@example.test'));
        CurrentShop::forget();
        CurrentTenant::forget();

        $names = Customer::query()->pluck('name');

        $this->assertContains('Acme Customer', $names);
        $this->assertNotContains('Rival Customer', $names);
    }

    public function test_the_customer_list_screen_does_not_leak_across_tenants(): void
    {
        Customer::query()->create([
            'shop_id' => $this->rivalBranch->id,
            'name' => 'Rival Customer',
            'is_active' => true,
        ]);

        $this->actingAs($this->staffOf($this->acme, $this->acmeBranch, 'acme4@example.test'))
            ->get('/superadmin/customers')
            ->assertOk()
            ->assertDontSee('Rival Customer');
    }

    /**
     * A user with no tenant and no Super Admin role reaches nothing.
     *
     * The dangerous reading of "tenant_id is null" is "unscoped". It means
     * Super Admin, and only when the role says so.
     */
    public function test_a_tenantless_non_super_admin_reaches_nothing(): void
    {
        $orphan = User::create([
            'name' => 'Orphan',
            'email' => 'orphan@example.test',
            'password' => 'orphan-password-1',
            'is_admin' => true,
        ]);

        $orphan->givePermissionTo('crm.customers.view');
        $orphan->shops()->attach($this->rivalBranch->id, ['is_default' => true]);

        $this->actingAs($orphan);
        CurrentShop::forget();
        CurrentTenant::forget();

        $this->assertSame([], CurrentShop::accessibleIds());
        $this->assertNull(CurrentShop::id());
    }

    /* ------------------------------------------------------ super admin */

    public function test_a_super_admin_reaches_every_tenant(): void
    {
        $this->actingAs($this->superAdmin());
        CurrentShop::forget();
        CurrentTenant::forget();

        $this->assertEqualsCanonicalizing(
            [$this->acme->id, $this->rival->id],
            CurrentTenant::accessibleIds(),
        );
    }

    public function test_switching_tenant_narrows_the_branches_on_offer(): void
    {
        $this->actingAs($this->superAdmin());
        CurrentShop::forget();
        CurrentTenant::forget();

        $this->assertTrue(CurrentTenant::set($this->rival->id));
        $this->assertSame([$this->rivalBranch->id], CurrentShop::accessibleIds());

        $this->assertTrue(CurrentTenant::set($this->acme->id));
        $this->assertNotContains($this->rivalBranch->id, CurrentShop::accessibleIds());
    }

    /**
     * Switching tenant must drop the branch that came with the old one.
     *
     * Otherwise a stale current_shop_id keeps pointing at the previous
     * tenant's branch, and the very next scoped query answers from the
     * business the user just left.
     */
    public function test_switching_tenant_clears_the_stale_branch_context(): void
    {
        $admin = $this->superAdmin();
        $admin->forceFill(['current_shop_id' => $this->acmeBranch->id])->save();

        $this->actingAs($admin);
        CurrentShop::forget();
        CurrentTenant::forget();

        $this->assertSame($this->acmeBranch->id, CurrentShop::id());

        CurrentTenant::set($this->rival->id);

        $this->assertNotSame($this->acmeBranch->id, CurrentShop::id());
        $this->assertSame($this->rivalBranch->id, CurrentShop::id());
    }

    public function test_staff_cannot_switch_tenant_at_all(): void
    {
        $this->actingAs($this->staffOf($this->acme, $this->acmeBranch, 'acme5@example.test'));
        CurrentShop::forget();
        CurrentTenant::forget();

        $this->assertFalse(CurrentTenant::canAccess($this->rival->id));
        $this->assertFalse(CurrentTenant::set($this->rival->id));
        $this->assertSame($this->acme->id, CurrentTenant::id());
    }
}
