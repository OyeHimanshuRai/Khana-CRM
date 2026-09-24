<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Models\StockMovement;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CartService;
use App\Services\OrderService;
use App\Services\StockService;
use App\Support\CurrentShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Cancelling an order: releases the reservation cleanly when nothing has
 * been invoiced yet, or delegates to InvoiceService::cancel() once it has -
 * see OrderService::cancel().
 */
class OrderCancellationTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private Warehouse $warehouse;

    private StockService $stock;

    private OrderService $orders;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');

        $this->seed();
        CurrentShop::forget();

        $this->shop = Shop::first();
        $this->warehouse = Warehouse::defaultFor($this->shop->id);
        $this->stock = new StockService();
        $this->orders = app(OrderService::class);
    }

    protected function tearDown(): void
    {
        CurrentShop::forget();

        parent::tearDown();
    }

    private function staff(): User
    {
        static $counter = 0;
        $counter++;

        $user = User::create([
            'name' => 'Cancel Staff '.$counter,
            'email' => "cancelstaff{$counter}@example.test",
            'password' => 'staff-password-1',
            'is_admin' => true,
        ]);

        $user->givePermissionTo(['sales.orders.view', 'sales.orders.approve', 'sales.orders.reject']);
        $user->shops()->attach($this->shop->id, ['is_default' => true]);
        $user->forceFill(['current_shop_id' => $this->shop->id])->save();

        return $user;
    }

    private function product(float $stock = 50): Product
    {
        static $counter = 0;
        $counter++;

        $product = Product::create([
            'name' => 'Cancellation Product '.$counter,
            'slug' => Product::uniqueSlug('Cancellation Product '.$counter),
            'sku' => Product::generateSku('CAN'.$counter),
            'unit_id' => Unit::where('code', 'PCS')->firstOrFail()->id,
            'tax_rate_id' => TaxRate::where('name', 'GST 18%')->firstOrFail()->id,
            'purchase_price' => 100,
            'mrp' => 200,
            'selling_price' => 180,
            'tax_inclusive' => true,
            'is_active' => true,
            'is_published' => true,
        ]);

        $this->stock->receive($product, $stock, $this->warehouse, null, 100, StockMovement::PURCHASE, null, null, $this->shop->id);

        return $product;
    }

    private function customer(): Customer
    {
        static $counter = 0;
        $counter++;

        return Customer::create([
            'shop_id' => $this->shop->id,
            'code' => Customer::nextCode($this->shop->id),
            'name' => 'Cancellation Customer '.$counter,
            'mobile' => '93000000'.str_pad((string) $counter, 2, '0', STR_PAD_LEFT),
            'password' => Hash::make('password123'),
            'type' => 'retail',
            'is_active' => true,
        ]);
    }

    private function placeOrder(Product $product, Customer $customer, float $qty): Order
    {
        app(CartService::class)->add($this->shop, $customer, request(), $product, $qty);

        return $this->orders->place($this->shop, $customer, request(), [
            'address' => [
                'recipient_name' => $customer->name,
                'mobile' => '9876543210',
                'address_line1' => 'Farm Road 1',
            ],
            'payment_method' => 'cod',
        ]);
    }

    public function test_cancelling_a_pending_order_fully_releases_its_reservation(): void
    {
        $product = $this->product(50);
        $customer = $this->customer();
        $order = $this->placeOrder($product, $customer, 4);

        $this->assertEqualsWithDelta(46.0, $product->fresh()->availableStock($this->shop->id), 0.01);

        $staff = $this->staff();
        $response = $this->actingAs($staff)->putJson(route('admin.orders.cancel', $order), [
            'reason' => 'Customer changed their mind',
        ]);

        $response->assertOk()->assertJsonPath('success', true);

        $this->assertSame(Order::CANCELLED, $order->fresh()->status);
        $this->assertEqualsWithDelta(50.0, $product->fresh()->availableStock($this->shop->id), 0.01);
        $this->assertEqualsWithDelta(50.0, $product->fresh()->stockOnHand($this->shop->id), 0.01);
    }

    public function test_cancelling_an_invoiced_order_reverses_the_invoice(): void
    {
        $product = $this->product(50);
        $customer = $this->customer();
        $order = $this->placeOrder($product, $customer, 3);
        $staff = $this->staff();

        $this->orders->confirm($order, $staff);
        $this->orders->markPaid($order, $staff, ['amount' => (float) $order->grand_total, 'method' => 'cash']);

        $order->refresh();
        $this->assertNotNull($order->invoice_id);
        $this->assertEqualsWithDelta(47.0, $product->fresh()->stockOnHand($this->shop->id), 0.01);

        $response = $this->actingAs($staff)->putJson(route('admin.orders.cancel', $order), [
            'reason' => 'Customer returned everything',
        ]);

        $response->assertOk()->assertJsonPath('success', true);

        $order->refresh();
        $this->assertSame(Order::CANCELLED, $order->status);
        $this->assertTrue($order->invoice->fresh()->isCancelled());

        // Stock returned via the invoice reversal.
        $this->assertEqualsWithDelta(50.0, $product->fresh()->stockOnHand($this->shop->id), 0.01);
    }

    public function test_cancelling_requires_a_reason(): void
    {
        $product = $this->product();
        $customer = $this->customer();
        $order = $this->placeOrder($product, $customer, 1);
        $staff = $this->staff();

        $response = $this->actingAs($staff)->putJson(route('admin.orders.cancel', $order), ['reason' => '']);

        $response->assertUnprocessable();
        $this->assertNotSame(Order::CANCELLED, $order->fresh()->status);
    }
}
