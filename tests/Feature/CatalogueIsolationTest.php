<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Floor;
use App\Models\Product;
use App\Models\RestaurantTable;
use App\Models\Shop;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TableQrService;
use App\Support\CurrentShop;
use App\Support\CurrentTenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Each restaurant's menu is its own.
 *
 * Products, categories and brands were once global, so a restaurant that had
 * just signed up opened Products and found another company's dishes - and a
 * guest at its tables could order them. What is held here: a company sees,
 * validates against and serves to its guests only its own catalogue, and two
 * companies can name things the same way without colliding.
 */
class CatalogueIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $ours;

    private Tenant $theirs;

    private Shop $theirShop;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');

        $this->seed();

        $this->ours = Tenant::query()->orderBy('id')->firstOrFail();
        $this->theirs = Tenant::create(['name' => 'Rival Foods', 'code' => 'RIVAL', 'slug' => 'rival-foods']);
        $this->theirShop = Shop::query()->create([
            'tenant_id' => $this->theirs->id, 'name' => 'Rival Kitchen', 'code' => 'RK',
            'slug' => 'rival-kitchen', 'is_active' => true,
        ]);
    }

    private function dish(Tenant $tenant, string $name, string $sku): Product
    {
        $product = new Product([
            'name' => $name, 'slug' => Str::slug($name), 'sku' => $sku,
            'selling_price' => 120, 'is_active' => true, 'is_made_to_order' => true,
        ]);
        $product->tenant_id = $tenant->id;
        $product->save();

        return $product;
    }

    private function rivalOwner(): User
    {
        $owner = User::create([
            'tenant_id' => $this->theirs->id, 'name' => 'Rival Owner', 'email' => 'owner@rival.test',
            'password' => 'owner-password-1', 'is_admin' => true,
        ]);
        $owner->shops()->attach($this->theirShop->id);
        $owner->givePermissionTo(['dashboard.overview.view', 'inventory.products.view']);

        return $owner->fresh();
    }

    private function signIn(User $user): void
    {
        $this->actingAs($user);
        CurrentTenant::forget();
        CurrentShop::forget();
    }

    /* ------------------------------------------------------------ staff */

    public function test_a_restaurant_reads_only_its_own_catalogue(): void
    {
        $this->dish($this->ours, 'Zz Our Paneer', 'OUR-1');
        $this->dish($this->theirs, 'Zz Their Dal', 'THEIR-1');

        $this->signIn($this->rivalOwner());

        $this->assertSame(['Zz Their Dal'], Product::query()->where('name', 'like', 'Zz %')->pluck('name')->all());
        $this->assertNull(Product::query()->where('sku', 'OUR-1')->first());
    }

    public function test_the_products_screen_does_not_list_another_restaurants_dishes(): void
    {
        $this->dish($this->ours, 'Zz Our Paneer', 'OUR-1');
        $this->dish($this->theirs, 'Zz Their Dal', 'THEIR-1');

        $this->signIn($this->rivalOwner());

        $this->get(route('admin.products.index'))
            ->assertOk()
            ->assertSee('Zz Their Dal')
            ->assertDontSee('Zz Our Paneer');
    }

    public function test_what_a_restaurant_creates_is_stamped_as_its_own(): void
    {
        $this->signIn($this->rivalOwner());

        $category = Category::create(['name' => 'Rival Starters', 'slug' => 'rival-starters', 'is_active' => true]);
        $product = Product::create(['name' => 'Rival Roll', 'slug' => 'rival-roll', 'sku' => 'RR-1', 'selling_price' => 90]);

        $this->assertSame($this->theirs->id, $category->tenant_id);
        $this->assertSame($this->theirs->id, $product->tenant_id);
    }

    public function test_the_super_admin_sees_every_restaurant_in_the_all_companies_view(): void
    {
        $this->dish($this->ours, 'Zz Our Paneer', 'OUR-1');
        $this->dish($this->theirs, 'Zz Their Dal', 'THEIR-1');

        $admin = User::query()->withoutGlobalScopes()->where('email', 'admin@erp.test')->firstOrFail();
        $this->signIn($admin);
        CurrentTenant::set(null);

        $this->assertSame(2, Product::query()->where('name', 'like', 'Zz %')->count());
    }

    /* -------------------------------------------------- names and rules */

    public function test_two_restaurants_may_use_the_same_sku_and_slug(): void
    {
        $this->dish($this->ours, 'Paneer Tikka', 'PT-01');
        $this->dish($this->theirs, 'Paneer Tikka', 'PT-01');

        $this->assertSame(2, Product::allTenants()->where('sku', 'PT-01')->count());

        // ...but not twice within one.
        $this->expectException(QueryException::class);
        $this->dish($this->theirs, 'Paneer Tikka', 'PT-01');
    }

    public function test_uniqueness_is_checked_within_the_restaurant(): void
    {
        $this->dish($this->ours, 'Paneer Tikka', 'PT-01');

        $this->signIn($this->rivalOwner());

        $rule = ['sku' => [Product::uniqueRule('sku')]];

        $this->assertTrue(Validator::make(['sku' => 'PT-01'], $rule)->passes(), 'Another company\'s SKU is free to reuse.');

        $this->dish($this->theirs, 'Paneer Tikka', 'PT-01');

        $this->assertTrue(Validator::make(['sku' => 'PT-01'], $rule)->fails(), 'The company\'s own SKU is taken.');
    }

    public function test_another_restaurants_product_id_does_not_validate(): void
    {
        $ourDish = $this->dish($this->ours, 'Zz Our Paneer', 'OUR-1');
        $theirDish = $this->dish($this->theirs, 'Zz Their Dal', 'THEIR-1');

        $this->signIn($this->rivalOwner());

        $rule = ['product_id' => [Product::existsRule()]];

        $this->assertTrue(Validator::make(['product_id' => $theirDish->id], $rule)->passes());
        $this->assertTrue(Validator::make(['product_id' => $ourDish->id], $rule)->fails());
    }

    /* ------------------------------------------------------------ guests */

    private function tableAt(Shop $shop): RestaurantTable
    {
        $floor = Floor::query()->firstOrCreate(
            ['shop_id' => $shop->id, 'code' => 'GF'],
            ['name' => 'Ground Floor', 'is_active' => true],
        );

        $table = new RestaurantTable([
            'shop_id' => $shop->id, 'floor_id' => $floor->id, 'name' => '04', 'code' => 'GF-04',
            'capacity' => 4, 'status' => RestaurantTable::AVAILABLE, 'is_active' => true,
        ]);
        $table->save();

        app(TableQrService::class)->issue($table);

        return $table->fresh(['activeQr']);
    }

    public function test_a_guest_sees_only_that_restaurants_menu_and_cannot_order_another_s(): void
    {
        $ourDish = $this->dish($this->ours, 'Zz Our Paneer', 'OUR-1');
        $theirDish = $this->dish($this->theirs, 'Zz Their Dal', 'THEIR-1');

        $table = $this->tableAt(Shop::query()->where('tenant_id', $this->ours->id)->orderBy('id')->firstOrFail());

        $this->get('/t/'.$table->activeQr->token)->assertRedirect(route('table.show'));

        $this->get(route('table.show'))
            ->assertOk()
            ->assertSee($ourDish->name)
            ->assertDontSee($theirDish->name);

        $this->from(route('table.show'))
            ->post(route('table.cart.add'), ['product_id' => $theirDish->id])
            ->assertSessionHas('table_error', 'That is not on the menu.');

        $this->from(route('table.show'))
            ->post(route('table.cart.add'), ['product_id' => $ourDish->id])
            ->assertSessionHas('table_notice');
    }
}
