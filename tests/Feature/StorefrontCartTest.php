<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Shop;
use App\Models\TaxRate;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The storefront cart: a session-backed one for a guest, folded into the
 * customer's own database cart the moment they sign in or register - see
 * CartService::mergeIntoCustomer().
 */
class StorefrontCartTest extends TestCase
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

    private function product(): Product
    {
        static $counter = 0;
        $counter++;

        return Product::create([
            'name' => 'Cart Product '.$counter,
            'slug' => Product::uniqueSlug('Cart Product '.$counter),
            'sku' => Product::generateSku('CRT'.$counter),
            'unit_id' => Unit::where('code', 'PCS')->firstOrFail()->id,
            'tax_rate_id' => TaxRate::where('name', 'GST 18%')->firstOrFail()->id,
            'purchase_price' => 100,
            'mrp' => 200,
            'selling_price' => 180,
            'tax_inclusive' => true,
            'is_active' => true,
            'is_published' => true,
        ]);
    }

    private function customer(array $attributes = []): Customer
    {
        static $counter = 0;
        $counter++;

        return Customer::create([
            'shop_id' => $this->shop->id,
            'code' => Customer::nextCode($this->shop->id),
            'name' => 'Cart Customer '.$counter,
            'mobile' => '90000000'.str_pad((string) $counter, 2, '0', STR_PAD_LEFT),
            'email' => "cartcustomer{$counter}@example.test",
            'password' => Hash::make('password123'),
            'type' => 'retail',
            'is_active' => true,
            ...$attributes,
        ]);
    }

    public function test_a_guest_can_add_a_product_to_the_session_cart(): void
    {
        $product = $this->product();

        $this->post(route('shop.cart.add', $this->shop), [
            'product_id' => $product->id,
            'quantity' => 2,
        ])->assertRedirect();

        $this->get(route('shop.cart', $this->shop))
            ->assertOk()
            ->assertSee($product->name);
    }

    public function test_a_guest_cart_survives_across_requests_in_the_same_session(): void
    {
        $product = $this->product();

        $this->withSession([])->post(route('shop.cart.add', $this->shop), [
            'product_id' => $product->id,
            'quantity' => 3,
        ]);

        $response = $this->get(route('shop.cart', $this->shop));

        $response->assertOk()->assertSee('₹'.number_format(180 * 3, 2));
    }

    public function test_a_guest_cart_merges_into_the_customer_cart_on_login(): void
    {
        $product = $this->product();
        $customer = $this->customer();

        $this->post(route('shop.cart.add', $this->shop), [
            'product_id' => $product->id,
            'quantity' => 2,
        ]);

        $this->post(route('shop.login.store', $this->shop), [
            'login' => $customer->mobile,
            'password' => 'password123',
        ])->assertRedirect();

        $cart = Cart::query()->where('shop_id', $this->shop->id)->where('customer_id', $customer->id)->firstOrFail();

        $this->assertSame(1, $cart->items()->count());
        $this->assertEqualsWithDelta(2.0, (float) $cart->items()->first()->quantity, 0.001);
    }

    public function test_a_logged_in_customers_cart_is_stored_in_the_database(): void
    {
        $product = $this->product();
        $customer = $this->customer();

        $this->actingAs($customer, 'customer')->post(route('shop.cart.add', $this->shop), [
            'product_id' => $product->id,
            'quantity' => 1,
        ]);

        $this->assertDatabaseHas('cart_items', [
            'product_id' => $product->id,
        ]);
    }
}
