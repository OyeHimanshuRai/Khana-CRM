<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Models\StockMovement;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\OrderService;
use App\Services\StockService;
use App\Support\CurrentShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Staff confirming and paying an online order - the point at which the
 * real Invoice is raised (OrderService::markPaid()) and stock actually
 * leaves the shelf via InvoiceService/StockService, not before.
 */
class OrderFulfilmentTest extends TestCase
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

    private function staff(array $permissions = []): User
    {
        static $counter = 0;
        $counter++;

        $user = User::create([
            'name' => 'Order Staff '.$counter,
            'email' => "orderstaff{$counter}@example.test",
            'password' => 'staff-password-1',
            'is_admin' => true,
        ]);

        $user->givePermissionTo($permissions);
        $user->shops()->attach($this->shop->id, ['is_default' => true]);
        $user->forceFill(['current_shop_id' => $this->shop->id])->save();

        return $user;
    }

    private function product(float $stock = 50): Product
    {
        static $counter = 0;
        $counter++;

        $product = Product::create([
            'name' => 'Fulfilment Product '.$counter,
            'slug' => Product::uniqueSlug('Fulfilment Product '.$counter),
            'sku' => Product::generateSku('FUL'.$counter),
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
            'name' => 'Fulfilment Customer '.$counter,
            'mobile' => '92000000'.str_pad((string) $counter, 2, '0', STR_PAD_LEFT),
            'password' => Hash::make('password123'),
            'type' => 'retail',
            'is_active' => true,
        ]);
    }

    private function placeViaCart(Product $product, Customer $customer, float $qty): Order
    {
        app(\App\Services\CartService::class)->add($this->shop, $customer, request(), $product, $qty);

        return $this->orders->place($this->shop, $customer, request(), [
            'address' => [
                'recipient_name' => $customer->name,
                'mobile' => '9876543210',
                'address_line1' => 'Farm Road 1',
            ],
            'payment_method' => 'cod',
        ]);
    }

    public function test_confirm_then_mark_paid_raises_a_real_invoice_and_issues_stock(): void
    {
        $product = $this->product();
        $customer = $this->customer();
        $order = $this->placeViaCart($product, $customer, 3);

        $staff = $this->staff(['sales.orders.view', 'sales.orders.approve']);

        $response = $this->actingAs($staff)->putJson(route('admin.orders.confirm', $order));
        $response->assertOk()->assertJsonPath('success', true);
        $this->assertSame(Order::CONFIRMED, $order->fresh()->status);

        $response = $this->actingAs($staff)->putJson(route('admin.orders.mark-paid', $order), [
            'amount' => (float) $order->fresh()->grand_total,
            'method' => 'cash',
        ]);
        $response->assertOk()->assertJsonPath('success', true);

        $order->refresh();

        $this->assertSame(Order::PAYMENT_PAID, $order->payment_status);
        $this->assertNotNull($order->invoice_id);

        $invoice = Invoice::allShops()->findOrFail($order->invoice_id);
        $this->assertSame(Invoice::ONLINE, $invoice->channel);
        $this->assertEqualsWithDelta((float) $order->grand_total, (float) $invoice->grand_total, 0.02);

        // Reservation released and stock actually issued exactly once.
        $this->assertEqualsWithDelta(47.0, $product->fresh()->stockOnHand($this->shop->id), 0.01);
        $this->assertEqualsWithDelta(47.0, $product->fresh()->availableStock($this->shop->id), 0.01);

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'type' => StockMovement::SALE,
        ]);
    }

    public function test_marking_delivered_before_invoicing_is_refused(): void
    {
        $product = $this->product();
        $customer = $this->customer();
        $order = $this->placeViaCart($product, $customer, 1);

        $staff = $this->staff(['sales.orders.view', 'sales.orders.approve']);

        $response = $this->actingAs($staff)->putJson(route('admin.orders.deliver', $order));

        $response->assertStatus(422)->assertJsonPath('success', false);
        $this->assertNotSame(Order::DELIVERED, $order->fresh()->status);
    }

    public function test_staff_without_approve_permission_cannot_confirm_or_mark_paid(): void
    {
        $product = $this->product();
        $customer = $this->customer();
        $order = $this->placeViaCart($product, $customer, 1);

        $staff = $this->staff(['sales.orders.view']);

        $this->actingAs($staff)->putJson(route('admin.orders.confirm', $order))->assertForbidden();
        $this->actingAs($staff)->putJson(route('admin.orders.mark-paid', $order), [
            'amount' => (float) $order->grand_total,
            'method' => 'cash',
        ])->assertForbidden();
    }
}
