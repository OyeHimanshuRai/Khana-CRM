<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Shop;
use App\Models\Tenant;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CatalogStarter;
use App\Support\CurrentShop;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
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

    /* ------------------------------------------- creating a branch (§2) */

    /*
     | Which business a new branch belongs to.
     |
     | `tenant_id` was missing from ShopController's validation rules
     | altogether, so a posted id was validated away and the resolution
     | underneath fell through to null. A Super Admin working across all
     | companies therefore created branches belonging to NOBODY - invisible
     | under every company, unreachable by any staff account, impossible to
     | subscribe - and the response said "created".
     |
     | An orphan is far harder to notice than an error, so these three pin
     | down the whole rule: name it and it lands there, name nothing and it
     | is refused, name somebody else's and only a Super Admin may.
     */

    public function test_a_super_admin_creates_a_branch_for_the_company_they_name(): void
    {
        $this->actingAs($this->superAdmin());

        // All-companies mode: there is no single company in context.
        CurrentTenant::set(null);

        $this->postJson(route('admin.shops.store'), [
            'name' => 'Rival Second',
            'code' => 'RVL2',
            'tenant_id' => $this->rival->id,
        ])->assertOk();

        $created = Shop::query()->withoutGlobalScopes()->where('code', 'RVL2')->firstOrFail();

        $this->assertSame($this->rival->id, $created->tenant_id);
    }

    public function test_a_branch_is_never_created_without_a_company(): void
    {
        $this->actingAs($this->superAdmin());
        CurrentTenant::set(null);

        $response = $this->postJson(route('admin.shops.store'), [
            'name' => 'Nobodys Branch',
            'code' => 'ORPH',
        ]);

        // Refused in words, rather than filed under nobody.
        $response->assertStatus(422);
        $this->assertStringContainsString('which company', $response->json('message'));

        $this->assertSame(0, Shop::query()->withoutGlobalScopes()->whereNull('tenant_id')->count());
        $this->assertNull(Shop::query()->withoutGlobalScopes()->firstWhere('code', 'ORPH'));
    }

    public function test_staff_cannot_create_a_branch_for_another_company(): void
    {
        $staff = $this->staffOf($this->acme, $this->acmeBranch, 'acme-builder@example.test');
        $staff->givePermissionTo('settings.shops.create');

        $this->actingAs($staff->fresh());
        CurrentTenant::forget();

        $this->postJson(route('admin.shops.store'), [
            'name' => 'Sneaky Branch',
            'code' => 'SNK1',
            'tenant_id' => $this->rival->id,
        ])->assertStatus(422);

        $this->assertNull(Shop::query()->withoutGlobalScopes()->firstWhere('code', 'SNK1'));
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
            ->get('/admin/customers')
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

    /* ------------------------------------------- the catalogue (SS8, SS23) */

    /*
     | Products, categories, brands, units and tax rates had no owner column
     | at all - one table shared by every business on the install. Invisible
     | on a single-restaurant deployment, and on a platform it meant the
     | business that signed up this morning opened Products and found
     | somebody else's menu, editable, and already on its own QR menu.
     |
     | These four pin the fix: the list, the uniqueness that used to be
     | table-wide, the guest-facing menu, and the starter set that keeps a new
     | business able to work at all.
     */

    private function productFor(Tenant $tenant, string $name): Product
    {
        return Product::query()->forceCreate([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'slug' => Str::slug($name),
            'sku' => strtoupper(Str::random(8)),
            'selling_price' => 100,
            'is_active' => true,
        ]);
    }

    public function test_one_companys_catalogue_is_invisible_to_another(): void
    {
        $mine = $this->productFor($this->acme, 'Acme Paneer Tikka');
        $theirs = $this->productFor($this->rival, 'Rival Paneer Tikka');

        $staff = $this->staffOf($this->acme, $this->acmeBranch, 'catalogue@acme.test');

        $this->actingAs($staff);
        CurrentShop::forget();
        CurrentTenant::forget();

        $visible = Product::query()->pluck('id');

        $this->assertTrue($visible->contains($mine->id));
        $this->assertFalse($visible->contains($theirs->id), "One company's product reached another.");
    }

    /**
     * The same dish name in two restaurants is two rows, not a collision.
     *
     * `products.slug` and `products.sku` were unique across the whole table,
     * so the second business to sell a Paneer Tikka was refused because of a
     * row it could not see - and the error named it.
     */
    public function test_two_companies_may_sell_a_dish_of_the_same_name(): void
    {
        $this->productFor($this->acme, 'Paneer Tikka');

        $second = Product::query()->forceCreate([
            'tenant_id' => $this->rival->id,
            'name' => 'Paneer Tikka',
            'slug' => 'paneer-tikka',
            'sku' => 'PANEER-1',
            'selling_price' => 220,
            'is_active' => true,
        ]);

        $this->assertTrue($second->exists);
        $this->assertSame(2, Product::allTenants()->where('slug', 'paneer-tikka')->count());
    }

    /**
     * A guest has no session, so the scope steps aside and the query has to
     * name the company itself. Without this a diner scanning a table in one
     * restaurant was shown every restaurant's dishes on the install.
     */
    public function test_a_guests_menu_only_shows_the_outlets_own_company(): void
    {
        $mine = $this->productFor($this->acme, 'Acme Dal Makhani');
        $theirs = $this->productFor($this->rival, 'Rival Dal Makhani');

        $this->assertGuest();

        $available = Product::query()->availableAt($this->acmeBranch->id)->pluck('id');

        $this->assertTrue($available->contains($mine->id));
        $this->assertFalse($available->contains($theirs->id), "A guest was offered another company's dish.");
    }

    /**
     * And a new business can actually work.
     *
     * Scoping the masters means a fresh company owns no units and no tax
     * slabs, and a product cannot be saved without a unit - so the starter
     * set is part of the isolation guarantee rather than a nicety.
     */
    public function test_a_new_company_is_given_its_own_units_and_slabs(): void
    {
        app(CatalogStarter::class)->forTenant($this->rival);

        $theirUnits = Unit::allTenants()->where('tenant_id', $this->rival->id)->pluck('id');
        $ourUnits = Unit::allTenants()->where('tenant_id', $this->acme->id)->pluck('id');

        $this->assertGreaterThan(0, $theirUnits->count());
        $this->assertGreaterThan(0, TaxRate::allTenants()->where('tenant_id', $this->rival->id)->count());

        // Its own copies, not the other company's rows.
        $this->assertTrue($theirUnits->intersect($ourUnits)->isEmpty());
    }
}
