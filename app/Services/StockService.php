<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Shop;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The only place stock ever changes.
 *
 * Two things have to stay true of every quantity in the system:
 *
 *   1. product_stocks always equals the sum of stock_movements for its slot.
 *   2. No quantity ever changes without a ledger row saying why.
 *
 * Keeping both is only tractable if there is exactly one door. Controllers,
 * jobs and importers all come through here; nothing else writes to
 * product_stocks, and nothing at all writes to stock_movements.
 *
 * Every public method runs in a transaction and takes a row lock on the
 * affected slot, because two cashiers selling the last bag at the same
 * moment is an ordinary Saturday, not an edge case.
 */
class StockService
{
    /**
     * Shops already looked up, memoised per instance.
     *
     * The negative-stock and expiry rules are read once per line item, and a
     * ten-line invoice should not be ten queries for the same row.
     *
     * @var array<int, Shop|null>
     */
    private array $shops = [];

    /**
     * Put stock in.
     *
     * The unit cost feeds the weighted average, which is what stock
     * valuation and margin are later worked out from - so a receipt that
     * does not know its cost should pass 0 rather than a guess.
     */
    public function receive(
        Product $product,
        float $quantity,
        ?Warehouse $warehouse = null,
        ?Batch $batch = null,
        float $unitCost = 0,
        string $type = StockMovement::PURCHASE,
        ?Model $reference = null,
        ?string $reason = null,
        ?int $shopId = null,
    ): ProductStock {
        if ($quantity <= 0) {
            throw new RuntimeException('A receipt has to be for a positive quantity.');
        }

        return $this->apply(
            $product, abs($quantity), $warehouse, $batch, $unitCost, $type, $reference, $reason, $shopId
        );
    }

    /**
     * Take stock out.
     *
     * Refused when it would go below zero, unless the shop has explicitly
     * turned that on. The check happens inside the lock, so it cannot be
     * won by two requests at once.
     */
    public function issue(
        Product $product,
        float $quantity,
        ?Warehouse $warehouse = null,
        ?Batch $batch = null,
        string $type = StockMovement::SALE,
        ?Model $reference = null,
        ?string $reason = null,
        ?int $shopId = null,
    ): ProductStock {
        if ($quantity <= 0) {
            throw new RuntimeException('An issue has to be for a positive quantity.');
        }

        return $this->apply(
            $product, -abs($quantity), $warehouse, $batch, 0, $type, $reference, $reason, $shopId
        );
    }

    /**
     * Correct a slot to a counted quantity.
     *
     * Takes the target rather than the delta because that is what a stock
     * count produces, and working the difference out here removes the one
     * place a human would get the sign wrong. A reason is mandatory: an
     * unexplained adjustment is indistinguishable from theft.
     */
    public function adjustTo(
        Product $product,
        float $countedQuantity,
        string $reason,
        ?Warehouse $warehouse = null,
        ?Batch $batch = null,
        ?Model $reference = null,
        ?int $shopId = null,
        ?float $unitCost = null,
    ): ProductStock {
        if (trim($reason) === '') {
            throw new RuntimeException('A stock adjustment needs a reason.');
        }

        $shopId = $this->resolveShopId($shopId);
        $warehouse = $this->resolveWarehouse($warehouse, $shopId);

        return DB::transaction(function () use (
            $product, $countedQuantity, $reason, $warehouse, $batch, $reference, $shopId, $unitCost
        ) {
            $slot = $this->lockSlot($shopId, $warehouse, $product, $batch);
            $delta = $countedQuantity - (float) $slot->quantity;

            if (abs($delta) < 0.0005) {
                // Nothing moved. Writing a zero-quantity ledger row would be
                // noise in the one place noise is most expensive.
                return $slot;
            }

            /*
             | An adjustment that finds stock the system did not know about -
             | opening stock, most often - is the first time that slot has
             | ever had a cost. Without one it would be valued at nothing,
             | and every margin calculated from it would be pure profit.
             |
             | So: the caller's cost when it gave one, the slot's existing
             | average otherwise, and the product's purchase price as the
             | last resort before zero.
             */
            $cost = $unitCost !== null && $unitCost > 0
                ? $unitCost
                : (float) $slot->average_cost;

            if ($delta > 0 && $cost <= 0) {
                $cost = (float) ($batch?->purchase_price ?: $product->purchasePriceFor($shopId));
            }

            return $this->write(
                $slot, $delta, $cost,
                StockMovement::ADJUSTMENT, $reference, $reason, $product, $warehouse, $batch, $shopId
            );
        });
    }

