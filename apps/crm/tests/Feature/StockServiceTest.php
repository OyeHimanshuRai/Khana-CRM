<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Shop;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\StockService;
use App\Support\CurrentShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * The inventory invariants.
 *
 * Two things must always hold, and everything downstream - valuation,
 * margin, the reorder list, the expiry sweep - assumes them:
 *
 *   1. product_stocks equals the sum of stock_movements for its slot.
 *   2. Nothing changes a quantity without leaving a ledger row.
 *
 * Between them they cover the SRS's "stock changes correctly after
 * purchase, sale, return and adjustment".
 */
class StockServiceTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private Warehouse $warehouse;

    private StockService $stock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        CurrentShop::forget();

        $this->shop = Shop::firstOrFail();
        $this->warehouse = Warehouse::allShops()->where('shop_id', $this->shop->id)->firstOrFail();
        $this->stock = new StockService();
    }

    protected function tearDown(): void
    {
        CurrentShop::forget();

        parent::tearDown();
    }

    /* ------------------------------------------------------------ fixtures */

    private function product(array $attributes = []): Product
    {
        $unit = Unit::where('code', 'KG')->firstOrFail();

        return Product::create([
            'name' => 'Urea 50kg',
            'slug' => Product::uniqueSlug('Urea 50kg'),
            'sku' => Product::generateSku('Urea'),
            'unit_id' => $unit->id,
            'purchase_price' => 250,
            'mrp' => 300,
            'selling_price' => 290,
            ...$attributes,
        ]);
    }

    private function batch(Product $product, string $number, ?string $expiry = null): Batch
    {
        $batch = new Batch([
            'shop_id' => $this->shop->id,
            'product_id' => $product->id,
            'batch_no' => $number,
            'expiry_date' => $expiry,
            'purchase_price' => 250,
        ]);

        $batch->saveQuietly();

        return $batch;
    }

    private function slot(Product $product, ?Batch $batch = null): ProductStock
    {
        return ProductStock::allShops()
            ->where('shop_id', $this->shop->id)
            ->where('product_id', $product->id)
            ->where('batch_id', $batch?->id)
            ->firstOrFail();
    }

    /** The ledger's own answer, independent of the cached total. */
    private function ledgerBalance(Product $product): float
    {
        return (float) StockMovement::allShops()
            ->where('shop_id', $this->shop->id)
            ->where('product_id', $product->id)
            ->sum('quantity');
    }

    /* ------------------------------------------------------------ receipts */

    public function test_a_receipt_adds_stock_and_writes_a_ledger_row(): void
    {
        $product = $this->product();

        $this->stock->receive(
            $product, 100, $this->warehouse, null, 250.0,
            StockMovement::PURCHASE, null, 'Opening consignment', $this->shop->id
        );

        $this->assertSame(100.0, (float) $this->slot($product)->quantity);
        $this->assertSame(100.0, $this->ledgerBalance($product));

        $movement = StockMovement::allShops()->where('product_id', $product->id)->sole();
        $this->assertSame(StockMovement::PURCHASE, $movement->type);
        $this->assertSame(100.0, (float) $movement->quantity);
        $this->assertSame(100.0, (float) $movement->balance_after);
        $this->assertSame('Opening consignment', $movement->reason);
    }

    public function test_a_second_receipt_moves_the_weighted_average_cost(): void
    {
        $product = $this->product();

        $this->stock->receive($product, 100, $this->warehouse, null, 250.0,
            StockMovement::PURCHASE, null, null, $this->shop->id);
        $this->stock->receive($product, 100, $this->warehouse, null, 270.0,
            StockMovement::PURCHASE, null, null, $this->shop->id);

        // (100 x 250 + 100 x 270) / 200
        $this->assertSame(260.0, (float) $this->slot($product)->average_cost);
    }

    public function test_issuing_stock_does_not_move_the_average_cost(): void
    {
        $product = $this->product();

        $this->stock->receive($product, 100, $this->warehouse, null, 250.0,
            StockMovement::PURCHASE, null, null, $this->shop->id);
        $this->stock->issue($product, 40, $this->warehouse, null,
            StockMovement::SALE, null, null, $this->shop->id);

        $slot = $this->slot($product);

        $this->assertSame(60.0, (float) $slot->quantity);
        // Cost basis has to be what receipts paid, not what sales fetched.
        $this->assertSame(250.0, (float) $slot->average_cost);
        $this->assertSame(60.0, $this->ledgerBalance($product));
    }

    /* -------------------------------------------------------- the negative */

    public function test_overselling_is_refused_by_default(): void
    {
        $product = $this->product();

        $this->stock->receive($product, 10, $this->warehouse, null, 250.0,
            StockMovement::PURCHASE, null, null, $this->shop->id);

        $this->expectException(RuntimeException::class);

        $this->stock->issue($product, 25, $this->warehouse, null,
            StockMovement::SALE, null, null, $this->shop->id);
    }

    public function test_a_refused_sale_leaves_the_quantity_untouched(): void
    {
        $product = $this->product();

        $this->stock->receive($product, 10, $this->warehouse, null, 250.0,
            StockMovement::PURCHASE, null, null, $this->shop->id);

        try {
            $this->stock->issue($product, 25, $this->warehouse, null,
                StockMovement::SALE, null, null, $this->shop->id);
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(10.0, (float) $this->slot($product)->quantity);
        $this->assertSame(10.0, $this->ledgerBalance($product));
        $this->assertSame(1, StockMovement::allShops()->where('product_id', $product->id)->count());
    }

    public function test_a_shop_may_opt_in_to_negative_stock(): void
    {
        $this->shop->forceFill(['allow_negative_stock' => true])->save();

        $product = $this->product();

        $this->stock->receive($product, 10, $this->warehouse, null, 250.0,
            StockMovement::PURCHASE, null, null, $this->shop->id);
        $this->stock->issue($product, 25, $this->warehouse, null,
            StockMovement::SALE, null, null, $this->shop->id);

        $this->assertSame(-15.0, (float) $this->slot($product)->quantity);
        $this->assertSame(-15.0, $this->ledgerBalance($product));
    }

    /* ------------------------------------------------------------ returns */

    public function test_a_sales_return_puts_stock_back(): void
    {
        $product = $this->product();

        $this->stock->receive($product, 50, $this->warehouse, null, 250.0,
            StockMovement::PURCHASE, null, null, $this->shop->id);
        $this->stock->issue($product, 20, $this->warehouse, null,
            StockMovement::SALE, null, null, $this->shop->id);
        $this->stock->receive($product, 5, $this->warehouse, null, 250.0,
            StockMovement::SALE_RETURN, null, 'Unopened bag', $this->shop->id);

        $this->assertSame(35.0, (float) $this->slot($product)->quantity);
        $this->assertSame(35.0, $this->ledgerBalance($product));
    }

    public function test_a_purchase_return_takes_stock_out(): void
    {
        $product = $this->product();

        $this->stock->receive($product, 50, $this->warehouse, null, 250.0,
            StockMovement::PURCHASE, null, null, $this->shop->id);
        $this->stock->issue($product, 8, $this->warehouse, null,
            StockMovement::PURCHASE_RETURN, null, 'Damaged in transit', $this->shop->id);

        $this->assertSame(42.0, (float) $this->slot($product)->quantity);
        $this->assertSame(42.0, $this->ledgerBalance($product));
    }

    /* -------------------------------------------------------- adjustments */

    public function test_an_adjustment_corrects_to_the_counted_quantity(): void
    {
        $product = $this->product();

        $this->stock->receive($product, 50, $this->warehouse, null, 250.0,
            StockMovement::PURCHASE, null, null, $this->shop->id);
        $this->stock->adjustTo($product, 47, 'Annual count', $this->warehouse, null, null, $this->shop->id);

        $this->assertSame(47.0, (float) $this->slot($product)->quantity);
        $this->assertSame(47.0, $this->ledgerBalance($product));

        $adjustment = StockMovement::allShops()
            ->where('product_id', $product->id)
            ->where('type', StockMovement::ADJUSTMENT)
            ->sole();

        $this->assertSame(-3.0, (float) $adjustment->quantity);
        $this->assertSame('Annual count', $adjustment->reason);
    }

    public function test_an_adjustment_that_changes_nothing_writes_no_ledger_row(): void
    {
        $product = $this->product();

        $this->stock->receive($product, 50, $this->warehouse, null, 250.0,
            StockMovement::PURCHASE, null, null, $this->shop->id);
        $this->stock->adjustTo($product, 50, 'Count matched', $this->warehouse, null, null, $this->shop->id);

        // One row - the receipt. A zero-quantity entry would be noise in the
        // one place noise is most expensive.
        $this->assertSame(1, StockMovement::allShops()->where('product_id', $product->id)->count());
    }

    public function test_opening_stock_takes_a_cost_rather_than_being_valued_at_nothing(): void
    {
        // Nothing has ever been received, so the slot has no average cost.
        // An adjustment that finds stock still has to value it, or every
        // margin worked out from it would read as pure profit.
        $product = $this->product(['purchase_price' => 250]);

        $this->stock->adjustTo($product, 40, 'Opening stock', $this->warehouse, null, null, $this->shop->id);

        $this->assertSame(250.0, (float) $this->slot($product)->average_cost);
    }

    public function test_an_explicit_cost_beats_the_products_list_price(): void
    {
        $product = $this->product(['purchase_price' => 250]);

        $this->stock->adjustTo(
            $product, 40, 'Opening stock', $this->warehouse, null, null, $this->shop->id, 205.0
        );

        $this->assertSame(205.0, (float) $this->slot($product)->average_cost);
    }

    public function test_an_adjustment_without_a_reason_is_refused(): void
    {
        $product = $this->product();

        $this->expectException(RuntimeException::class);

        $this->stock->adjustTo($product, 10, '   ', $this->warehouse, null, null, $this->shop->id);
    }

    /* --------------------------------------------------------- reservation */

    public function test_a_reservation_narrows_availability_without_moving_stock(): void
    {
        $product = $this->product();

        $this->stock->receive($product, 30, $this->warehouse, null, 250.0,
            StockMovement::PURCHASE, null, null, $this->shop->id);
        $this->stock->reserve($product, 12, $this->warehouse, null, $this->shop->id);

        $slot = $this->slot($product);

        $this->assertSame(30.0, (float) $slot->quantity);
        $this->assertSame(12.0, (float) $slot->reserved);
        $this->assertSame(18.0, $slot->available());

        // Reserving is not a movement, so the ledger is untouched.
        $this->assertSame(1, StockMovement::allShops()->where('product_id', $product->id)->count());
    }

    public function test_releasing_never_drives_a_reservation_below_zero(): void
    {
        $product = $this->product();

        $this->stock->receive($product, 30, $this->warehouse, null, 250.0,
            StockMovement::PURCHASE, null, null, $this->shop->id);
        $this->stock->reserve($product, 5, $this->warehouse, null, $this->shop->id);
        $this->stock->release($product, 50, $this->warehouse, null, $this->shop->id);

        $this->assertSame(0.0, (float) $this->slot($product)->reserved);
    }

    /* --------------------------------------------------------------- FEFO */

    public function test_picking_takes_the_earliest_expiry_first(): void
    {
        $product = $this->product(['track_batches' => true]);

        $late = $this->batch($product, 'LATE', now()->addYear()->toDateString());
        $soon = $this->batch($product, 'SOON', now()->addMonth()->toDateString());

        $this->stock->receive($product, 40, $this->warehouse, $late, 250.0,
            StockMovement::PURCHASE, null, null, $this->shop->id);
        $this->stock->receive($product, 25, $this->warehouse, $soon, 250.0,
            StockMovement::PURCHASE, null, null, $this->shop->id);

        $plan = $this->stock->planPick($product, 30, $this->warehouse, $this->shop->id);

        $this->assertCount(2, $plan);
        $this->assertSame('SOON', $plan[0]['batch']->batch_no);
        $this->assertSame(25.0, $plan[0]['quantity']);
        $this->assertSame('LATE', $plan[1]['batch']->batch_no);
        $this->assertSame(5.0, $plan[1]['quantity']);
    }

    public function test_an_expired_batch_is_skipped_when_the_shop_blocks_it(): void
    {
        $product = $this->product(['track_batches' => true]);

        $good = $this->batch($product, 'GOOD', now()->addMonths(6)->toDateString());
        $dead = $this->batch($product, 'DEAD', now()->subDay()->toDateString());

        $this->stock->receive($product, 20, $this->warehouse, $good, 250.0,
            StockMovement::PURCHASE, null, null, $this->shop->id);
        $this->stock->receive($product, 500, $this->warehouse, $dead, 250.0,
            StockMovement::PURCHASE, null, null, $this->shop->id);

        $blocked = $this->stock->planPick($product, 1000, $this->warehouse, $this->shop->id, true);
        $this->assertSame(20.0, (float) $blocked->sum('quantity'));

        // A stock-take or a write-off still has to be able to see it.
        $unblocked = $this->stock->planPick($product, 1000, $this->warehouse, $this->shop->id, false);
        $this->assertSame(520.0, (float) $unblocked->sum('quantity'));
    }

    /* ----------------------------------------------------------- transfers */

    public function test_a_transfer_writes_both_sides_of_the_movement(): void
    {
        $product = $this->product();

        $godown = Warehouse::withoutEvents(fn () => Warehouse::query()->create([
            'shop_id' => $this->shop->id,
            'name' => 'Godown',
            'code' => 'GODOWN',
            'is_active' => true,
        ]));

        $this->stock->receive($product, 60, $this->warehouse, null, 250.0,
            StockMovement::PURCHASE, null, null, $this->shop->id);

        $result = $this->stock->transfer($product, 25, $this->warehouse, $godown);

        $this->assertSame(35.0, (float) $result['out']->quantity);
        $this->assertSame(25.0, (float) $result['in']->quantity);

        // The shop total is unchanged - stock moved, it did not appear.
        $this->assertSame(60.0, $product->stockOnHand($this->shop->id));
        $this->assertSame(60.0, $this->ledgerBalance($product));

        $this->assertSame(1, StockMovement::allShops()
            ->where('product_id', $product->id)
            ->where('type', StockMovement::TRANSFER_OUT)
            ->count());
        $this->assertSame(1, StockMovement::allShops()
            ->where('product_id', $product->id)
            ->where('type', StockMovement::TRANSFER_IN)
            ->count());
    }

    public function test_a_transfer_carries_the_cost_across(): void
    {
        $product = $this->product();

        $godown = Warehouse::withoutEvents(fn () => Warehouse::query()->create([
            'shop_id' => $this->shop->id,
            'name' => 'Godown',
            'code' => 'GODOWN2',
            'is_active' => true,
        ]));

        $this->stock->receive($product, 60, $this->warehouse, null, 275.0,
            StockMovement::PURCHASE, null, null, $this->shop->id);

        $result = $this->stock->transfer($product, 25, $this->warehouse, $godown);

        // Moving stock must not restate what it cost.
        $this->assertSame(275.0, (float) $result['in']->average_cost);
    }

    public function test_a_transfer_to_the_same_warehouse_is_refused(): void
    {
        $product = $this->product();

        $this->expectException(RuntimeException::class);

        $this->stock->transfer($product, 5, $this->warehouse, $this->warehouse);
    }
}
