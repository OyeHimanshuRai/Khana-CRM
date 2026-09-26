<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentShop;
use App\Support\CurrentTenant;
use App\Support\Modules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Shop-level module visibility.
 *
 * Two gates, and the whole point is that they are separate:
 *
 *     module      does this branch do this kind of business at all?
 *     permission  may this person do it?
 *
 * A branch that does no purchasing hides it from everybody, its own owner
 * included. A branch that does still hides it from a cashier, because the
 * cashier has no permission. Neither substitutes for the other, and the
 * tests below are mostly about proving they do not.
 *
 * The half that matters is the server side. Hiding a sidebar entry is
 * presentation; a disabled module has to be unreachable by typed URL and by
 * API call, and that is what most of this asserts.
 */
class ShopModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

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

    private function shop(): Shop
    {
        return Shop::query()->orderBy('id')->firstOrFail();
    }

    private function tenant(): Tenant
    {
        return Tenant::query()->orderBy('id')->firstOrFail();
    }

    private function admin(): User
    {
        return User::where('is_admin', true)->firstOrFail();
    }

    /**
     * Somebody with every right that matters here, pinned to one branch, so
     * a refusal can only ever be the module and never the permission.
     */
    private function owner(): User
    {
        $user = User::create([
            'tenant_id' => $this->tenant()->id,
            'name' => 'Branch Owner',
            'email' => 'branchowner@example.test',
            'password' => 'owner-password-1',
            'is_admin' => true,
        ]);

        $user->givePermissionTo([
            'dashboard.overview.view',
            'purchasing.purchase_orders.view',
            'purchasing.suppliers.view',
            'crm.customers.view',
            'crm.dues.view',
            'sales.invoices.view',
            'inventory.products.view',
        ]);

        $user->shops()->syncWithoutDetaching([$this->shop()->id => ['is_default' => true]]);

        $user->forceFill([
            'current_shop_id' => $this->shop()->id,
            'all_shops_view' => false,
        ])->save();

        return $user;
    }

    /** @param  array<int, string>  $modules */
    private function enable(array $modules): void
    {
        $this->shop()->forceFill(['modules' => $modules])->save();

        CurrentShop::forget();
    }

    /* -------------------------------------------------------- the answer */

    /**
     * Null and [] are different answers, and the difference is what keeps an
     * upgrade from taking every existing branch dark.
     */
    public function test_a_shop_that_has_never_been_asked_runs_everything(): void
    {
        $shop = $this->shop();
        $shop->forceFill(['modules' => null])->save();

        $this->assertSame(Modules::keys(), Modules::forShop($shop));
        $this->assertTrue($shop->hasModule('purchase'));
    }

    public function test_a_shop_with_every_box_unticked_runs_nothing(): void
    {
        $shop = $this->shop();
        $shop->forceFill(['modules' => []])->save();

        $this->assertSame([], Modules::forShop($shop));
        $this->assertFalse($shop->hasModule('retail'));
    }

    /** A key for a module that no longer exists must not linger. */
    public function test_an_unknown_module_key_is_dropped(): void
    {
        $shop = $this->shop();
        $shop->forceFill(['modules' => ['retail', 'time_travel']])->save();

        $this->assertSame(['retail'], Modules::forShop($shop));
    }

    /**
     * A permission no module claims is never gated. That default is what
     * stops a permission added tomorrow from going dark because nobody
     * remembered to list it.
     */
    public function test_a_permission_no_module_claims_is_always_allowed(): void
    {
        $this->actingAs($this->owner());
        $this->enable([]);

        $this->assertTrue(Modules::allows('settings.users.view'));
        $this->assertTrue(Modules::allows('dashboard.overview.view'));
        $this->assertFalse(Modules::allows('purchasing.purchase_orders.view'));
    }

    /**
     * A prefix may be claimed by more than one module, and is then reachable
     * while any one of its owners is on.
     *
     * The shipped catalogue happens to have no such prefix today, so the
     * overlap is declared here rather than borrowed from config - the
     * mechanism is what is under test, not the current list.
     */
    public function test_a_permission_claimed_by_several_modules_needs_only_one(): void
    {
        config(['modules.purchase.permissions' => ['purchasing', 'crm']]);
        Modules::forget();

        $this->actingAs($this->owner());

        $this->enable(['purchase']);
        $this->assertTrue(Modules::allows('crm.customers.view'));

        $this->enable(['customers']);
        $this->assertTrue(Modules::allows('crm.customers.view'));

        $this->enable(['inventory']);
        $this->assertFalse(Modules::allows('crm.customers.view'));
    }

    /* ------------------------------------------------------- the routes */

    /**
     * The assertion the whole feature rests on: hidden is not the same as
     * closed, and this is the closed part.
     */
    public function test_a_disabled_module_is_refused_by_url(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner);
        $this->enable(['retail', 'inventory']);

        $this->actingAs($owner)->get('/admin/purchase-orders')->assertForbidden();
        $this->actingAs($owner)->get('/admin/suppliers')->assertForbidden();
        $this->actingAs($owner)->get('/admin/customers')->assertForbidden();

        // And what is on stays reachable, so the gate is doing something
        // narrower than "refuse everything".
        $this->actingAs($owner)->get('/admin/invoices')->assertOk();
        $this->actingAs($owner)->get('/admin/products')->assertOk();
    }

    public function test_a_disabled_module_is_refused_over_json_too(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner);
        $this->enable(['retail']);

        $this->actingAs($owner)
            ->getJson('/admin/purchase-orders')
            ->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    /**
     * Switching it on is all it takes, and nothing else has to change.
     */
    public function test_switching_the_module_on_opens_the_same_url(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner);
        $this->enable(['retail']);
        $this->actingAs($owner)->get('/admin/purchase-orders')->assertForbidden();

        $this->enable(['retail', 'purchase']);
        $this->actingAs($owner)->get('/admin/purchase-orders')->assertOk();
    }

    /**
     * The refusal names the module rather than saying "forbidden", so an
     * owner can go and switch it on instead of filing a bug.
     */
    public function test_the_refusal_says_which_module_is_off(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner);
        $this->enable(['retail']);

        $this->actingAs($owner)
            ->getJson('/admin/customers')
            ->assertStatus(403)
            ->assertJsonPath('message', 'Customer Management is not switched on for '.$this->shop()->name.'.');
    }

    /**
     * A module being on is not a permission. The two gates are independent
     * and both have to say yes.
     */
    public function test_the_module_being_on_is_not_a_permission(): void
    {
        $cashier = User::create([
            'tenant_id' => $this->tenant()->id,
            'name' => 'Just A Cashier',
            'email' => 'modulecashier@example.test',
            'password' => 'cashier-password-1',
            'is_admin' => true,
        ]);

        $cashier->givePermissionTo('dashboard.overview.view');
        $cashier->shops()->syncWithoutDetaching([$this->shop()->id => ['is_default' => true]]);

        $this->actingAs($cashier);

        // Everything on, and it still makes no difference to somebody with
        // no right to it.
        $this->enable(Modules::keys());

        $this->actingAs($cashier)->get('/admin/purchase-orders')->assertForbidden();
        $this->actingAs($cashier)->get('/admin/customers')->assertForbidden();
    }

    /* ----------------------------------------------------- the dashboard */

    public function test_the_dashboard_drops_the_widgets_for_a_disabled_module(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner);
        $this->enable(['retail', 'inventory']);

        $this->actingAs($owner)
            ->get('/admin/dashboard')
            ->assertOk()
            ->assertDontSee('Money owed')
            ->assertDontSee('Customer Master');

        $this->enable(['retail', 'inventory', 'customers']);

        $this->actingAs($owner)
            ->get('/admin/dashboard')
            ->assertOk()
            ->assertSee('Money owed')
            ->assertSee('Customer Master');
    }

    /* -------------------------------------------------------- the sidebar */

    public function test_the_sidebar_drops_a_disabled_module(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner);
        $this->enable(['retail', 'inventory']);

        $this->actingAs($owner)
            ->get('/admin/dashboard')
            ->assertOk()
            ->assertDontSee('Purchase Orders')
            ->assertDontSee('Customer Master')
            // The inventory module's own entry, which is still there. Named
            // "Menu Items" since the catalogue became a restaurant menu - the
            // module key did not change, only what a restaurant calls it.
            ->assertSee('Menu Items');
    }

    /* ---------------------------------------------------------- the form */

    public function test_the_shop_form_offers_every_module(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/shops/create')
            ->assertOk()
            ->assertSee('Business modules')
            ->assertSee('Purchase')
            ->assertSee('Customer Management')
            ->assertSee('name="modules[]"', false)
            ->assertSee('name="modules_configured"', false);
    }

    public function test_saving_the_shop_stores_the_ticked_modules(): void
    {
        $shop = $this->shop();

        $this->actingAs($this->admin())
            ->putJson("/admin/shops/{$shop->id}", [
                'name' => $shop->name,
                'code' => $shop->code,
                'modules_configured' => 1,
                'modules' => ['retail', 'pos', 'purchase'],
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(['retail', 'pos', 'purchase'], $shop->fresh()->modules);
    }

    /**
     * A caller that never mentioned modules must not silently switch every
     * line of business off.
     */
    public function test_a_save_that_does_not_mention_modules_leaves_them_alone(): void
    {
        $shop = $this->shop();
        $shop->forceFill(['modules' => ['retail', 'pos']])->save();

        $this->actingAs($this->admin())
            ->putJson("/admin/shops/{$shop->id}", [
                'name' => $shop->name,
                'code' => $shop->code,
                'phone' => '9876500000',
            ])
            ->assertOk();

        $this->assertSame(['retail', 'pos'], $shop->fresh()->modules);
    }

    /** Every box unticked is a real answer and is stored as one. */
    public function test_unticking_everything_is_stored_as_none(): void
    {
        $shop = $this->shop();

        $this->actingAs($this->admin())
            ->putJson("/admin/shops/{$shop->id}", [
                'name' => $shop->name,
                'code' => $shop->code,
                'modules_configured' => 1,
            ])
            ->assertOk();

        $this->assertSame([], $shop->fresh()->modules);
    }

    public function test_an_unknown_module_is_refused_by_validation(): void
    {
        $shop = $this->shop();

        $this->actingAs($this->admin())
            ->putJson("/admin/shops/{$shop->id}", [
                'name' => $shop->name,
                'code' => $shop->code,
                'modules_configured' => 1,
                'modules' => ['retail', 'time_travel'],
            ])
            ->assertStatus(422)
            // Reported against the offending index, not the array, so the
            // form can highlight the box that is wrong.
            ->assertJsonStructure(['errors' => ['modules.1' => []]]);

        $this->assertNotSame(['retail', 'time_travel'], $shop->fresh()->modules);
    }

    /* ------------------------------------------------- consolidated view */

    /**
     * Across branches it is the union, not the intersection.
     *
     * A group-level purchase report is meaningful as long as one branch
     * buys; narrowing to the intersection would blank the consolidated view
     * for any group whose branches differ, which is every group worth
     * consolidating.
     */
    public function test_the_all_shops_view_is_the_union_of_its_branches(): void
    {
        $first = $this->shop();
        $first->forceFill(['modules' => ['retail']])->save();

        $second = Shop::query()->create([
            'tenant_id' => $this->tenant()->id,
            'name' => 'Central Kitchen',
            'code' => 'KITCHEN',
            'slug' => Shop::uniqueSlug('Central Kitchen'),
            'modules' => ['purchase'],
            'is_active' => true,
        ]);

        $owner = $this->owner();
        $owner->shops()->syncWithoutDetaching([$second->id]);
        $owner->forceFill(['current_shop_id' => null, 'all_shops_view' => true])->save();

        $this->actingAs($owner);
        CurrentShop::forget();

        $this->assertTrue(Modules::enabled('retail'));
        $this->assertTrue(Modules::enabled('purchase'));
        $this->assertFalse(Modules::enabled('customers'));
    }

    /**
     * Console and queue context has no shop, and a gate there would silently
     * skip work the scheduler exists to do.
     */
    public function test_with_no_shop_in_context_nothing_is_gated(): void
    {
        CurrentShop::forget();
        CurrentTenant::forget();

        $this->assertTrue(Modules::allows('purchasing.purchase_orders.view'));
        $this->assertTrue(Modules::allows('crm.customers.view'));
    }
}
