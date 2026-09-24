<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Shop;
use App\Models\TaxRate;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The public catalogue a guest sees on a shop's storefront.
 *
 * The one fact worth proving directly: product_shop is opt-out, not
 * opt-in (see its migration's docblock) - a product with no override row
 * for a shop must still show there, and one explicitly switched off for a
 * shop must disappear only there, not everywhere.
 */
class StorefrontCatalogTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shopA;

    private Shop $shopB;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');

        $this->seed();

        $this->shopA = Shop::first();
        $this->shopB = Shop::create([
            'name' => 'Second Shop', 'code' => 'SHOP2', 'slug' => 'second-shop',
            'invoice_prefix' => 'INV', 'pos_prefix' => 'POS',
            'currency' => 'INR', 'timezone' => 'Asia/Kolkata', 'is_active' => true,
        ]);
    }

    private function product(array $attributes = []): Product
    {
        static $counter = 0;
        $counter++;

        return Product::create([
            'name' => 'Catalogue Product '.$counter,
            'slug' => Product::uniqueSlug('Catalogue Product '.$counter),
            'sku' => Product::generateSku('CAT'.$counter),
            'unit_id' => Unit::where('code', 'PCS')->firstOrFail()->id,
            'tax_rate_id' => TaxRate::where('name', 'GST 18%')->firstOrFail()->id,
            'purchase_price' => 100,
            'mrp' => 200,
            'selling_price' => 180,
            'tax_inclusive' => true,
            'is_active' => true,
            'is_published' => true,
            ...$attributes,
        ]);
    }

    public function test_a_product_never_customised_for_a_shop_is_still_available_there(): void
    {
        $product = $this->product();

        $this->assertTrue(
            Product::query()->availableAt($this->shopA->id)->whereKey($product->id)->exists()
        );
    }

    public function test_a_product_switched_off_for_one_shop_disappears_only_there(): void
    {
        $product = $this->product();
        $product->shops()->attach($this->shopA->id, ['is_active' => false]);

        $this->assertFalse(
            Product::query()->availableAt($this->shopA->id)->whereKey($product->id)->exists()
        );

        $this->assertTrue(
            Product::query()->availableAt($this->shopB->id)->whereKey($product->id)->exists()
        );
    }

    public function test_guest_can_browse_the_shop_home_page(): void
    {
        $this->product(['is_featured' => true]);

        $this->get(route('shop.home', $this->shopA))->assertOk();
    }

    public function test_guest_can_browse_and_search_the_catalog(): void
    {
        $this->product(['name' => 'Neem Oil Spray']);
        $this->product(['name' => 'Wheat Seed Bag']);

        $this->get(route('shop.catalog', $this->shopA))->assertOk();
        $this->get(route('shop.catalog', $this->shopA).'?q=Neem')->assertOk();
    }

    public function test_guest_can_view_a_product_detail_page(): void
    {
        $product = $this->product();

        $this->get(route('shop.product', [$this->shopA, $product]))->assertOk();
    }

    public function test_a_deactivated_shop_storefront_404s(): void
    {
        $this->shopA->forceFill(['is_active' => false])->save();

        $this->get(route('shop.home', $this->shopA))->assertNotFound();
    }

    public function test_an_unpublished_product_page_404s(): void
    {
        $product = $this->product(['is_published' => false]);

        $this->get(route('shop.product', [$this->shopA, $product]))->assertNotFound();
    }

    public function test_category_listing_only_shows_that_category(): void
    {
        $categoryA = Category::create(['name' => 'Fertilisers', 'slug' => 'fertilisers', 'is_active' => true]);
        $categoryB = Category::create(['name' => 'Pesticides', 'slug' => 'pesticides', 'is_active' => true]);

        $this->product(['name' => 'Urea Bag', 'category_id' => $categoryA->id]);
        $this->product(['name' => 'Bug Spray', 'category_id' => $categoryB->id]);

        $response = $this->get(route('shop.category', [$this->shopA, $categoryA]));

        $response->assertOk();
        $response->assertSee('Urea Bag');
        $response->assertDontSee('Bug Spray');
    }
}