    /**
     * Move stock between two warehouses, which may be in different shops.
     *
     * Written as two ledger rows rather than one, because that is what it
     * is: the sending shop is short and the receiving shop is long, and each
     * has to be able to read its own side of the movement in its own ledger.
     *
     * @return array{out: ProductStock, in: ProductStock}
     */
    public function transfer(
        Product $product,
        float $quantity,
        Warehouse $from,
        Warehouse $to,
        ?Batch $fromBatch = null,
        ?Model $reference = null,
        ?string $reason = null,
    ): array {
        if ($quantity <= 0) {
            throw new RuntimeException('A transfer has to be for a positive quantity.');
        }

        if ($from->id === $to->id) {
            throw new RuntimeException('A transfer needs two different warehouses.');
        }

        return DB::transaction(function () use ($product, $quantity, $from, $to, $fromBatch, $reference, $reason) {
            $out = $this->apply(
                $product, -abs($quantity), $from, $fromBatch,
                0, StockMovement::TRANSFER_OUT, $reference,
                $reason ?? 'Transfer to '.$to->name, $from->shop_id
            );

            /*
             | Crossing shops means the lot has to exist on the other side
             | too: a batch belongs to one shop, so the receiving shop needs
             | its own row with the same number, dates and cost.
             */
            $toBatch = $fromBatch === null
                ? null
                : ($from->shop_id === $to->shop_id
                    ? $fromBatch
                    : $this->mirrorBatch($fromBatch, $to->shop_id));

            $in = $this->apply(
                $product, abs($quantity), $to, $toBatch,
                (float) $out->average_cost, StockMovement::TRANSFER_IN, $reference,
                $reason ?? 'Transfer from '.$from->name, $to->shop_id
            );

            return ['out' => $out, 'in' => $in];
        });
    }

    /* ------------------------------------------------------- reservation */

    /**
     * Hold stock for an online order that is placed but not yet picked.
     *
     * Reservation is not a movement: nothing has left the shelf, so it
     * writes no ledger row. It only narrows what the next sale may take.
     */
    public function reserve(
        Product $product,
        float $quantity,
        ?Warehouse $warehouse = null,
        ?Batch $batch = null,
        ?int $shopId = null,
    ): ProductStock {
        $shopId = $this->resolveShopId($shopId);
        $warehouse = $this->resolveWarehouse($warehouse, $shopId);

        return DB::transaction(function () use ($product, $quantity, $warehouse, $batch, $shopId) {
            $slot = $this->lockSlot($shopId, $warehouse, $product, $batch);

            if ($slot->available() < $quantity && ! $this->allowsNegative($shopId)) {
                throw new RuntimeException(sprintf(
                    'Only %s of "%s" is available to reserve.',
                    rtrim(rtrim(number_format($slot->available(), 3), '0'), '.'),
                    $product->name,
                ));
            }

            $slot->forceFill(['reserved' => (float) $slot->reserved + $quantity])->save();

            return $slot;
        });
    }

    /** Give a reservation back, without ever going below zero. */
    public function release(
        Product $product,
        float $quantity,
        ?Warehouse $warehouse = null,
        ?Batch $batch = null,
        ?int $shopId = null,
    ): ProductStock {
        $shopId = $this->resolveShopId($shopId);
        $warehouse = $this->resolveWarehouse($warehouse, $shopId);

        return DB::transaction(function () use ($product, $quantity, $warehouse, $batch, $shopId) {
            $slot = $this->lockSlot($shopId, $warehouse, $product, $batch);

            $slot->forceFill([
                'reserved' => max(0, (float) $slot->reserved - $quantity),
            ])->save();

            return $slot;
        });
    }

    /* ---------------------------------------------------------- picking */

