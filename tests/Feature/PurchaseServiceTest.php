<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\GoodsReceipt;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\PurchaseOrder;
use App\Models\Shop;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierLedger;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\PurchaseService;
use App\Services\StockService;
use App\Services\SupplierLedgerService;
use App\Support\CurrentShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Receiving goods.
 *
 * What has to hold when a consignment is posted:
 *
 *   - the stock lands, at its landed cost rather than its invoice line
 *   - freight is spread by value, not by unit
 *   - free stock counts as stock and pulls the average cost down
 *   - a batch-tracked product cannot be received without a batch number
 *   - the supplier is billed exactly once
 *   - the purchase order moves along by itself
 *   - none of it happens if any of it fails
 */
class PurchaseServiceTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private Warehouse $warehouse;

    private Supplier $supplier;

    private PurchaseService $purchases;

    private StockService $stock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        CurrentShop::forget();

        $this->shop = Shop::firstOrFail();
        $this->warehouse = Warehouse::allShops()->where('shop_id', $this->shop->id)->firstOrFail();

        $this->stock = new StockService();
        $this->purchases = new PurchaseService($this->stock, new SupplierLedgerService());

        $supplier = new Supplier([
            'shop_id' => $this->shop->id,
            'name' => 'Test Distributors',
            'code' => 'S00001',
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
            'name' => 'Purchase Product '.$counter,
            'slug' => Product::uniqueSlug('Purchase Product '.$counter),
            'sku' => Product::generateSku('PUR'.$counter),
            'unit_id' => Unit::where('code', 'PCS')->value('id'),
            'purchase_price' => 100,
            'mrp' => 200,
            'selling_price' => 180,
            ...$attributes,
        ]);
    }

    /**
     * A draft receipt with the given lines.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array<string, mixed>  $attributes
     */
    private function receipt(array $lines, array $attributes = []): GoodsReceipt
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
            ...$attributes,
        ]);

        $receipt->saveQuietly();

        foreach ($lines as $line) {
            /** @var Product $product */
            $product = $line['product'];

            $receipt->items()->create([
                'product_id' => $product->id,
                'product_name' => $product->name,
                'sku' => $product->sku,
                'unit_code' => $product->unit?->code,
                'quantity' => $line['quantity'],
                'free_quantity' => $line['free'] ?? 0,
                'unit_cost' => $line['cost'],
                'tax_rate' => $line['tax'] ?? 0,
                'discount_percent' => $line['discount'] ?? 0,
                'batch_no' => $line['batch'] ?? null,
                'expiry_date' => $line['expiry'] ?? null,
                'mrp' => $line['mrp'] ?? 0,
                'selling_price' => $line['selling'] ?? 0,
            ]);
        }

        return $receipt->fresh();
    }

    private function slot(Product $product, ?Batch $batch = null): ProductStock
    {
        return ProductStock::allShops()
            ->where('shop_id', $this->shop->id)
            ->where('product_id', $product->id)
            ->where('batch_id', $batch?->id)
            ->firstOrFail();
    }

    /* -------------------------------------------------------------- posting */

    public function test_posting_puts_the_stock_on_the_shelf(): void
    {
        $product = $this->product();

        $receipt = $this->receipt([
            ['product' => $product, 'quantity' => 20, 'cost' => 100],
        ]);

        $this->purchases->post($receipt);

        $this->assertSame(GoodsReceipt::POSTED, $receipt->fresh()->status);
        $this->assertSame(20.0, $product->stockOnHand($this->shop->id));

        $movement = StockMovement::allShops()
            ->where('product_id', $product->id)
            ->where('type', StockMovement::PURCHASE)
            ->sole();

        $this->assertSame(20.0, (float) $movement->quantity);
    }

    public function test_freight_is_spread_by_value_and_folded_into_the_cost(): void
    {
        /*
         | ₹300 of freight on ₹1000 and ₹3000 of goods should split ₹75/₹225,
         | not ₹150/₹150. Costing it evenly per line - or ignoring it - is
         | what makes a shop's reported margins quietly wrong.
         */
        $cheap = $this->product();
        $dear = $this->product();

        $receipt = $this->receipt([
            ['product' => $cheap, 'quantity' => 10, 'cost' => 100],   // ₹1000
            ['product' => $dear, 'quantity' => 10, 'cost' => 300],    // ₹3000
        ], ['other_charges' => 300]);

        $this->purchases->post($receipt);

        $items = $receipt->fresh()->items->keyBy('product_id');

        // (1000 + 75) / 10 and (3000 + 225) / 10
        $this->assertSame(107.50, (float) $items[$cheap->id]->landed_cost);
        $this->assertSame(322.50, (float) $items[$dear->id]->landed_cost);

        $this->assertSame(107.50, (float) $this->slot($cheap)->average_cost);
        $this->assertSame(322.50, (float) $this->slot($dear)->average_cost);
    }

    public function test_free_stock_lands_and_pulls_the_average_cost_down(): void
    {
        // 10 paid at ₹100 plus 2 free is 12 units for ₹1000 - so ₹83.33 each,
        // which is what the shop actually paid per sellable unit.
        $product = $this->product();

        $receipt = $this->receipt([
            ['product' => $product, 'quantity' => 10, 'free' => 2, 'cost' => 100],
        ]);

        $this->purchases->post($receipt);

        $this->assertSame(12.0, $product->stockOnHand($this->shop->id));
        $this->assertEqualsWithDelta(83.3333, (float) $this->slot($product)->average_cost, 0.001);
    }

    public function test_a_line_discount_reduces_what_the_stock_is_carried_at(): void
    {
        $product = $this->product();

        $receipt = $this->receipt([
            ['product' => $product, 'quantity' => 10, 'cost' => 100, 'discount' => 10],
        ]);

        $this->purchases->post($receipt);

        $this->assertSame(90.0, (float) $this->slot($product)->average_cost);
        $this->assertSame(900.00, (float) $receipt->fresh()->subtotal);
    }

    /* --------------------------------------------------------------- batches */

    public function test_a_batch_tracked_line_creates_the_lot_it_is_received_into(): void
    {
        $product = $this->product(['track_batches' => true]);

        $receipt = $this->receipt([
            [
                'product' => $product,
                'quantity' => 15,
                'cost' => 100,
                'batch' => 'LOT-2026-A',
                'expiry' => today()->addYear()->toDateString(),
            ],
        ]);

        $this->purchases->post($receipt);

        $batch = Batch::allShops()
            ->where('product_id', $product->id)
            ->where('batch_no', 'LOT-2026-A')
            ->sole();

        $this->assertSame(today()->addYear()->toDateString(), $batch->expiry_date->toDateString());
        $this->assertSame(15.0, (float) $this->slot($product, $batch)->quantity);
        $this->assertSame($batch->id, $receipt->fresh()->items->first()->batch_id);
    }

    public function test_a_batch_tracked_line_without_a_number_is_refused(): void
    {
        // A lot received without a number can never be sold oldest-first.
        $product = $this->product(['track_batches' => true]);

        $receipt = $this->receipt([
            ['product' => $product, 'quantity' => 15, 'cost' => 100],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('batch number');

        $this->purchases->post($receipt);
    }

    public function test_a_refused_receipt_leaves_nothing_behind(): void
    {
        $good = $this->product();
        $bad = $this->product(['track_batches' => true]);

        $receipt = $this->receipt([
            ['product' => $good, 'quantity' => 10, 'cost' => 100],
            ['product' => $bad, 'quantity' => 5, 'cost' => 200],
        ]);

        try {
            $this->purchases->post($receipt);
        } catch (RuntimeException) {
            // expected
        }

        // The whole posting is one transaction, so the first line must not
        // have landed either.
        $this->assertSame(0.0, $good->stockOnHand($this->shop->id));
        $this->assertSame(GoodsReceipt::DRAFT, $receipt->fresh()->status);
        $this->assertSame(0.00, (float) $this->supplier->fresh()->balance);
    }

    public function test_receiving_the_same_batch_again_keeps_its_dates(): void
    {
        $product = $this->product(['track_batches' => true]);
        $expiry = today()->addYear()->toDateString();

        $this->purchases->post($this->receipt([
            ['product' => $product, 'quantity' => 10, 'cost' => 100, 'batch' => 'SAME', 'expiry' => $expiry],
        ]));

        // A second delivery of the same lot, this time with no date on the
        // paperwork. It must not blank out what the first one recorded.
        $this->purchases->post($this->receipt([
            ['product' => $product, 'quantity' => 5, 'cost' => 100, 'batch' => 'SAME'],
        ]));

        $batch = Batch::allShops()->where('batch_no', 'SAME')->sole();

        $this->assertSame($expiry, $batch->expiry_date->toDateString());
        $this->assertSame(15.0, (float) $this->slot($product, $batch)->quantity);
    }

    /* -------------------------------------------------------------- supplier */

    public function test_posting_bills_the_supplier_once(): void
    {
        $product = $this->product();

        $receipt = $this->receipt([
            ['product' => $product, 'quantity' => 10, 'cost' => 100, 'tax' => 18],
        ]);

        $this->purchases->post($receipt);

        // ₹1000 + 18% = ₹1180.
        $this->assertSame(1180.00, (float) $receipt->fresh()->grand_total);
        $this->assertSame(1180.00, (float) $this->supplier->fresh()->balance);

        $entry = SupplierLedger::allShops()
            ->where('supplier_id', $this->supplier->id)
            ->sole();

        $this->assertSame(SupplierLedger::BILL, $entry->type);
        $this->assertSame(1180.00, (float) $entry->credit);
    }

    public function test_the_due_date_follows_the_suppliers_terms(): void
    {
        $product = $this->product();

        $receipt = $this->receipt([
            ['product' => $product, 'quantity' => 10, 'cost' => 100],
        ]);

        $this->purchases->post($receipt);

        $this->assertSame(
            today()->addDays(30)->toDateString(),
            $receipt->fresh()->due_date->toDateString(),
        );
    }

    public function test_an_inter_state_purchase_is_taxed_as_igst(): void
    {
        $product = $this->product();

        $receipt = $this->receipt([
            ['product' => $product, 'quantity' => 10, 'cost' => 100, 'tax' => 18],
        ], ['is_inter_state' => true]);

        $this->purchases->post($receipt);

        $fresh = $receipt->fresh();

        $this->assertSame(180.00, (float) $fresh->igst_total);
        $this->assertSame(0.00, (float) $fresh->cgst_total);
    }

    /* -------------------------------------------------------------- pricing */

    public function test_a_price_change_on_the_consignment_reaches_the_product(): void
    {
        // The step everyone forgets: the price rise comes with the goods.
        $product = $this->product(['mrp' => 200, 'selling_price' => 180]);

        $receipt = $this->receipt([
            ['product' => $product, 'quantity' => 10, 'cost' => 120, 'mrp' => 240, 'selling' => 215],
        ]);

        $this->purchases->post($receipt);

        $fresh = $product->fresh();

        $this->assertSame(240.0, (float) $fresh->mrp);
        $this->assertSame(215.0, (float) $fresh->selling_price);
        $this->assertSame(120.0, (float) $fresh->purchase_price);
    }

    public function test_a_receipt_with_no_prices_leaves_the_product_alone(): void
    {
        $product = $this->product(['mrp' => 200, 'selling_price' => 180]);

        $receipt = $this->receipt([
            ['product' => $product, 'quantity' => 10, 'cost' => 100],
        ]);

        $this->purchases->post($receipt);

        $fresh = $product->fresh();

        $this->assertSame(200.0, (float) $fresh->mrp);
        $this->assertSame(180.0, (float) $fresh->selling_price);
    }

    /* ------------------------------------------------------- purchase order */

    public function test_receiving_against_an_order_ticks_it_off(): void
    {
        $product = $this->product();

        $order = new PurchaseOrder([
            'shop_id' => $this->shop->id,
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'reference' => PurchaseOrder::nextReference($this->shop),
            'ordered_on' => today(),
            'status' => PurchaseOrder::APPROVED,
        ]);
        $order->saveQuietly();

        $order->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'sku' => $product->sku,
            'quantity' => 20,
            'unit_cost' => 100,
        ]);

        // A part delivery first.
        $this->purchases->post($this->receipt([
            ['product' => $product, 'quantity' => 8, 'cost' => 100],
        ], ['purchase_order_id' => $order->id]));

        $this->assertSame(PurchaseOrder::PARTIAL, $order->fresh()->status);
        $this->assertSame(8.0, (float) $order->fresh()->items->first()->received_quantity);

        // Then the rest.
        $this->purchases->post($this->receipt([
            ['product' => $product, 'quantity' => 12, 'cost' => 100],
        ], ['purchase_order_id' => $order->id]));

        $this->assertSame(PurchaseOrder::RECEIVED, $order->fresh()->status);
    }

    /* --------------------------------------------------------------- cancel */

    public function test_cancelling_takes_the_stock_back_and_reverses_the_bill(): void
    {
        $product = $this->product();

        $receipt = $this->receipt([
            ['product' => $product, 'quantity' => 20, 'cost' => 100],
        ]);

        $this->purchases->post($receipt);

        $this->assertSame(20.0, $product->stockOnHand($this->shop->id));
        $this->assertSame(2000.00, (float) $this->supplier->fresh()->balance);

        $this->purchases->cancel($receipt->fresh(), 'Wrong consignment');

        $this->assertSame(GoodsReceipt::CANCELLED, $receipt->fresh()->status);
        $this->assertSame(0.0, $product->stockOnHand($this->shop->id));
        $this->assertSame(0.00, (float) $this->supplier->fresh()->balance);
    }

    public function test_cancelling_is_refused_once_the_stock_has_been_sold(): void
    {
        /*
         | Taking back units that are no longer there would drive the shelf
         | negative and make every later count wrong. A purchase return is
         | the right document at that point.
         */
        $product = $this->product();

        $receipt = $this->receipt([
            ['product' => $product, 'quantity' => 20, 'cost' => 100],
        ]);

        $this->purchases->post($receipt);

        $this->stock->issue($product, 15, $this->warehouse, null,
            StockMovement::SALE, null, null, $this->shop->id);

        $this->expectException(RuntimeException::class);

        $this->purchases->cancel($receipt->fresh(), 'Too late');
    }

    public function test_posting_twice_is_refused(): void
    {
        $product = $this->product();

        $receipt = $this->receipt([
            ['product' => $product, 'quantity' => 10, 'cost' => 100],
        ]);

        $this->purchases->post($receipt);

        $this->expectException(RuntimeException::class);

        $this->purchases->post($receipt->fresh());
    }

    public function test_posting_an_empty_receipt_is_refused(): void
    {
        $receipt = $this->receipt([]);

        $this->expectException(RuntimeException::class);

        $this->purchases->post($receipt);
    }
}
