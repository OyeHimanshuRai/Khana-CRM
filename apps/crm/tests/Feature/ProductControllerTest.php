<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Shop;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\User;
use App\Support\CurrentShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The product master over HTTP - create, edit and (guarded) delete, plus
 * the one rule worth a direct test: a product still holding stock anywhere
 * cannot be removed.
 */
class ProductControllerTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');

        $this->seed();
        CurrentShop::forget();

        $this->shop = Shop::firstOrFail();
        $this->unit = Unit::where('code', 'PCS')->firstOrFail();
    }

    protected function tearDown(): void
    {
        CurrentShop::forget();

        parent::tearDown();
    }

    private function staff(array $permissions): User
    {
        static $counter = 0;
        $counter++;

        $user = User::create([
            'name' => 'Product Staff '.$counter,
            'email' => "productstaff{$counter}@example.test",
            'password' => 'staff-password-1',
            'is_admin' => true,
        ]);

        $user->givePermissionTo($permissions);
        $user->shops()->attach($this->shop->id, ['is_default' => true]);
        $user->forceFill(['current_shop_id' => $this->shop->id])->save();

        return $user;
    }

    public function test_the_list_and_create_screens_render(): void
    {
        $staff = $this->staff(['inventory.products.view', 'inventory.products.create']);

        $this->actingAs($staff)->get(route('admin.products.index'))->assertOk();
        $this->actingAs($staff)->get(route('admin.products.create'))->assertOk();
    }

    public function test_a_staff_member_can_create_a_product(): void
    {
        $staff = $this->staff(['inventory.products.create']);

        $response = $this->actingAs($staff)->postJson(route('admin.products.store'), [
            'name' => 'Test Neem Oil',
            'unit_id' => $this->unit->id,
            'selling_price' => 249.50,
            'purchase_price' => 180,
            'mrp' => 299,
            'is_active' => 1,
        ]);

        $response->assertOk()->assertJsonPath('success', true);

        $product = Product::sole();
        $this->assertSame('Test Neem Oil', $product->name);
        $this->assertNotEmpty($product->slug);
        $this->assertNotEmpty($product->sku);
        $this->assertEqualsWithDelta(249.50, (float) $product->selling_price, 0.01);
    }

    public function test_creating_without_a_name_is_refused(): void
    {
        $staff = $this->staff(['inventory.products.create']);

        $this->actingAs($staff)->postJson(route('admin.products.store'), [
            'unit_id' => $this->unit->id,
            'selling_price' => 100,
        ])->assertUnprocessable();

        $this->assertSame(0, Product::count());
    }

    public function test_a_duplicate_sku_is_refused(): void
    {
        $staff = $this->staff(['inventory.products.create']);

        Product::create([
            'name' => 'First', 'slug' => Product::uniqueSlug('First'),
            'sku' => 'DUPSKU', 'unit_id' => $this->unit->id, 'selling_price' => 100,
        ]);

        $this->actingAs($staff)->postJson(route('admin.products.store'), [
            'name' => 'Second', 'sku' => 'DUPSKU',
            'unit_id' => $this->unit->id, 'selling_price' => 120,
        ])->assertUnprocessable();

        $this->assertSame(1, Product::count());
    }

    public function test_a_staff_member_can_update_a_product(): void
    {
        $staff = $this->staff(['inventory.products.edit']);

        $product = Product::create([
            'name' => 'Old Name', 'slug' => Product::uniqueSlug('Old Name'),
            'sku' => Product::generateSku('OLD'), 'unit_id' => $this->unit->id, 'selling_price' => 100,
        ]);

        $response = $this->actingAs($staff)->putJson(route('admin.products.update', $product), [
            'name' => 'New Name',
            'unit_id' => $this->unit->id,
            'selling_price' => 150,
        ]);

        $response->assertOk()->assertJsonPath('success', true);
        $this->assertSame('New Name', $product->fresh()->name);
        $this->assertEqualsWithDelta(150.0, (float) $product->fresh()->selling_price, 0.01);
    }

    public function test_a_product_holding_stock_cannot_be_deleted(): void
    {
        $staff = $this->staff(['inventory.products.delete']);

        $product = Product::create([
            'name' => 'Stocked Product', 'slug' => Product::uniqueSlug('Stocked Product'),
            'sku' => Product::generateSku('STK'), 'unit_id' => $this->unit->id, 'selling_price' => 100,
        ]);

        $warehouse = \App\Models\Warehouse::allShops()->where('shop_id', $this->shop->id)->firstOrFail();
        app(\App\Services\StockService::class)->receive(
            $product, 10, $warehouse, null, 50, \App\Models\StockMovement::PURCHASE, null, null, $this->shop->id
        );

        $response = $this->actingAs($staff)->deleteJson(route('admin.products.destroy', $product));

        $response->assertStatus(422)->assertJsonPath('success', false);
        $this->assertNotNull($product->fresh());
    }

    public function test_a_product_with_no_stock_can_be_deleted(): void
    {
        $staff = $this->staff(['inventory.products.delete']);

        $product = Product::create([
            'name' => 'Empty Product', 'slug' => Product::uniqueSlug('Empty Product'),
            'sku' => Product::generateSku('EMP'), 'unit_id' => $this->unit->id, 'selling_price' => 100,
        ]);

        $response = $this->actingAs($staff)->deleteJson(route('admin.products.destroy', $product));

        $response->assertOk()->assertJsonPath('success', true);
        $this->assertSoftDeleted($product);
    }

    public function test_staff_without_permission_cannot_create_a_product(): void
    {
        $staff = $this->staff([]);

        $this->actingAs($staff)->postJson(route('admin.products.store'), [
            'name' => 'Blocked', 'unit_id' => $this->unit->id, 'selling_price' => 100,
        ])->assertForbidden();

        $this->assertSame(0, Product::count());
    }
}
