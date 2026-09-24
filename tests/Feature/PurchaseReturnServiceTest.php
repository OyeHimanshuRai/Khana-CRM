<?php

namespace Tests\Feature;

use App\Models\GoodsReceipt;
use App\Models\Payment;
use App\Models\Product;
use App\Models\PurchaseReturn;
use App\Models\Shop;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierLedger;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\PurchaseReturnService;
use App\Services\PurchaseService;
use App\Services\StockService;
use App\Services\SupplierLedgerService;
use App\Support\CurrentShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Goods going back to a supplier - the mirror of SalesReturnServiceTest, on
 * the other side of the counter.
 *
 * The properties that matter:
 *
 *   - approving takes the goods off the shelf, at the cost they arrived at
 *   - the receipt line remembers how much has gone back, so the same units
 *     cannot be returned twice
 *   - a credit reduces what the shop owes the supplier and the receipt's
 *     own outstanding balance; a refund leaves the balance where it was and
 *     records cash coming back in
 *   - refusing or double-approving a return is rejected outright
 */
class PurchaseReturnServiceTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private Warehouse $warehouse;

    private Supplier $supplier;

    private StockService $stock;

    private PurchaseService $purchases;

    private PurchaseReturnService $returns;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        CurrentShop::forget();

        $this->shop = Shop::firstOrFail();
        $this->warehouse = Warehouse::allShops()->where('shop_id', $this->shop->id)->firstOrFail();

        $this->stock = new StockService();
        $ledger = new SupplierLedgerService();
        $this->purchases = new PurchaseService($this->stock, $ledger);
        $this->returns = new PurchaseReturnService($this->stock, $ledger);

        $supplier = new Supplier([
            'shop_id' => $this->shop->id,
            'name' => 'Return Supplier',
            'code' => 'S00099',
            'credit_days' => 30,
            'is_active' => true,
        ]);
        $supplier->saveQuietly();

        $this->supplier = $supplier;
    }

    protected function tearDown(): void
    {
        CurrentShop::forget();

        parent::tearDown();
    }

    /* ------------------------------------------------------------ fixtures */

    private function product(array $attributes = []): Product
    {
        static $counter = 0;
        $counter++;

        return Product::create([
            'name' => 'Purchase Return Product '.$counter,
            'slug' => Product::uniqueSlug('Purchase Return Product '.$counter),
            'sku' => Product::generateSku('PRT'.$counter),
            'unit_id' => Unit::where('code', 'PCS')->value('id'),
            'purchase_price' => 100,
            'mrp' => 200,
            'selling_price' => 180,
            ...$attributes,
        ]);
    }

    /** A posted receipt with one line, ready to be returned against. */
    private function receive(Product $product, float $quantity = 20, float $cost = 100, float $tax = 0): GoodsReceipt
    {
        $receipt = new GoodsReceipt([
            'shop_id' => $this->shop->id,
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'reference' => GoodsReceipt::nextReference($this->shop),
            'received_on' => today(),
            'status' => GoodsReceipt::DRAFT,
            'bill_number' => 'BILL-'.uniqid(),
            'bill_date' => today(),
        ]);
        $receipt->saveQuietly();

        $receipt->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'sku' => $product->sku,
            'unit_code' => $product->unit?->code,
            'quantity' => $quantity,
            'unit_cost' => $cost,
            'tax_rate' => $tax,
        ]);

        return $this->purchases->post($receipt->fresh());
    }

    /**
     * A pending return against a receipt.
     *
     * @param  array<int, array{line: int, quantity: float}>  $lines
     */
    private function raise(GoodsReceipt $receipt, array $lines, string $settlement = PurchaseReturn::CREDIT): PurchaseReturn
    {
        $return = new PurchaseReturn([
            'shop_id' => $this->shop->id,
            'warehouse_id' => $this->warehouse->id,
            'goods_receipt_id' => $receipt->id,
            'supplier_id' => $receipt->supplier_id,
            'supplier_name' => $receipt->supplier?->displayName(),
            'reference' => PurchaseReturn::nextReference($this->shop),
            'returned_on' => today(),
            'status' => PurchaseReturn::PENDING,
            'reason_code' => 'excess',
            'settlement' => $settlement,
        ]);

        $return->saveQuietly();

        foreach ($lines as $line) {
            $source = $receipt->items[$line['line']];

            $return->items()->create([
                'goods_receipt_item_id' => $source->id,
                'product_id' => $source->product_id,
                'batch_id' => $source->batch_id,
                'product_name' => $source->product_name,
                'sku' => $source->sku,
                'unit_code' => $source->unit_code,
                'quantity' => $line['quantity'],
                'unit_cost' => (float) ($source->landed_cost ?: $source->unit_cost),
                'tax_rate' => (float) $source->tax_rate,
            ]);
        }

        return $return->fresh();
    }

    /* -------------------------------------------------------------- stock */

    public function test_approving_takes_the_goods_off_the_shelf(): void
    {
        $product = $this->product();
        $receipt = $this->receive($product, 20, 100);

        $this->assertSame(20.0, $product->stockOnHand($this->shop->id));

        $this->returns->approve($this->raise($receipt, [['line' => 0, 'quantity' => 5]]));

        $this->assertSame(15.0, $product->stockOnHand($this->shop->id));

        $this->assertSame(1, StockMovement::allShops()
            ->where('product_id', $product->id)
            ->where('type', StockMovement::PURCHASE_RETURN)
            ->count());
    }

    /* ------------------------------------------------------------- supplier */

    public function test_a_credit_reduces_what_the_shop_owes_the_supplier(): void
    {
        $product = $this->product();
        $receipt = $this->receive($product, 20, 100);

        $this->assertSame(2000.00, (float) $this->supplier->fresh()->balance);

        $this->returns->approve($this->raise($receipt, [['line' => 0, 'quantity' => 5]]));

        $this->assertSame(1500.00, (float) $this->supplier->fresh()->balance);
        $this->assertSame(1500.00, (float) $receipt->fresh()->due_total);
    }

    public function test_a_refund_pays_cash_in_and_leaves_the_balance_alone(): void
    {
        $product = $this->product();
        $receipt = $this->receive($product, 20, 100);

        $this->assertSame(2000.00, (float) $this->supplier->fresh()->balance);

        $return = $this->returns->approve(
            $this->raise($receipt, [['line' => 0, 'quantity' => 5]], PurchaseReturn::REFUND)
        );

        $this->assertSame(500.00, (float) $return->refund_amount);

        // Cash changed hands rather than a lower bill, so the balance nets
        // back to where it was.
        $this->assertSame(2000.00, (float) $this->supplier->fresh()->balance);
        $this->assertSame(2000.00, (float) $receipt->fresh()->due_total);

        $payment = Payment::allShops()->where('direction', Payment::IN)->sole();
        $this->assertSame(500.00, (float) $payment->amount);

        // Both sides of the story are on the ledger: the return, and its reversal.
        $this->assertSame(2, SupplierLedger::allShops()
            ->where('supplier_id', $this->supplier->id)
            ->where('reference_type', PurchaseReturn::class)
            ->count());
    }

    public function test_an_exchange_moves_stock_but_no_money(): void
    {
        $product = $this->product();
        $receipt = $this->receive($product, 20, 100);

        $this->returns->approve(
            $this->raise($receipt, [['line' => 0, 'quantity' => 3]], PurchaseReturn::NONE)
        );

        $this->assertSame(17.0, $product->stockOnHand($this->shop->id));
        $this->assertSame(2000.00, (float) $this->supplier->fresh()->balance);
        $this->assertSame(0, Payment::allShops()->where('direction', Payment::IN)->count());
    }

    /* ------------------------------------------------------------- receipt */

    public function test_the_receipt_line_remembers_what_has_gone_back(): void
    {
        $product = $this->product();
        $receipt = $this->receive($product, 20, 100);

        $this->returns->approve($this->raise($receipt, [['line' => 0, 'quantity' => 8]]));

        $line = $receipt->items()->sole();

        $this->assertSame(8.0, (float) $line->returned_quantity);
        $this->assertSame(12.0, $line->returnableQuantity());
        $this->assertFalse($line->isFullyReturned());
    }

    public function test_returning_everything_marks_the_line_finished(): void
    {
        $product = $this->product();
        $receipt = $this->receive($product, 10, 100);

        $this->returns->approve($this->raise($receipt, [['line' => 0, 'quantity' => 10]]));

        $this->assertTrue($receipt->items()->sole()->isFullyReturned());
    }

    public function test_sending_back_more_than_was_received_is_impossible_to_raise_correctly(): void
    {
        // The service trusts the line it is given; it is the controller's
        // syncItems() that refuses an over-quantity before a return is ever
        // built - proven separately at the HTTP layer. Here, a return built
        // with more than what remains simply overshoots what is on the
        // shelf, and StockService's own negative-stock guard is what stops it.
        $product = $this->product();
        $receipt = $this->receive($product, 5, 100);

        $this->expectException(RuntimeException::class);

        $this->returns->approve($this->raise($receipt, [['line' => 0, 'quantity' => 500]]));
    }

    /* --------------------------------------------------------- decisions */

    public function test_refusing_a_return_moves_nothing(): void
    {
        $product = $this->product();
        $receipt = $this->receive($product, 20, 100);

        $return = $this->raise($receipt, [['line' => 0, 'quantity' => 4]]);

        $this->returns->reject($return, 'Supplier will not accept it back');

        $this->assertSame(PurchaseReturn::REJECTED, $return->fresh()->status);
        $this->assertSame(20.0, $product->stockOnHand($this->shop->id));
        $this->assertSame(2000.00, (float) $this->supplier->fresh()->balance);
    }

    public function test_refusing_without_a_reason_is_refused(): void
    {
        $product = $this->product();
        $receipt = $this->receive($product, 10, 100);

        $this->expectException(RuntimeException::class);

        $this->returns->reject($this->raise($receipt, [['line' => 0, 'quantity' => 2]]), '  ');
    }

    public function test_approving_twice_is_refused(): void
    {
        $product = $this->product();
        $receipt = $this->receive($product, 10, 100);

        $return = $this->raise($receipt, [['line' => 0, 'quantity' => 2]]);
        $this->returns->approve($return);

        $this->expectException(RuntimeException::class);

        $this->returns->approve($return->fresh());
    }

    /* ------------------------------------------------------------ margin */

    public function test_the_credit_is_booked_at_the_figure_the_purchase_was_made_at(): void
    {
        // A later, cheaper purchase must not change the credit on a return
        // against an earlier, dearer receipt.
        $product = $this->product();
        $receipt = $this->receive($product, 10, 150);

        $this->stock->receive($product, 50, $this->warehouse, null, 90,
            StockMovement::PURCHASE, null, null, $this->shop->id);

        $return = $this->returns->approve($this->raise($receipt, [['line' => 0, 'quantity' => 4]]));

        // 4 x the ₹150 it was bought at, not the ₹90 it costs now.
        $this->assertSame(600.0, (float) $return->subtotal);
    }
}
