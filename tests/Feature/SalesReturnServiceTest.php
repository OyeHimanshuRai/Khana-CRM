<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SalesReturn;
use App\Models\SalesReturnItem;
use App\Models\Shop;
use App\Models\StockMovement;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\AlertService;
use App\Services\InvoiceService;
use App\Services\LedgerService;
use App\Services\SalesReturnService;
use App\Services\StockService;
use App\Support\CurrentShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Goods coming back.
 *
 * The properties that matter:
 *
 *   - only resalable goods return to sellable stock; damaged ones are
 *     written off where the loss can be seen
 *   - the credit matches what was charged, discount and all
 *   - the invoice line remembers how much has come back, so the same units
 *     cannot be returned twice
 *   - the cost is unwound at the figure the sale was booked at, so a month
 *     already reported does not quietly change
 */
class SalesReturnServiceTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private Warehouse $warehouse;

    private StockService $stock;

    private InvoiceService $invoices;

    private SalesReturnService $returns;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        CurrentShop::forget();

        $this->shop = Shop::firstOrFail();
        $this->warehouse = Warehouse::allShops()->where('shop_id', $this->shop->id)->firstOrFail();

        $this->stock = new StockService();
        $ledger = new LedgerService();
        /*
         | Built through the container rather than by hand.
         |
         | Constructed literally, this breaks every time the service gains a
         | dependency - which it has, twice - and the failure lands on four
         | test files that have nothing to do with the change. What these
         | tests DO care about is holding on to the same collaborators, so
         | those are bound explicitly and the rest is resolved.
         */
        $this->app->instance(StockService::class, $this->stock);
        $this->app->instance(LedgerService::class, $ledger);
        $this->invoices = $this->app->make(InvoiceService::class);
        $this->returns = new SalesReturnService($this->stock, $ledger);
    }

    protected function tearDown(): void
    {
        CurrentShop::forget();

        parent::tearDown();
    }

    /* ------------------------------------------------------------ fixtures */

    private function product(float $cost = 60, float $price = 100): Product
    {
        static $counter = 0;
        $counter++;

        $product = Product::create([
            'name' => 'Return Product '.$counter,
            'slug' => Product::uniqueSlug('Return Product '.$counter),
            'sku' => Product::generateSku('RET'.$counter),
            'unit_id' => Unit::where('code', 'PCS')->value('id'),
            'tax_rate_id' => TaxRate::where('name', 'Exempt / Nil')->value('id'),
            'purchase_price' => $cost,
            'selling_price' => $price,
            'tax_inclusive' => true,
        ]);

        $this->stock->receive($product, 200, $this->warehouse, null, $cost,
            StockMovement::PURCHASE, null, null, $this->shop->id);

        return $product;
    }

    private function customer(array $attributes = []): Customer
    {
        static $counter = 0;
        $counter++;

        $customer = new Customer([
            'shop_id' => $this->shop->id,
            'name' => 'Returner '.$counter,
            'mobile' => '96000000'.str_pad((string) $counter, 2, '0', STR_PAD_LEFT),
            'type' => 'farmer',
            'allow_credit' => true,
            'credit_limit' => 100000,
            'credit_days' => 30,
            ...$attributes,
        ]);

        $customer->saveQuietly();

        return $customer;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function sell(?Customer $customer, array $items, array $payments = []): Invoice
    {
        return $this->invoices->create([
            'shop' => $this->shop,
            'warehouse' => $this->warehouse,
            'customer' => $customer,
            'channel' => Invoice::MANUAL,
            'items' => $items,
            'payments' => $payments,
        ]);
    }

    /**
     * A pending return against an invoice.
     *
     * @param  array<int, array{line: int, quantity: float, condition?: string}>  $lines
     */
    private function raise(Invoice $invoice, array $lines, string $settlement = SalesReturn::CREDIT): SalesReturn
    {
        $return = new SalesReturn([
            'shop_id' => $this->shop->id,
            'warehouse_id' => $this->warehouse->id,
            'invoice_id' => $invoice->id,
            'customer_id' => $invoice->customer_id,
            'customer_name' => $invoice->billedTo(),
            'reference' => SalesReturn::nextReference($this->shop),
            'returned_on' => today(),
            'status' => SalesReturn::PENDING,
            'reason_code' => 'not_needed',
            'settlement' => $settlement,
        ]);

        $return->saveQuietly();

        foreach ($lines as $line) {
            $source = $invoice->items[$line['line']];

            $return->items()->create([
                'invoice_item_id' => $source->id,
                'product_id' => $source->product_id,
                'batch_id' => $source->batch_id,
                'product_name' => $source->product_name,
                'sku' => $source->sku,
                'unit_code' => $source->unit_code,
                'quantity' => $line['quantity'],
                'condition' => $line['condition'] ?? SalesReturnItem::RESALABLE,
                'unit_price' => (float) $source->quantity > 0
                    ? (float) $source->taxable_value / (float) $source->quantity
                    : 0,
                'tax_rate' => (float) $source->tax_rate,
                'unit_cost' => (float) $source->unit_cost,
            ]);
        }

        return $return->fresh();
    }

    /* -------------------------------------------------------------- stock */

    public function test_resalable_goods_go_back_on_the_shelf(): void
    {
        $product = $this->product();
        $customer = $this->customer();

        $invoice = $this->sell($customer, [
            ['product_id' => $product->id, 'quantity' => 10],
        ], [['method' => Payment::CASH, 'amount' => 1000]]);

        $this->assertSame(190.0, $product->stockOnHand($this->shop->id));

        $this->returns->approve($this->raise($invoice, [['line' => 0, 'quantity' => 4]]));

        $this->assertSame(194.0, $product->stockOnHand($this->shop->id));
    }

    public function test_damaged_goods_never_reach_sellable_stock(): void
    {
        /*
         | Restocking a burst bag would make the next count wrong and the
         | loss invisible, which is the opposite of what a returns document
         | is for.
         */
        $product = $this->product();
        $customer = $this->customer();

        $invoice = $this->sell($customer, [
            ['product_id' => $product->id, 'quantity' => 10],
        ], [['method' => Payment::CASH, 'amount' => 1000]]);

        $this->returns->approve($this->raise($invoice, [
            ['line' => 0, 'quantity' => 4, 'condition' => SalesReturnItem::DAMAGED],
        ]));

        // Back where it was before the return, not four higher.
        $this->assertSame(190.0, $product->stockOnHand($this->shop->id));

        // And the write-off is a movement somebody can find.
        $this->assertSame(1, StockMovement::allShops()
            ->where('product_id', $product->id)
            ->where('type', StockMovement::WASTAGE)
            ->count());
    }

    public function test_a_mixed_return_restocks_only_the_good_part(): void
    {
        $product = $this->product();
        $customer = $this->customer();

        $invoice = $this->sell($customer, [
            ['product_id' => $product->id, 'quantity' => 10],
        ], [['method' => Payment::CASH, 'amount' => 1000]]);

        $return = $this->raise($invoice, [['line' => 0, 'quantity' => 6]]);

        // Split the line: four good, two damaged.
        $return->items()->first()->forceFill(['quantity' => 4])->save();
        $return->items()->create([
            'invoice_item_id' => $invoice->items[0]->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 2,
            'condition' => SalesReturnItem::DAMAGED,
            'unit_price' => 100,
            'unit_cost' => 60,
        ]);

        $this->returns->approve($return->fresh());

        $this->assertSame(194.0, $product->stockOnHand($this->shop->id));
    }

    /* -------------------------------------------------------------- credit */

    public function test_the_credit_matches_what_was_charged_including_the_discount(): void
    {
        // Sold at 10% off, so the credit is the discounted figure - not the
        // list price, which the customer never paid.
        $product = $this->product(price: 100);
        $customer = $this->customer();

        $invoice = $this->sell($customer, [
            ['product_id' => $product->id, 'quantity' => 10, 'discount_percent' => 10],
        ], [['method' => Payment::CASH, 'amount' => 900]]);

        $return = $this->returns->approve($this->raise($invoice, [['line' => 0, 'quantity' => 5]]));

        $this->assertSame(450.00, (float) $return->grand_total);
    }

    public function test_a_credit_reduces_what_the_customer_owes(): void
    {
        $product = $this->product();
        $customer = $this->customer();

        $invoice = $this->sell($customer, [
            ['product_id' => $product->id, 'quantity' => 10],
        ], []);

        $this->assertSame(1000.00, (float) $customer->fresh()->balance);

        $this->returns->approve($this->raise($invoice, [['line' => 0, 'quantity' => 3]]));

        $this->assertSame(700.00, (float) $customer->fresh()->balance);
        $this->assertSame(700.00, (float) $invoice->fresh()->due_total);
    }

    public function test_a_refund_pays_money_out_and_leaves_the_balance_alone(): void
    {
        $product = $this->product();
        $customer = $this->customer();

        $invoice = $this->sell($customer, [
            ['product_id' => $product->id, 'quantity' => 10],
        ], [['method' => Payment::CASH, 'amount' => 1000]]);

        $this->assertSame(0.00, (float) $customer->fresh()->balance);

        $return = $this->returns->approve(
            $this->raise($invoice, [['line' => 0, 'quantity' => 3]], SalesReturn::REFUND)
        );

        $this->assertSame(300.00, (float) $return->refund_amount);
        // Money changed hands, so the account nets back to where it was.
        $this->assertSame(0.00, (float) $customer->fresh()->balance);

        $payment = Payment::allShops()->where('direction', Payment::OUT)->sole();
        $this->assertSame(300.00, (float) $payment->amount);
    }

    public function test_a_walk_in_is_refunded_whatever_settlement_was_chosen(): void
    {
        // There is no account to credit, so quietly doing nothing would
        // leave the customer out of pocket.
        $product = $this->product();

        $invoice = $this->sell(null, [
            ['product_id' => $product->id, 'quantity' => 5],
        ], [['method' => Payment::CASH, 'amount' => 500]]);

        $return = $this->returns->approve(
            $this->raise($invoice, [['line' => 0, 'quantity' => 2]], SalesReturn::CREDIT)
        );

        $this->assertSame(SalesReturn::REFUND, $return->fresh()->settlement);
        $this->assertSame(200.00, (float) $return->fresh()->refund_amount);
    }

    public function test_an_exchange_moves_stock_but_no_money(): void
    {
        $product = $this->product();
        $customer = $this->customer();

        $invoice = $this->sell($customer, [
            ['product_id' => $product->id, 'quantity' => 10],
        ], [['method' => Payment::CASH, 'amount' => 1000]]);

        $this->returns->approve(
            $this->raise($invoice, [['line' => 0, 'quantity' => 3]], SalesReturn::NONE)
        );

        $this->assertSame(193.0, $product->stockOnHand($this->shop->id));
        $this->assertSame(0.00, (float) $customer->fresh()->balance);
        $this->assertSame(0, Payment::allShops()->where('direction', Payment::OUT)->count());
    }

    /* ------------------------------------------------------- the invoice */

    public function test_the_invoice_line_remembers_what_has_come_back(): void
    {
        $product = $this->product();
        $customer = $this->customer();

        $invoice = $this->sell($customer, [
            ['product_id' => $product->id, 'quantity' => 10],
        ], [['method' => Payment::CASH, 'amount' => 1000]]);

        $this->returns->approve($this->raise($invoice, [['line' => 0, 'quantity' => 4]]));

        $line = $invoice->items()->sole();

        $this->assertSame(4.0, (float) $line->returned_quantity);
        $this->assertSame(6.0, $line->returnableQuantity());
        $this->assertFalse($line->isFullyReturned());
    }

    public function test_returning_everything_marks_the_line_finished(): void
    {
        $product = $this->product();
        $customer = $this->customer();

        $invoice = $this->sell($customer, [
            ['product_id' => $product->id, 'quantity' => 5],
        ], [['method' => Payment::CASH, 'amount' => 500]]);

        $this->returns->approve($this->raise($invoice, [['line' => 0, 'quantity' => 5]]));

        $this->assertTrue($invoice->items()->sole()->isFullyReturned());
    }

    /* --------------------------------------------------------- decisions */

    public function test_refusing_a_return_moves_nothing(): void
    {
        $product = $this->product();
        $customer = $this->customer();

        $invoice = $this->sell($customer, [
            ['product_id' => $product->id, 'quantity' => 10],
        ], [['method' => Payment::CASH, 'amount' => 1000]]);

        $return = $this->raise($invoice, [['line' => 0, 'quantity' => 4]]);

        $this->returns->reject($return, 'Outside the return window');

        $this->assertSame(SalesReturn::REJECTED, $return->fresh()->status);
        $this->assertSame(190.0, $product->stockOnHand($this->shop->id));
        $this->assertSame(0.00, (float) $customer->fresh()->balance);
    }

    public function test_refusing_without_a_reason_is_refused(): void
    {
        $product = $this->product();
        $invoice = $this->sell($this->customer(), [
            ['product_id' => $product->id, 'quantity' => 5],
        ], [['method' => Payment::CASH, 'amount' => 500]]);

        $this->expectException(RuntimeException::class);

        $this->returns->reject($this->raise($invoice, [['line' => 0, 'quantity' => 2]]), '  ');
    }

    public function test_approving_twice_is_refused(): void
    {
        $product = $this->product();
        $invoice = $this->sell($this->customer(), [
            ['product_id' => $product->id, 'quantity' => 5],
        ], [['method' => Payment::CASH, 'amount' => 500]]);

        $return = $this->raise($invoice, [['line' => 0, 'quantity' => 2]]);
        $this->returns->approve($return);

        $this->expectException(RuntimeException::class);

        $this->returns->approve($return->fresh());
    }

    /* ------------------------------------------------------------ margin */

    public function test_the_cost_is_unwound_at_the_figure_the_sale_was_booked_at(): void
    {
        /*
         | A later, dearer purchase must not change the margin on a month
         | that has already been reported.
         */
        $product = $this->product(cost: 60);
        $customer = $this->customer();

        $invoice = $this->sell($customer, [
            ['product_id' => $product->id, 'quantity' => 10],
        ], [['method' => Payment::CASH, 'amount' => 1000]]);

        $this->stock->receive($product, 100, $this->warehouse, null, 200,
            StockMovement::PURCHASE, null, null, $this->shop->id);

        $return = $this->returns->approve($this->raise($invoice, [['line' => 0, 'quantity' => 5]]));

        // 5 x the â‚¹60 it was sold at, not the â‚¹200 it costs now.
        $this->assertSame(300.0, (float) $return->cost_total);
    }
}
