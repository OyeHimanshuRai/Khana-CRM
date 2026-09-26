<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\ProductStock;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentItem;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\Warehouse;
use App\Services\StockAdjustmentService;
use App\Services\StockTransferService;
use App\Support\CurrentShop;
use Database\Seeders\Concerns\SeedsDemoData;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Stock-take differences and stock moved between stores.
 *
 * Both documents are pushed through their services, because in both cases
 * the document is only half the story - approving an adjustment is what
 * writes the movement that makes the count true, and a transfer only leaves
 * one shelf and lands on another when it is dispatched and then received.
 *
 * Each is left spread across its lifecycle rather than all approved: the
 * approvals queue is the screen these modules exist for, and an empty queue
 * shows nothing about how it works.
 */
class DemoInventorySeeder extends Seeder
{
    use SeedsDemoData;

    public function __construct(
        private readonly StockAdjustmentService $adjustments,
        private readonly StockTransferService $transfers,
    ) {}

    public function run(): void
    {
        $this->seedRandom(5);

        $this->stockAdjustments();
        $this->stockTransfers();
    }

    /**
     * Twenty stock-take corrections.
     *
     * The counted quantity is nudged either side of what the system thinks,
     * because both directions are real: a bag found behind a pallet and a
     * bag that leaked are the same document with the sign reversed.
     */
    private function stockAdjustments(): void
    {
        if ($this->alreadySeeded('Stock adjustments', StockAdjustment::query()->count())) {
            return;
        }

        $shop = CurrentShop::get();
        $warehouses = Warehouse::allShops()->where('shop_id', $shop->id)->get();
        $user = auth()->user();

        $approved = 0;
        $made = 0;

        for ($i = 0; $i < self::PER_MODULE; $i++) {
            /*
             | The warehouse is taken from the stock, not the other way round.
             | Picking a warehouse first and then looking for stock in it
             | wastes every draw that lands on a store the sales have already
             | emptied, and quietly produces fewer documents than asked for.
             */
            $slots = ProductStock::allShops()
                ->where('shop_id', $shop->id)
                ->where('quantity', '>', 5)
                ->with('product')
                ->inRandomOrder()
                ->take($this->between(1, 4))
                ->get()
                ->groupBy('warehouse_id');

            if ($slots->isEmpty()) {
                continue;
            }

            $warehouseId = $slots->keys()->first();
            $warehouse = $warehouses->firstWhere('id', $warehouseId);
            $slots = $slots->get($warehouseId);

            if (! $warehouse) {
                continue;
            }

            $adjustment = StockAdjustment::query()->create([
                'shop_id' => $shop->id,
                'warehouse_id' => $warehouse->id,
                'reference' => StockAdjustment::nextReference($shop),
                'adjustment_date' => Carbon::today()->subDays($this->between(1, 75)),
                'status' => StockAdjustment::DRAFT,
                'reason_code' => $this->pickKey(StockAdjustment::REASONS),
                'reason' => $this->pick([
                    'Monthly physical count.',
                    'Two bottles found leaking in the rack.',
                    'Recount after the season rush.',
                    'Correcting an entry made against the wrong lot.',
                ]),
                'created_by' => $user?->id,
                'created_by_name' => $user?->name ?? 'System',
            ]);

            foreach ($slots as $slot) {
                $system = (float) $slot->quantity;
                $drift = $this->between(1, max(2, (int) floor($system * 0.06)));
                $counted = $this->chance(55) ? $system - $drift : $system + $drift;

                StockAdjustmentItem::query()->create([
                    'stock_adjustment_id' => $adjustment->id,
                    'product_id' => $slot->product_id,
                    'batch_id' => $slot->batch_id,
                    'system_quantity' => $system,
                    'counted_quantity' => max(0, $counted),
                    'difference' => max(0, $counted) - $system,
                    'unit_cost' => $slot->average_cost,
                ]);
            }

            $made++;

            // Four left waiting, so the approvals screen is not empty.
            if ($i >= self::PER_MODULE - 4) {
                continue;
            }

            try {
                $this->adjustments->approve($adjustment, 'Counted and signed off.');
                $approved++;
            } catch (RuntimeException) {
                // The count would drive a slot negative and the shop refuses
                // that. Leaving it pending is what the counter would do.
            }
        }

        $this->say(sprintf('%d stock adjustments (%d approved).', $made, $approved));
    }

    /**
     * Twenty consignments between the shop's own stores.
     *
     * Deliberately left at every stage of the journey: a few still awaiting
     * approval, a few approved but not yet loaded, a few in transit - which
     * is what puts the dashboard's "consignments in transit to you" alert on
     * the screen - and the rest booked in at the far end.
     */
    private function stockTransfers(): void
    {
        if ($this->alreadySeeded('Stock transfers', StockTransfer::query()->count())) {
            return;
        }

        $shop = CurrentShop::get();
        $warehouses = Warehouse::allShops()->where('shop_id', $shop->id)->get();

        if ($warehouses->count() < 2) {
            $this->say('Stock transfers skipped: the shop has only one warehouse.');

            return;
        }

        $user = auth()->user();
        $made = 0;
        $received = 0;
        $inTransit = 0;

        for ($i = 0; $i < self::PER_MODULE; $i++) {
            $from = $warehouses->random();
            $to = $warehouses->where('id', '!=', $from->id)->random();

            $slots = ProductStock::allShops()
                ->where('shop_id', $shop->id)
                ->where('warehouse_id', $from->id)
                ->where('quantity', '>', 10)
                ->with('product')
                ->inRandomOrder()
                ->take($this->between(1, 3))
                ->get();

            if ($slots->isEmpty()) {
                continue;
            }

            $transfer = StockTransfer::query()->create([
                'shop_id' => $shop->id,
                'from_warehouse_id' => $from->id,
                'to_shop_id' => $shop->id,
                'to_warehouse_id' => $to->id,
                'reference' => StockTransfer::nextReference($shop),
                'transfer_date' => Carbon::today()->subDays($this->between(0, 50)),
                'status' => StockTransfer::DRAFT,
                'note' => sprintf('Moving stock from %s to %s.', $from->name, $to->name),
                'created_by' => $user?->id,
                'created_by_name' => $user?->name ?? 'System',
            ]);

            foreach ($slots as $slot) {
                StockTransferItem::query()->create([
                    'stock_transfer_id' => $transfer->id,
                    'product_id' => $slot->product_id,
                    'batch_id' => $slot->batch_id,
                    'quantity' => max(1, (int) floor((float) $slot->quantity * 0.2)),
                    'unit_cost' => $slot->average_cost,
                ]);
            }

            $made++;

            try {
                // Four stay pending approval.
                if ($i % 5 === 0) {
                    continue;
                }

                $this->transfers->approve($transfer, 'Checked against the request.');

                // Four more are approved but not yet loaded.
                if ($i % 5 === 1) {
                    continue;
                }

                $this->transfers->dispatch($transfer);

                // Four sit in transit, which is what raises the dashboard's
                // "book them in" alert.
                if ($i % 5 === 2) {
                    $inTransit++;

                    continue;
                }

                $this->transfers->receive($transfer, [], 'Counted in at the far end.');
                $received++;
            } catch (RuntimeException) {
                // Not enough left in the source warehouse by the time this
                // one dispatched. The document stays where it got to, which
                // is exactly what would happen on the floor.
            }
        }

        $this->say(sprintf(
            '%d stock transfers (%d received, %d in transit).',
            $made,
            $received,
            $inTransit,
        ));
    }
}
