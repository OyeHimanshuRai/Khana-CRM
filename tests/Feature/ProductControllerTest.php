<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Setting;
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

    public function test_scanning_an_unknown_barcode_saves_a_draft_once(): void
    {
        $staff = $this->staff(['inventory.products.view', 'inventory.products.create']);

        $first = $this->actingAs($staff)->postJson(route('admin.products.quick-scan'), ['barcode' => '8901234567890']);
        $first->assertOk()->assertJsonPath('data.created', true);

        $product = Product::sole();
        $this->assertSame('8901234567890', $product->barcode);
        $this->assertFalse((bool) $product->is_active);

        $this->actingAs($staff)->postJson(route('admin.products.quick-scan'), ['barcode' => '8901234567890'])
            ->assertOk()
            ->assertJsonPath('data.created', false)
            ->assertJsonPath('data.id', $product->id);

        $this->assertSame(1, Product::count());
    }

    public function test_create_form_opens_with_a_scanned_barcode_filled_in(): void
    {
        $staff = $this->staff(['inventory.products.view', 'inventory.products.create', 'inventory.products.edit']);

        $this->actingAs($staff)->get(route('admin.products.create', ['barcode' => '8901234567890']))
            ->assertOk()
            ->assertSee('value="8901234567890"', false);

        $this->actingAs($staff)->get(route('admin.products.index'))
            ->assertOk()
            ->assertSee('data-product-scan', false);
    }

    public function test_the_list_and_create_screens_render(): void
    {
        $staff = $this->staff(['inventory.products.view', 'inventory.products.create']);

        $this->actingAs($staff)->get(route('admin.products.index'))->assertOk();
        $this->actingAs($staff)->get(route('admin.products.create'))
            ->assertOk()
            ->assertSee('Quick setup')
            ->assertSee('More product details')
            ->assertSee('name="selling_price"', false)
            ->assertSee('name="food_type"', false)
            ->assertSee('id="prod-barcode"', false)
            ->assertSee('for="prod-barcode">Barcode', false)
            ->assertSee('data-product-barcode-scan', false)
            ->assertSee('data-product-barcode-lookup="'.route('admin.products.lookup').'"', false)
            ->assertSeeInOrder([
                'for="prod-unit">Unit',
                'for="prod-min">Minimum stock',
                'name="min_stock"',
                'for="prod-purchase">Purchase price (₹)',
            ])
            ->assertSee('data-ajax data-close-modal data-refresh-list data-client-validate novalidate', false)
            ->assertDontSee('name="sku"', false)
            ->assertSeeInOrder([
                'name="purchase_price"',
                'name="mrp"',
                'name="selling_price"',
                'name="discount_percent"',
                'name="food_type"',
                'name="is_active"',
                'name="is_published"',
                'name="is_featured"',
                'name="sort_order"',
                'name="image"',
            ])
            ->assertSee('<details class="product-more">', false);
    }

    public function test_barcode_lookup_returns_catalogue_fields_for_product_form_prefill(): void
    {
        $staff = $this->staff(['inventory.products.view', 'inventory.products.create']);

        Setting::put([
            'scanner_enabled' => '1',
            'scanner_search_by' => 'barcode_sku',
        ]);

        Product::create([
            'name' => 'Scanned Pantry Item',
            'slug' => Product::uniqueSlug('Scanned Pantry Item'),
            'sku' => 'SCAN-12345',
            'barcode' => '8901234567890',
            'unit_id' => $this->unit->id,
            'manufacturer' => 'Example Foods',
            'purchase_price' => 24.50,
            'mrp' => 36,
            'selling_price' => 32,
            'discount_percent' => 5,
            'min_stock' => 2,
            'hsn_code' => '190590',
            'is_active' => true,
        ]);

        $this->actingAs($staff)
            ->getJson(route('admin.products.lookup', ['q' => '8901234567890']))
            ->assertOk()
            ->assertJsonPath('data.exact.catalog.name', 'Scanned Pantry Item')
            ->assertJsonPath('data.exact.catalog.barcode', '8901234567890')
            ->assertJsonPath('data.exact.catalog.unit_id', $this->unit->id)
            ->assertJsonPath('data.exact.catalog.manufacturer', 'Example Foods')
            ->assertJsonPath('data.exact.catalog.purchase_price', 24.5)
            ->assertJsonPath('data.exact.catalog.mrp', 36)
            ->assertJsonPath('data.exact.catalog.selling_price', 32)
            ->assertJsonPath('data.exact.catalog.discount_percent', 5)
            ->assertJsonPath('data.exact.catalog.min_stock', 2)
            ->assertJsonPath('data.exact.catalog.hsn_code', '190590');

        $this->actingAs($this->staff(['inventory.products.create']))
            ->getJson(route('admin.products.lookup', ['q' => '8901234567890']))
            ->assertOk()
            ->assertJsonPath('data.exact.catalog.name', 'Scanned Pantry Item');
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
