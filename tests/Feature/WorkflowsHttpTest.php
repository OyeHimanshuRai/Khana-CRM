<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Shop;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InvoiceService;
use App\Services\StockService;
use App\Support\CurrentShop;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The stock and money workflows, driven the way the screens drive them: over
 * HTTP, through each controller's validation, ending on the figure a person
 * would check afterwards (the stock on the shelf, the customer's balance, the
 * supplier's account).
 *
 * The services under them have their own tests; this proves the doors in
 * front of the services are wired to them correctly.
 */
class WorkflowsHttpTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');
        $this->seed();
        CurrentShop::forget();
        CurrentTenant::forget();

        $this->shop = Shop::query()->orderBy('id')->firstOrFail();
        $this->warehouse = Warehouse::defaultFor($this->shop->id);

        $this->actingAs(User::query()->withoutGlobalScopes()->where('email', 'admin@erp.test')->firstOrFail());
        CurrentShop::forget();
        // Work inside the one branch so every screen and service agrees which it is.
        auth()->user()->forceFill(['current_shop_id' => $this->shop->id, 'all_shops_view' => false])->save();
        CurrentShop::forget();
    }

    protected function tearDown(): void
    {
        CurrentShop::forget();
        CurrentTenant::forget();

        parent::tearDown();
    }

    /* ------------------------------------------------------------ fixtures */

    private function product(float $cost = 60, float $price = 100, float $stock = 50): Product
    {
        static $n = 0;
        $n++;

        $product = Product::create([
            'name' => "Flow Item {$n}", 'slug' => Product::uniqueSlug("Flow Item {$n}"), 'sku' => Product::generateSku("FLOW{$n}"),
            'unit_id' => Unit::where('code', 'PCS')->value('id'), 'tax_rate_id' => TaxRate::where('rate', 0)->value('id'),
            'purchase_price' => $cost, 'mrp' => $price, 'selling_price' => $price, 'tax_inclusive' => true,
        ]);

        if ($stock > 0) {
            app(StockService::class)->receive($product, $stock, $this->warehouse, null, $cost, StockMovement::PURCHASE, null, 'fixture', $this->shop->id);
        }

        return $product;
    }

    private function onHand(Product $product, ?Warehouse $warehouse = null): float
    {
        return (float) ProductStock::query()->withoutGlobalScopes()
            ->where('product_id', $product->id)->where('warehouse_id', ($warehouse ?? $this->warehouse)->id)->sum('quantity');
    }

    private function customer(bool $credit = true): Customer
    {
        $customer = new Customer([
            'name' => 'Asha Menon', 'mobile' => '9811100001', 'type' => 'retail',
            'allow_credit' => $credit, 'credit_limit' => 50000, 'credit_days' => 15, 'is_active' => true,
        ]);
        $customer->shop_id = $this->shop->id;
        $customer->code = Customer::nextCode($this->shop->id);
        $customer->save();

        return $customer;
    }

    private function invoice(Product $product, int $qty, array $extra = []): Invoice
    {
        return app(InvoiceService::class)->create(array_merge([
            'shop' => $this->shop, 'warehouse' => $this->warehouse, 'channel' => 'pos',
            'items' => [['product_id' => $product->id, 'quantity' => $qty]],
            'payments' => [['method' => 'cash', 'amount' => 100 * $qty]],
        ], $extra));
    }

    /* ---------------------------------------------------- stock adjustments */

    public function test_a_stock_count_is_drafted_approved_and_sets_the_shelf_to_the_counted_figure(): void
    {
        $product = $this->product(stock: 50);

        $created = $this->postJson(route('admin.stock-adjustments.store'), [
            'warehouse_id' => $this->warehouse->id, 'adjustment_date' => now()->toDateString(), 'reason_code' => 'count',
            'items' => [['product_id' => $product->id, 'counted_quantity' => 42]],
        ])->assertOk()->assertJsonPath('success', true);

        // A draft moves nothing.
        $this->assertSame(50.0, $this->onHand($product));

        $id = \App\Models\StockAdjustment::query()->withoutGlobalScopes()->latest('id')->value('id');
        $this->putJson(route('admin.stock-adjustments.approve', $id), [])->assertOk();

        $this->assertSame(42.0, $this->onHand($product));
        $this->assertTrue(StockMovement::query()->withoutGlobalScopes()->where('product_id', $product->id)->where('quantity', -8)->exists());
    }

    public function test_a_rejected_stock_count_changes_nothing(): void
    {
        $product = $this->product(stock: 30);

        $this->postJson(route('admin.stock-adjustments.store'), [
            'warehouse_id' => $this->warehouse->id, 'adjustment_date' => now()->toDateString(), 'reason_code' => 'damage',
            'items' => [['product_id' => $product->id, 'counted_quantity' => 5]],
        ])->assertOk();

        $id = \App\Models\StockAdjustment::query()->withoutGlobalScopes()->latest('id')->value('id');
        $this->putJson(route('admin.stock-adjustments.reject', $id), ['review_note' => 'Recount first'])->assertOk();

        $this->assertSame(30.0, $this->onHand($product));
    }

    public function test_a_stock_count_needs_a_known_reason_and_a_real_product(): void
    {
        $this->postJson(route('admin.stock-adjustments.store'), [
            'warehouse_id' => $this->warehouse->id, 'adjustment_date' => now()->toDateString(), 'reason_code' => 'because',
            'items' => [['product_id' => 999999, 'counted_quantity' => 1]],
        ])->assertStatus(422)->assertJsonValidationErrors(['reason_code', 'items.0.product_id']);
    }

    /* ----------------------------------------------------- stock transfers */

    public function test_a_transfer_moves_stock_between_warehouses_in_four_steps(): void
    {
        $product = $this->product(stock: 40);
        $second = Warehouse::create(['shop_id' => $this->shop->id, 'name' => 'Cold Store', 'code' => 'CS9', 'is_active' => true]);

        $this->postJson(route('admin.stock-transfers.store'), [
            'from_warehouse_id' => $this->warehouse->id, 'to_warehouse_id' => $second->id, 'transfer_date' => now()->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 15]],
        ])->assertOk()->assertJsonPath('success', true);

        $id = \App\Models\StockTransfer::query()->withoutGlobalScopes()->latest('id')->value('id');

        $this->putJson(route('admin.stock-transfers.approve', $id), [])->assertOk();
        $this->assertSame(40.0, $this->onHand($product), 'approving is not moving');

        $this->putJson(route('admin.stock-transfers.dispatch', $id), [])->assertOk();
        // On the van: out of the sender, not yet in the receiver.
        $this->assertSame(25.0, $this->onHand($product));
        $this->assertSame(0.0, $this->onHand($product, $second));

        $this->putJson(route('admin.stock-transfers.receive', $id), [])->assertOk();
        $this->assertSame(25.0, $this->onHand($product));
        $this->assertSame(15.0, $this->onHand($product, $second));
    }

    public function test_a_transfer_to_the_same_warehouse_is_refused(): void
    {
        $product = $this->product(stock: 10);

        $this->postJson(route('admin.stock-transfers.store'), [
            'from_warehouse_id' => $this->warehouse->id, 'to_warehouse_id' => $this->warehouse->id, 'transfer_date' => now()->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(422);
    }

    /* ------------------------------------------------------ goods receipts */

    public function test_posting_a_goods_receipt_puts_stock_on_the_shelf_and_bills_the_supplier(): void
    {
        $product = $this->product(stock: 0);
        $supplier = new Supplier(['name' => 'Kisan Grains', 'is_active' => true]);
        $supplier->shop_id = $this->shop->id;
        $supplier->code = Supplier::nextCode($this->shop->id);
        $supplier->save();

        $this->postJson(route('admin.receipts.store'), [
            'supplier_id' => $supplier->id, 'warehouse_id' => $this->warehouse->id, 'received_on' => now()->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 20, 'unit_cost' => 55]],
        ])->assertOk()->assertJsonPath('success', true);

        $receipt = \App\Models\GoodsReceipt::query()->withoutGlobalScopes()->latest('id')->first();
        $this->assertSame(0.0, $this->onHand($product), 'a draft receipt is not stock yet');

        $this->putJson(route('admin.receipts.post', $receipt->id), [])->assertOk();

        $this->assertSame(20.0, $this->onHand($product));
        $this->assertGreaterThan(0, (float) $supplier->fresh()->balance, 'the supplier is now owed money');
    }

    public function test_a_goods_receipt_cannot_be_dated_in_the_future_or_be_empty(): void
    {
        $this->postJson(route('admin.receipts.store'), [
            'supplier_id' => 1, 'warehouse_id' => $this->warehouse->id, 'received_on' => now()->addDays(3)->toDateString(), 'items' => [],
        ])->assertStatus(422)->assertJsonValidationErrors(['received_on', 'items']);
    }

    /* --------------------------------------------------------- payments */

    public function test_a_payment_settles_the_customers_credit_invoice_and_a_reversal_restores_it(): void
    {
        $product = $this->product(stock: 20);
        $customer = $this->customer();
        $invoice = $this->invoice($product, 3, ['customer' => $customer, 'is_credit' => true, 'payments' => [], 'due_date' => now()->addDays(10)->toDateString()]);

        $this->assertEqualsWithDelta(300.0, (float) $invoice->fresh()->due_total, 0.01);
        $this->assertEqualsWithDelta(300.0, (float) $customer->fresh()->balance, 0.01);

        $this->postJson(route('admin.payments.store'), [
            'customer_id' => $customer->id, 'amount' => 300, 'method' => 'cash', 'allocations' => [$invoice->id => 300],
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertEqualsWithDelta(0.0, (float) $invoice->fresh()->due_total, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $customer->fresh()->balance, 0.01);

        $payment = Payment::query()->withoutGlobalScopes()->where('direction', 'in')->latest('id')->first();
        $this->putJson(route('admin.payments.reverse', $payment->id), ['reason' => 'Entered against the wrong customer'])->assertOk();

        // Never edited: a reversing entry exists, and the debt is back.
        $this->assertEqualsWithDelta(300.0, (float) $customer->fresh()->balance, 0.01);
        $this->assertTrue(Payment::query()->withoutGlobalScopes()->where('reverses_payment_id', $payment->id)->exists());
    }

    public function test_a_payment_needs_an_amount_a_method_and_a_customer(): void
    {
        $this->postJson(route('admin.payments.store'), ['amount' => 0, 'method' => 'barter'])
            ->assertStatus(422)->assertJsonValidationErrors(['customer_id', 'amount', 'method']);
    }

    public function test_writing_off_a_debt_needs_a_reason_and_reduces_the_balance(): void
    {
        $product = $this->product(stock: 10);
        $customer = $this->customer();
        $this->invoice($product, 2, ['customer' => $customer, 'is_credit' => true, 'payments' => [], 'due_date' => now()->addDays(5)->toDateString()]);

        $this->postJson(route('admin.dues.write-off', $customer->id), ['amount' => 100])
            ->assertStatus(422)->assertJsonValidationErrors('reason');

        $this->postJson(route('admin.dues.write-off', $customer->id), ['amount' => 100, 'reason' => 'Customer closed down, uncollectable'])->assertOk();

        $this->assertEqualsWithDelta(100.0, (float) $customer->fresh()->balance, 0.01);
    }

    /* ---------------------------------------------------- sales returns */

    public function test_an_approved_sales_return_puts_resalable_goods_back_on_the_shelf(): void
    {
        $product = $this->product(stock: 20);
        $invoice = $this->invoice($product, 4);
        $this->assertSame(16.0, $this->onHand($product));

        $line = $invoice->items()->first();

        $this->postJson(route('admin.sales-returns.store'), [
            'invoice_id' => $invoice->id, 'warehouse_id' => $this->warehouse->id, 'returned_on' => now()->toDateString(),
            'reason_code' => 'not_needed', 'settlement' => 'refund',
            'items' => [['invoice_item_id' => $line->id, 'quantity' => 2, 'condition' => 'resalable']],
        ])->assertOk()->assertJsonPath('success', true);

        $return = \App\Models\SalesReturn::query()->withoutGlobalScopes()->latest('id')->first();
        $this->assertSame(16.0, $this->onHand($product), 'a pending return has not touched the shelf');

        $this->putJson(route('admin.sales-returns.approve', $return->id), [])->assertOk();
        $this->assertSame(18.0, $this->onHand($product));
    }

    public function test_a_return_cannot_exceed_what_was_sold(): void
    {
        $product = $this->product(stock: 20);
        $invoice = $this->invoice($product, 2);
        $line = $invoice->items()->first();

        $response = $this->postJson(route('admin.sales-returns.store'), [
            'invoice_id' => $invoice->id, 'warehouse_id' => $this->warehouse->id, 'returned_on' => now()->toDateString(),
            'reason_code' => 'other', 'settlement' => 'none',
            'items' => [['invoice_item_id' => $line->id, 'quantity' => 5, 'condition' => 'resalable']],
        ]);

        $this->assertTrue($response->status() >= 400, 'returning more than was sold must be refused');
        $this->assertSame(18.0, $this->onHand($product));
    }

    /* ------------------------------------------------------------ wastage */

    public function test_wastage_takes_stock_off_the_shelf_and_needs_a_reason(): void
    {
        $product = $this->product(stock: 12);

        $this->postJson(route('admin.wastage.store'), ['product_id' => $product->id, 'quantity' => 3])
            ->assertStatus(422)->assertJsonValidationErrors('reason_code');

        $this->postJson(route('admin.wastage.store'), ['product_id' => $product->id, 'quantity' => 3, 'reason_code' => 'spoiled'])
            ->assertOk()->assertJsonPath('success', true);

        $this->assertSame(9.0, $this->onHand($product));
    }

    /* ------------------------------------------------------ cancel invoice */

    public function test_cancelling_an_invoice_returns_stock_and_keeps_the_number(): void
    {
        $product = $this->product(stock: 10);
        $invoice = $this->invoice($product, 3);
        $this->assertSame(7.0, $this->onHand($product));

        $this->putJson(route('admin.invoices.cancel', $invoice->id), ['reason' => 'Rung up twice by mistake'])->assertOk();

        $this->assertSame(10.0, $this->onHand($product));
        $fresh = $invoice->fresh();
        $this->assertSame('cancelled', $fresh->status);
        $this->assertSame($invoice->number, $fresh->number);
    }
}