    /**
     * Choose which lots to take a quantity from, oldest expiry first.
     *
     * Returns the plan rather than executing it, so the caller can show it,
     * price each line at its own batch cost, and only then commit. A short
     * pick comes back short: it is the caller's business whether that is an
     * error or a partial fulfilment.
     *
     * @return Collection<int, array{batch: Batch, warehouse: Warehouse, quantity: float, unit_cost: float}>
     */
    public function planPick(
        Product $product,
        float $quantity,
        ?Warehouse $warehouse = null,
        ?int $shopId = null,
        ?bool $blockExpired = null,
    ): Collection {
        $shopId = $this->resolveShopId($shopId);
        $blockExpired ??= $this->shop($shopId)?->block_expired_sale ?? true;

        $slots = ProductStock::query()
            ->with(['batch', 'warehouse'])
            ->where('shop_id', $shopId)
            ->where('product_id', $product->id)
            ->forWarehouse($warehouse?->id)
            ->inStock()
            ->get()
            // FEFO across warehouses: what expires first goes first, and an
            // undated lot is used only once the dated ones are gone.
            ->sortBy(fn (ProductStock $slot) => $slot->batch?->expiry_date?->timestamp ?? PHP_INT_MAX)
            ->values();

        $plan = collect();
        $remaining = $quantity;

        foreach ($slots as $slot) {
            if ($remaining <= 0) {
                break;
            }

            if ($blockExpired && $slot->batch?->isExpired()) {
                continue;
            }

            $take = min($remaining, $slot->available());

            if ($take <= 0) {
                continue;
            }

            $plan->push([
                'batch' => $slot->batch,
                'warehouse' => $slot->warehouse,
                'quantity' => $take,
                'unit_cost' => (float) $slot->average_cost,
            ]);

            $remaining -= $take;
        }

        return $plan;
    }

    /* ------------------------------------------------------------ internals */

    /**
     * The one write path: lock the slot, move it, log it.
     */
    private function apply(
        Product $product,
        float $delta,
        ?Warehouse $warehouse,
        ?Batch $batch,
        float $unitCost,
        string $type,
        ?Model $reference,
        ?string $reason,
        ?int $shopId,
    ): ProductStock {
        $shopId = $this->resolveShopId($shopId);
        $warehouse = $this->resolveWarehouse($warehouse, $shopId);

        return DB::transaction(function () use (
            $product, $delta, $warehouse, $batch, $unitCost, $type, $reference, $reason, $shopId
        ) {
            $slot = $this->lockSlot($shopId, $warehouse, $product, $batch);

            if ($delta < 0) {
                $this->guardAvailability($slot, $product, abs($delta), $shopId, $type);
            }

            return $this->write(
                $slot, $delta, $unitCost, $type, $reference, $reason, $product, $warehouse, $batch, $shopId
            );
        });
    }

    /**
     * Update the slot and append the ledger row, as one unit.
     *
     * Called from inside a transaction that already holds the row lock.
     */
    private function write(
        ProductStock $slot,
        float $delta,
        float $unitCost,
        string $type,
        ?Model $reference,
        ?string $reason,
        Product $product,
        Warehouse $warehouse,
        ?Batch $batch,
        int $shopId,
    ): ProductStock {
        $before = (float) $slot->quantity;
        $after = $before + $delta;

        $slot->forceFill([
            'quantity' => $after,
            'average_cost' => $this->nextAverageCost($before, (float) $slot->average_cost, $delta, $unitCost),
        ])->save();

        $user = Auth::user();

        StockMovement::query()->create([
            'shop_id' => $shopId,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'batch_id' => $batch?->id,
            'type' => $type,
            'quantity' => $delta,
            'balance_after' => $after,
            'unit_cost' => $delta > 0 ? $unitCost : (float) $slot->average_cost,
            'reference_type' => $reference ? $reference::class : null,
            'reference_id' => $reference?->getKey(),
            'reason' => $reason,
            'user_id' => $user?->id,
            'user_name' => $user?->name ?? 'System',
        ]);

        return $slot->refresh();
    }

    /**
     * Weighted average cost after a movement.
     *
     * Only inward movements move the average - taking stock out at whatever
     * it happens to be selling for would make the valuation drift with the
     * sale price, which is exactly what a cost basis must not do.
     */
    private function nextAverageCost(float $before, float $currentAverage, float $delta, float $unitCost): float
    {
        if ($delta <= 0) {
            return $currentAverage;
        }

        // First receipt, or stock that had run to nothing: the new cost is
        // the only information there is.
        if ($before <= 0) {
            return $unitCost > 0 ? $unitCost : $currentAverage;
        }

        if ($unitCost <= 0) {
            return $currentAverage;
        }

        $total = ($before * $currentAverage) + ($delta * $unitCost);

        return $total / ($before + $delta);
    }

