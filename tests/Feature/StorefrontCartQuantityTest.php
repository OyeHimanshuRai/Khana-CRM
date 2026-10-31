<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Shop;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Services\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The storefront cart counts the way the counter does.
 *
 * A dish sold by the piece cannot be bought by the half, and nobody orders
 * a million of anything from a restaurant's website: the cart took 0.001 of
 * a pizza and 1,000,000 biryanis alike. Quantities now follow the product's
 * unit (whole numbers unless the unit allows decimals, as the POS already
 * does) and stop at a ceiling per line.
 */
class StorefrontCartQuantityTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');
        $this->seed();
        $this->shop = Shop::query()->withoutGlobalScopes()->orderBy('id')->firstOrFail();
    }

    private function product(bool $decimal = false): Product
    {
        $unit = Unit::query()->where('allow_decimal', $decimal)->firstOrFail();

        return Product::create([
            'name' => 'Cart Dish '.uniqid(), 'slug' => Product::uniqueSlug('Cart Dish '.uniqid()), 'sku' => Product::generateSku('CRT'.uniqid()),
            'unit_id' => $unit->id, 'tax_rate_id' => TaxRate::where('name', 'GST 18%')->firstOrFail()->id,
            'purchase_price' => 100, 'mrp' => 200, 'selling_price' => 180, 'tax_inclusive' => true,
            'is_active' => true, 'is_published' => true, 'is_made_to_order' => true,
        ]);
    }

    private function quantityIn(Product $product): float
    {
        $line = app(CartService::class)
            ->contents($this->shop, null, request())['items']
            ->firstWhere(fn ($l) => $l['product']->id === $product->id);

        return (float) ($line['quantity'] ?? 0);
    }

    private function add(Product $product, $quantity)
    {
        return $this->postJson(route('shop.cart.add', $this->shop), ['product_id' => $product->id, 'quantity' => $quantity]);
    }

    public function test_a_dish_sold_by_the_piece_is_bought_in_whole_pieces(): void
    {
        $dish = $this->product();

        $this->add($dish, 1.6)->assertOk();

        $this->get(route('shop.cart', $this->shop))->assertOk();
        $this->assertSame(2.0, (float) session('storefront.cart.'.$this->shop->id)[$dish->id]);
    }

    public function test_less_than_one_piece_is_refused(): void
    {
        $dish = $this->product();

        $this->add($dish, 0.3)->assertStatus(422)->assertJsonValidationErrors('quantity');
    }

    public function test_a_unit_that_allows_decimals_keeps_them(): void
    {
        $paneer = $this->product(decimal: true);

        $this->add($paneer, 1.25)->assertOk();
        $this->assertSame(1.25, (float) session('storefront.cart.'.$this->shop->id)[$paneer->id]);
    }

    public function test_a_line_cannot_go_past_the_ceiling(): void
    {
        $dish = $this->product();

        $this->add($dish, 1000000)->assertStatus(422)->assertJsonValidationErrors('quantity');

        // Nor by adding a little at a time.
        $this->add($dish, CartService::MAX_LINE_QUANTITY)->assertOk();
        $this->add($dish, 1)->assertStatus(422)->assertJsonValidationErrors('quantity');
        $this->assertSame((float) CartService::MAX_LINE_QUANTITY, (float) session('storefront.cart.'.$this->shop->id)[$dish->id]);
    }

    public function test_changing_a_quantity_in_the_cart_follows_the_same_rules(): void
    {
        $dish = $this->product();
        $this->add($dish, 2)->assertOk();

        $this->patchJson(route('shop.cart.update', [$this->shop, $dish]), ['quantity' => 2.4])->assertOk();
        $this->assertSame(2.0, (float) session('storefront.cart.'.$this->shop->id)[$dish->id]);

        $this->patchJson(route('shop.cart.update', [$this->shop, $dish]), ['quantity' => 5000])
            ->assertStatus(422)->assertJsonValidationErrors('quantity');
    }

    public function test_signing_in_with_a_guest_cart_never_fails_and_never_passes_the_ceiling(): void
    {
        $dish = $this->product();
        $customer = \App\Models\Customer::create([
            'shop_id' => $this->shop->id, 'code' => \App\Models\Customer::nextCode($this->shop->id),
            'name' => 'Regular', 'mobile' => '9000000099', 'email' => 'regular@example.test',
            'password' => \Illuminate\Support\Facades\Hash::make('password123'), 'type' => 'retail', 'is_active' => true,
        ]);
        $cart = \App\Models\Cart::query()->create(['shop_id' => $this->shop->id, 'customer_id' => $customer->id]);
        $cart->items()->create(['product_id' => $dish->id, 'quantity' => 80]);

        $this->add($dish, 50)->assertOk();
        $this->post(route('shop.login.store', $this->shop), ['login' => $customer->mobile, 'password' => 'password123'])
            ->assertRedirect();

        $this->assertSame((float) CartService::MAX_LINE_QUANTITY, (float) $cart->items()->first()->quantity);
    }
}
