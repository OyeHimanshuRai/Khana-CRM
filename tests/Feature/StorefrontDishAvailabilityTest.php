<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Shop;
use App\Models\TaxRate;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * A dish has no shelf, so the storefront must not ask it how many are on one.
 *
 * It did: every made-to-order dish read as "Out of stock" with a disabled Add
 * button, because availableStock() is zero for a product nothing is ever
 * received into - while OrderService would have accepted the order. Shelf
 * items keep the old rule; dishes follow the kitchen's sold-out tap, the same
 * answer the till gives.
 */
class StorefrontDishAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');
        $this->seed();
        $this->shop = Shop::first();
    }

    private function product(array $attributes = []): Product
    {
        static $counter = 0;
        $counter++;

        return Product::create([
            'name' => 'Availability Item '.$counter,
            'slug' => Product::uniqueSlug('Availability Item '.$counter),
            'sku' => Product::generateSku('AVL'.$counter),
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

    public function test_a_dish_with_no_shelf_is_not_blocked(): void
    {
        $dish = $this->product(['is_made_to_order' => true]);

        $this->assertNull($dish->storefrontBlock($this->shop->id));
    }

    public function test_a_dish_the_kitchen_has_sold_out_says_so(): void
    {
        $dish = $this->product(['is_made_to_order' => true, 'is_sold_out' => true]);

        $this->assertSame('Sold out', $dish->storefrontBlock($this->shop->id));
    }

    public function test_a_shelf_item_with_nothing_available_is_out_of_stock(): void
    {
        $item = $this->product(['is_made_to_order' => false]);

        $this->assertSame('Out of stock', $item->storefrontBlock($this->shop->id));
    }

    public function test_the_catalogue_offers_a_dish_for_sale(): void
    {
        $dish = $this->product(['is_made_to_order' => true, 'name' => 'Tandoori Platter']);

        $html = $this->get(route('shop.catalog', $this->shop))->assertOk()->getContent();

        $this->assertStringContainsString('Tandoori Platter', $html);
        $this->assertStringNotContainsString('Out of stock', $html);
        $this->assertStringNotContainsString('disabled', $this->cardFor($html, 'Tandoori Platter'));
    }

    public function test_the_product_page_for_a_dish_can_be_added_to_the_cart(): void
    {
        $dish = $this->product(['is_made_to_order' => true]);

        $this->get(route('shop.product', [$this->shop, $dish]))
            ->assertOk()
            ->assertDontSee('Out of stock')
            ->assertSee('Available');
    }

    /** The slice of the page holding one product's card, so a disabled button elsewhere cannot pass or fail it. */
    private function cardFor(string $html, string $name): string
    {
        $start = strpos($html, $name);
        $this->assertNotFalse($start, "{$name} is not on the page");

        return substr($html, $start, 900);
    }
}