    /**
     * Find or create the slot, locked for the rest of the transaction.
     */
    private function lockSlot(int $shopId, Warehouse $warehouse, Product $product, ?Batch $batch): ProductStock
    {
        $keys = [
            'shop_id' => $shopId,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'batch_id' => $batch?->id,
        ];

        $existing = ProductStock::allShops()
            ->where($keys)
            ->lockForUpdate()
            ->first();

        if ($existing) {
            return $existing;
        }

        /*
         | Racing creates are settled by the unique index on
         | (shop_id, warehouse_id, product_id, batch_key) - see the
         | migration. The loser re-reads the winner's row and locks that
         | instead, rather than failing a sale over a duplicate slot.
         */
        try {
            $created = ProductStock::query()->create($keys + [
                'quantity' => 0,
                'reserved' => 0,
                'average_cost' => 0,
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            return ProductStock::allShops()->where($keys)->lockForUpdate()->firstOrFail();
        }

        return ProductStock::allShops()->whereKey($created->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * Refuse an issue that would take the slot below zero.
     */
    private function guardAvailability(
        ProductStock $slot,
        Product $product,
        float $wanted,
        int $shopId,
        string $type = StockMovement::SALE,
    ): void {
        if ($this->allowsNegative($shopId)) {
            return;
        }

        /*
         | Kitchen consumption is never refused (§10).
         |
         | `allow_negative_stock` is a commercial decision - may the counter
         | sell something it does not have? This is not that question. The
         | flour has already gone into the naan, and refusing to *record* it
         | because the count disagrees leaves the shelf reading as full when
         | it is empty, which is strictly worse than a negative balance.
         |
         | A negative here is a true statement about a kitchen that has not
         | been counting, and it is exactly what the low-stock alert exists
         | to shout about.
         */
        if ($type === StockMovement::CONSUMPTION) {
            return;
        }

        if ((float) $slot->quantity >= $wanted) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Not enough stock: "%s" has %s on hand and %s was asked for.',
            $product->name,
            $this->trim((float) $slot->quantity),
            $this->trim($wanted),
        ));
    }

    private function allowsNegative(int $shopId): bool
    {
        return (bool) ($this->shop($shopId)?->allow_negative_stock ?? false);
    }

    /**
     * The receiving shop's copy of a lot, created on first sight.
     */
    private function mirrorBatch(Batch $batch, int $shopId): Batch
    {
        return Batch::allShops()->firstOrCreate(
            [
                'shop_id' => $shopId,
                'product_id' => $batch->product_id,
                'batch_no' => $batch->batch_no,
            ],
            [
                'mfg_date' => $batch->mfg_date,
                'expiry_date' => $batch->expiry_date,
                'purchase_price' => $batch->purchase_price,
                'mrp' => $batch->mrp,
                'selling_price' => $batch->selling_price,
                'supplier_batch_ref' => $batch->supplier_batch_ref,
                'is_active' => true,
            ]
        );
    }

    private function resolveShopId(?int $shopId): int
    {
        $shopId ??= \App\Support\CurrentShop::idForWrite();

        if ($shopId === null) {
            throw new RuntimeException(
                'No shop is selected. Choose a shop before moving stock.'
            );
        }

        return $shopId;
    }

    private function resolveWarehouse(?Warehouse $warehouse, int $shopId): Warehouse
    {
        if ($warehouse !== null) {
            if ((int) $warehouse->shop_id !== $shopId) {
                throw new RuntimeException('That warehouse belongs to a different shop.');
            }

            return $warehouse;
        }

        return Warehouse::defaultFor($shopId)
            ?? throw new RuntimeException('That shop has no warehouse to store stock in.');
    }

    private function shop(int $shopId): ?Shop
    {
        return $this->shops[$shopId] ??= Shop::withTrashed()->find($shopId);
    }

    /** "12.5" rather than "12.500". */
    private function trim(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.') ?: '0';
    }
}
