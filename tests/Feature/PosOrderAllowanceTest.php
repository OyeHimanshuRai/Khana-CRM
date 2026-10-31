<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Shop;
use App\Models\StockMovement;
use App\Models\Subscription;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InvoiceService;
use App\Services\StockService;
use App\Support\CurrentShop;
use App\Support\CurrentTenant;
use App\Support\Modules;
use App\Support\PlanAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Tests\TestCase;

/**
 * The plan's monthly order allowance counts the counter too.
 *
 * Starter sells "3,000 orders a month". Only orders from the QR code, the
 * storefront and the API were counted and checked, so the till - where a
 * Starter outlet takes nearly all of its business - had no ceiling at all.
 * A sale at the counter is an order taken; a table's bill is not a second
 * one, because its orders were counted when they were placed.
 */
class PosOrderAllowanceTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');
        $this->seed();
        $this->resetState();

        $this->shop = Shop::query()->withoutGlobalScopes()->orderBy('id')->firstOrFail();
        $this->warehouse = Warehouse::allShops()->where('shop_id', $this->shop->id)->firstOrFail();
    }

    private function resetState(): void
    {
        CurrentShop::forget();
        CurrentTenant::forget();
        PlanAccess::forget();
        Modules::forget();
    }

    private function cap(int $orders, ?\Carbon\CarbonInterface $endsAt = null): void
    {
        $plan = Plan::create([
            'name' => 'Capped', 'code' => 'capped', 'slug' => 'capped',
            'monthly_price' => 100, 'currency' => 'INR', 'max_orders_per_month' => $orders,
            'modules' => ['pos', 'retail'],
        ]);

        Subscription::create([
            'tenant_id' => $this->shop->tenant_id, 'shop_id' => $this->shop->id, 'plan_id' => $plan->id,
            'billing_period' => 'monthly', 'price' => 100, 'currency' => 'INR',
            'starts_at' => now()->subMonth(), 'ends_at' => $endsAt ?? now()->addMonth(), 'grace_days' => 0,
        ]);
        $this->resetState();
    }

    private function cashier(): User
    {
        $user = User::create([
            'tenant_id' => $this->shop->tenant_id, 'name' => 'Counter Staff', 'email' => 'cashier'.uniqid().'@example.test',
            'password' => 'cashier-password-1', 'is_admin' => true, 'is_active' => true,
        ]);
        $user->givePermissionTo(['pos.terminal.view', 'pos.terminal.create', 'pos.terminal.print']);
        $user->shops()->attach($this->shop->id, ['is_default' => true]);
        $user->forceFill(['current_shop_id' => $this->shop->id, 'all_shops_view' => false])->save();

        return $user->fresh();
    }

    private function product(): Product
    {
        $product = Product::create([
            'name' => 'Till Product', 'slug' => Product::uniqueSlug('Till Product'), 'sku' => Product::generateSku('TILL'),
            'unit_id' => Unit::where('code', 'PCS')->firstOrFail()->id,
            'tax_rate_id' => TaxRate::where('name', 'GST 18%')->firstOrFail()->id,
            'purchase_price' => 400, 'mrp' => 600, 'selling_price' => 590, 'tax_inclusive' => true,
        ]);

        (new StockService())->receive($product, 100, $this->warehouse, null, 400, StockMovement::PURCHASE, null, null, $this->shop->id);

        return $product;
    }

    private function ring(User $user, Product $product)
    {
        $this->resetState();
        $price = $product->counterPriceFor($this->shop->id);

        return $this->actingAs($user)->postJson('/superadmin/pos', [
            'channel' => Invoice::POS,
            'warehouse_id' => $this->warehouse->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => $price]],
            'payments' => [['method' => Payment::CASH, 'amount' => $price]],
        ]);
    }

    private function placeOrder(): void
    {
        Order::create([
            'shop_id' => $this->shop->id, 'order_type' => Order::DINE_IN, 'order_number' => 'T-'.uniqid(),
            'status' => Order::PENDING, 'payment_status' => Order::PAYMENT_PENDING,
            'placed_at' => now(), 'subtotal' => 0, 'grand_total' => 0,
        ]);
    }

    /** A real sitting at a real table, for a bill raised against it. */
    private function sitting(): int
    {
        $floor = \App\Models\Floor::create(['shop_id' => $this->shop->id, 'name' => 'Ground', 'code' => 'GF', 'is_active' => true]);
        $table = \App\Models\RestaurantTable::create([
            'shop_id' => $this->shop->id, 'floor_id' => $floor->id, 'name' => '4', 'code' => 'GF-04',
            'capacity' => 4, 'status' => \App\Models\RestaurantTable::AVAILABLE, 'is_active' => true,
        ]);
        $qr = app(\App\Services\TableQrService::class)->issue($table);

        return app(\App\Services\TableSessionService::class)->openFor($table, $qr)->id;
    }

    /* ------------------------------------------------------------ */

    public function test_the_counter_stops_at_the_monthly_allowance(): void
    {
        $this->cap(2);
        $cashier = $this->cashier();
        $product = $this->product();

        $this->ring($cashier, $product)->assertOk();
        $this->ring($cashier, $product)->assertOk();

        $this->ring($cashier, $product)
            ->assertStatus(422)
            ->assertJsonPath('message', 'This outlet has reached its order limit for the month.');

        $this->assertSame(2, Invoice::allShops()->count());
    }

    public function test_the_counter_and_the_tables_share_one_allowance(): void
    {
        $this->cap(2);
        $this->placeOrder();
        $cashier = $this->cashier();
        $product = $this->product();

        $this->ring($cashier, $product)->assertOk();
        $this->resetState();
        $this->assertSame(0, PlanAccess::remainingOrders($this->shop->id));

        $this->ring($cashier, $product)->assertStatus(422);
    }

    public function test_a_cancelled_sale_gives_its_place_back(): void
    {
        $this->cap(1);
        $cashier = $this->cashier();
        $product = $this->product();

        $this->ring($cashier, $product)->assertOk();
        Invoice::allShops()->update(['status' => Invoice::CANCELLED]);

        $this->ring($cashier, $product)->assertOk();
    }

    public function test_a_tables_bill_is_not_a_second_order_and_always_settles(): void
    {
        $this->cap(1);
        $this->placeOrder();
        $product = $this->product();
        $this->resetState();
        $this->assertSame(0, PlanAccess::remainingOrders($this->shop->id));

        // The bill for orders already counted: raised with its sitting attached.
        $invoice = app(InvoiceService::class)->create([
            'shop' => $this->shop,
            'warehouse' => $this->warehouse,
            'channel' => Invoice::POS,
            'table_session_id' => $this->sitting(),
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 590, 'price_includes_tax' => true]],
            'payments' => [['method' => Payment::CASH, 'amount' => 590]],
        ]);

        $this->assertNotNull($invoice->id);
        $this->resetState();
        $this->assertSame(0, PlanAccess::remainingOrders($this->shop->id), 'still one order, not two');
    }

    public function test_a_lapsed_outlet_cannot_sell_even_if_a_screen_forgets_to_check(): void
    {
        $this->cap(100, now()->subDay());
        $product = $this->product();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('This outlet is not taking orders right now.');
        app(InvoiceService::class)->create([
            'shop' => $this->shop,
            'warehouse' => $this->warehouse,
            'channel' => Invoice::POS,
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 590, 'price_includes_tax' => true]],
            'payments' => [['method' => Payment::CASH, 'amount' => 590]],
        ]);
    }

    public function test_without_an_allowance_the_counter_is_unlimited(): void
    {
        $cashier = $this->cashier();
        $product = $this->product();

        for ($i = 0; $i < 3; $i++) {
            $this->ring($cashier, $product)->assertOk();
        }
    }
}
