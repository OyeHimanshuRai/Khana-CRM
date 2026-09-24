<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Shop;
use App\Models\User;
use App\Support\CurrentShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Multi-tenant isolation.
 *
 * This covers the SRS's first acceptance criterion directly: "A user
 * assigned to Shop A cannot access Shop B data without explicit
 * permission." Everything else in the system leans on it, so it is tested
 * at the query layer, the controller layer and the switcher.
 */
class ShopScopeTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        CurrentShop::forget();

        parent::tearDown();
    }

    /* ------------------------------------------------------------ fixtures */

    private function superAdmin(): User
    {
        return User::where('is_admin', true)->firstOrFail();
    }

    private function shop(string $code, string $name): Shop
    {
        return Shop::create([
            'name' => $name,
            'code' => $code,
            'slug' => Shop::uniqueSlug($name),
            'is_active' => true,
        ]);
    }

    /** An admin who may work in exactly the given shops. */
    private function staffFor(array $shops, array $permissions = ['crm.customers.view']): User
    {
        static $counter = 0;
        $counter++;

        $user = User::create([
            'name' => 'Shop Staff '.$counter,
            'email' => "staff{$counter}@example.test",
            'password' => 'staff-password-1',
            'is_admin' => true,
        ]);

        $user->givePermissionTo($permissions);

        foreach ($shops as $index => $shop) {
            $user->shops()->attach($shop->id, ['is_default' => $index === 0]);
        }

        $user->forceFill(['current_shop_id' => $shops[0]->id ?? null])->save();

        return $user;
    }

    /** A customer filed against a named shop, bypassing the request scope. */
    private function customerIn(Shop $shop, string $name): Customer
    {
        $customer = new Customer([
            'shop_id' => $shop->id,
            'name' => $name,
            'type' => 'retail',
            'is_active' => true,
        ]);

        // saveQuietly skips the BelongsToShop guard, which is exactly right
        // for a fixture: the point is to plant data the actor cannot reach.
        $customer->saveQuietly();

        return $customer;
    }

    /* -------------------------------------------------------- query scope */

    public function test_a_query_only_returns_the_selected_shops_rows(): void
    {
        $alpha = $this->shop('ALPHA', 'Alpha Agro');
        $beta = $this->shop('BETA', 'Beta Agro');

        $this->customerIn($alpha, 'Alpha Farmer');
        $this->customerIn($beta, 'Beta Farmer');

        $staff = $this->staffFor([$alpha]);
        $this->actingAs($staff);
        CurrentShop::forget();

        $names = Customer::query()->pluck('name');

        $this->assertContains('Alpha Farmer', $names);
        $this->assertNotContains('Beta Farmer', $names);
    }

    public function test_all_shops_mode_spans_only_the_users_own_shops(): void
    {
        $alpha = $this->shop('ALPHA', 'Alpha Agro');
        $beta = $this->shop('BETA', 'Beta Agro');
        $gamma = $this->shop('GAMMA', 'Gamma Agro');

        $this->customerIn($alpha, 'Alpha Farmer');
        $this->customerIn($beta, 'Beta Farmer');
        $this->customerIn($gamma, 'Gamma Farmer');

        $staff = $this->staffFor([$alpha, $beta]);
        // The flag, not merely a null shop id, is what selects All shops.
        $staff->forceFill(['current_shop_id' => null, 'all_shops_view' => true])->save();

        $this->actingAs($staff);
        CurrentShop::forget();

        $names = Customer::query()->pluck('name');

        $this->assertContains('Alpha Farmer', $names);
        $this->assertContains('Beta Farmer', $names);
        $this->assertNotContains('Gamma Farmer', $names);
    }

    public function test_an_account_with_no_shops_sees_no_operational_rows(): void
    {
        $alpha = $this->shop('ALPHA', 'Alpha Agro');
        $this->customerIn($alpha, 'Alpha Farmer');

        $stranger = $this->staffFor([]);

        $this->actingAs($stranger);
        CurrentShop::forget();

        $this->assertSame(0, Customer::query()->count());
    }

    public function test_a_super_admin_reaches_every_shop(): void
    {
        $alpha = $this->shop('ALPHA', 'Alpha Agro');
        $beta = $this->shop('BETA', 'Beta Agro');

        $this->customerIn($alpha, 'Alpha Farmer');
        $this->customerIn($beta, 'Beta Farmer');

        $admin = $this->superAdmin();
        $admin->forceFill(['current_shop_id' => null, 'all_shops_view' => true])->save();

        $this->actingAs($admin);
        CurrentShop::forget();

        $names = Customer::query()->pluck('name');

        $this->assertContains('Alpha Farmer', $names);
        $this->assertContains('Beta Farmer', $names);
    }

    /* ------------------------------------------------------ route binding */

    public function test_another_shops_record_is_not_reachable_by_url(): void
    {
        $alpha = $this->shop('ALPHA', 'Alpha Agro');
        $beta = $this->shop('BETA', 'Beta Agro');

        $foreign = $this->customerIn($beta, 'Beta Farmer');

        $staff = $this->staffFor([$alpha]);

        // Route model binding resolves through the scoped query, so a row in
        // another shop simply does not exist as far as this actor is
        // concerned - a 404, not a 403, and no confirmation it is there.
        $this->actingAs($staff)
            ->get('/admin/customers/'.$foreign->id)
            ->assertNotFound();
    }

    public function test_writing_into_an_inaccessible_shop_is_refused(): void
    {
        $alpha = $this->shop('ALPHA', 'Alpha Agro');
        $beta = $this->shop('BETA', 'Beta Agro');

        $staff = $this->staffFor([$alpha], ['crm.customers.view', 'crm.customers.create']);

        $this->actingAs($staff);
        CurrentShop::forget();

        // The form's shop_id is validated against the accessible list, so a
        // hand-edited field cannot file a record into someone else's shop.
        $this->post('/admin/customers', [
            'shop_id' => $beta->id,
            'name' => 'Smuggled In',
            'type' => 'retail',
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertDatabaseMissing('customers', ['name' => 'Smuggled In']);
    }

    /* ----------------------------------------------------------- switching */

    public function test_switching_to_an_accessible_shop_changes_what_is_visible(): void
    {
        $alpha = $this->shop('ALPHA', 'Alpha Agro');
        $beta = $this->shop('BETA', 'Beta Agro');

        $this->customerIn($alpha, 'Alpha Farmer');
        $this->customerIn($beta, 'Beta Farmer');

        $staff = $this->staffFor([$alpha, $beta]);

        $this->actingAs($staff)
            ->post('/admin/shops/switch', ['shop' => (string) $beta->id], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame($beta->id, $staff->fresh()->current_shop_id);

        CurrentShop::forget();
        $names = Customer::query()->pluck('name');

        $this->assertContains('Beta Farmer', $names);
        $this->assertNotContains('Alpha Farmer', $names);
    }

    public function test_choosing_all_shops_survives_the_next_request(): void
    {
        $alpha = $this->shop('ALPHA', 'Alpha Agro');
        $beta = $this->shop('BETA', 'Beta Agro');

        $this->customerIn($alpha, 'Alpha Farmer');
        $this->customerIn($beta, 'Beta Farmer');

        // Assigned with alpha as their pivot default, which is what would
        // otherwise pull them straight back out of the consolidated view.
        $staff = $this->staffFor([$alpha, $beta]);

        $this->actingAs($staff)
            ->post('/admin/shops/switch', ['shop' => 'all'], ['Accept' => 'application/json'])
            ->assertOk();

        $fresh = $staff->fresh();
        $this->assertNull($fresh->current_shop_id);
        $this->assertTrue($fresh->all_shops_view);

        CurrentShop::forget();
        $this->assertNull(CurrentShop::id());

        $names = Customer::query()->pluck('name');
        $this->assertContains('Alpha Farmer', $names);
        $this->assertContains('Beta Farmer', $names);
    }

    public function test_all_shops_falls_back_when_access_narrows_to_one(): void
    {
        $alpha = $this->shop('ALPHA', 'Alpha Agro');
        $beta = $this->shop('BETA', 'Beta Agro');

        $staff = $this->staffFor([$alpha, $beta]);
        $staff->forceFill(['current_shop_id' => null, 'all_shops_view' => true])->save();

        // Down to one shop: there is nothing left to consolidate.
        $staff->shops()->detach($beta->id);

        $this->actingAs($staff);
        CurrentShop::forget();

        $this->assertSame($alpha->id, CurrentShop::id());
    }

    public function test_switching_to_a_shop_you_have_no_access_to_is_refused(): void
    {
        $alpha = $this->shop('ALPHA', 'Alpha Agro');
        $beta = $this->shop('BETA', 'Beta Agro');

        $staff = $this->staffFor([$alpha]);

        $this->actingAs($staff)
            ->post('/admin/shops/switch', ['shop' => (string) $beta->id], ['Accept' => 'application/json'])
            ->assertStatus(403);

        $this->assertSame($alpha->id, $staff->fresh()->current_shop_id);
    }

    public function test_losing_access_to_the_stored_shop_falls_back_rather_than_leaking(): void
    {
        $alpha = $this->shop('ALPHA', 'Alpha Agro');
        $beta = $this->shop('BETA', 'Beta Agro');

        $staff = $this->staffFor([$alpha, $beta]);
        $staff->forceFill(['current_shop_id' => $beta->id])->save();

        // Access revoked while the stored pointer still names that shop.
        $staff->shops()->detach($beta->id);

        $this->actingAs($staff);
        CurrentShop::forget();

        $this->assertSame($alpha->id, CurrentShop::id());
        $this->assertSame([$alpha->id], CurrentShop::accessibleIds());
    }
}
