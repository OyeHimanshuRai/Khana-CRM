<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Models\StockMovement;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\StockService;
use App\Support\CurrentShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Checkout: cart -> Order, with stock reserved (not issued) and no Invoice
 * raised yet - see the Order model's docblock for why the two documents
 * are kept apart.
 */
class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private Warehouse $warehouse;

    private StockService $stock;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');

        $this->seed();
        CurrentShop::forget();

        $this->shop = Shop::first();
        $this->warehouse = Warehouse::defaultFor($this->shop->id);
        $this->stock = new StockService();
    }

    protected function tearDown(): void
    {
        CurrentShop::forget();

        parent::tearDown();
    }

    private function product(float $stock = 50): Product
    {
        static $counter = 0;
        $counter++;

        $product = Product::create([
            'name' => 'Checkout Product '.$counter,
            'slug' => Product::uniqueSlug('Checkout Product '.$counter),
            'sku' => Product::generateSku('CHK'.$counter),
            'unit_id' => Unit::where('code', 'PCS')->firstOrFail()->id,
            'tax_rate_id' => TaxRate::where('name', 'GST 18%')->firstOrFail()->id,
            'purchase_price' => 100,
            'mrp' => 200,
            'selling_price' => 180,
            'tax_inclusive' => true,
            'is_active' => true,
            'is_published' => true,
        ]);

        if ($stock > 0) {
            $this->stock->receive($product, $stock, $this->warehouse, null, 100, StockMovement::PURCHASE, null, null, $this->shop->id);
        }

        return $product;
    }

    private function customer(array $attributes = []): Customer
    {
        static $counter = 0;
        $counter++;

        return Customer::create([
            'shop_id' => $this->shop->id,
            'code' => Customer::nextCode($this->shop->id),
            'name' => 'Checkout Customer '.$counter,
            'mobile' => '91000000'.str_pad((string) $counter, 2, '0', STR_PAD_LEFT),
            'password' => Hash::make('password123'),
            'type' => 'retail',
            'is_active' => true,
            ...$attributes,
        ]);
    }

    /**
     * Sign a customer in through the real HTTP login flow rather than
     * actingAs('customer'): actingAs() also calls Auth::shouldUse(), which
     * changes what the bare Auth:: facade resolves to for the rest of the
     * test - and BelongsToShop's saving() guard (correctly) calls the bare
     * facade in production, expecting it to mean the `web` guard. Logging
     * in for real avoids that test-only side effect entirely.
     */
    private function loginAs(Customer $customer): void
    {
        $this->post(route('shop.login.store', $this->shop), [
            'login' => $customer->mobile,
            'password' => 'password123',
        ])->assertRedirect();
    }

    /** @return array<string, mixed> */
    private function checkoutPayload(array $overrides = []): array
    {
        return [
            'recipient_name' => 'Test Farmer',
            'mobile' => '9876543210',
            'address_line1' => 'Farm Road 1',
            'city' => 'Test City',
            'state' => 'Test State',
            'pincode' => '123456',
            'payment_method' => 'cod',
            ...$overrides,
        ];
    }

    public function test_cod_checkout_creates_a_pending_order_and_reserves_stock_without_invoicing(): void
    {
        $product = $this->product();
        $customer = $this->customer();
        $this->loginAs($customer);

        $this->post(route('shop.cart.add', $this->shop), [
            'product_id' => $product->id,
            'quantity' => 3,
        ]);

        $response = $this->post(route('shop.checkout.store', $this->shop), $this->checkoutPayload());

        $order = Order::forShop($this->shop->id)->where('customer_id', $customer->id)->firstOrFail();

        $response->assertRedirect(route('shop.orders.show', ['shop' => $this->shop, 'order' => $order->id]));

        $this->assertSame(Order::PENDING, $order->status);
        $this->assertSame(Order::PAYMENT_PENDING, $order->payment_status);
        $this->assertNull($order->invoice_id);
        $this->assertSame(0, Invoice::allShops()->count());

        $this->assertEqualsWithDelta(47.0, $product->fresh()->availableStock($this->shop->id), 0.01);
        $this->assertEqualsWithDelta(50.0, $product->fresh()->stockOnHand($this->shop->id), 0.01);
    }

    public function test_a_valid_coupon_reduces_the_order_total_correctly(): void
    {
        $product = $this->product();
        $customer = $this->customer();
        $this->loginAs($customer);

        Coupon::create([
            'shop_id' => $this->shop->id,
            'code' => 'SAVE10',
            'type' => Coupon::PERCENT,
            'value' => 10,
            'min_order_amount' => 0,
            'usage_limit_per_customer' => 1,
            'is_active' => true,
        ]);

        $this->post(route('shop.cart.add', $this->shop), [
            'product_id' => $product->id,
            'quantity' => 2,
        ]);

        $this->post(route('shop.checkout.store', $this->shop), $this->checkoutPayload(['coupon_code' => 'SAVE10']))
            ->assertRedirect();

        $order = Order::forShop($this->shop->id)->where('customer_id', $customer->id)->firstOrFail();

        $this->assertEqualsWithDelta(360.0, (float) $order->subtotal, 0.01);
        $this->assertEqualsWithDelta(36.0, (float) $order->discount_total, 0.01);
        $this->assertEqualsWithDelta(324.0, (float) $order->grand_total, 0.01);
    }

    public function test_checking_out_more_than_available_stock_is_refused_and_leaves_no_reservation(): void
    {
        $product = $this->product(5);
        $customer = $this->customer();
        $this->loginAs($customer);

        $this->post(route('shop.cart.add', $this->shop), [
            'product_id' => $product->id,
            'quantity' => 100,
        ]);

        $this->post(route('shop.checkout.store', $this->shop), $this->checkoutPayload())
            ->assertRedirect();

        $this->assertSame(0, Order::forShop($this->shop->id)->where('customer_id', $customer->id)->count());
        $this->assertEqualsWithDelta(5.0, $product->fresh()->availableStock($this->shop->id), 0.01);
    }

    public function test_a_shop_a_customer_cannot_check_out_against_shop_b(): void
    {
        $shopB = Shop::create([
            'name' => 'Second Shop', 'code' => 'SHOP2', 'slug' => 'second-shop',
            'invoice_prefix' => 'INV', 'pos_prefix' => 'POS',
            'currency' => 'INR', 'timezone' => 'Asia/Kolkata', 'is_active' => true,
        ]);

        $customer = $this->customer();

        $response = $this->actingAs($customer, 'customer')->get(route('shop.checkout', $shopB));

        $response->assertRedirect(route('shop.login', $shopB));
    }
}
