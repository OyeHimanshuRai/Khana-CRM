<?php

namespace App\Services;

use App\Events\StockMoved;
use App\Models\Batch;
use App\Models\StockItem;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Store;
use App\Stock\Posting;
use App\Support\Outlets;
use App\Support\Units;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Khana\Kit\Support\Decimal;
use RuntimeException;

/**
 * The only place stock ever changes.
 *
 * Two things have to stay true of every quantity in ERP:
 *
 *   1. a level always equals the sum of the movements for its slot;
 *   2. no quantity ever changes without a ledger row saying why.
 *
 * Keeping both is only tractable if there is exactly one door. Receipts,
 * counts, recipes consumed from an event - all of it comes through here;
 * nothing else writes a level, and nothing at all edits a movement.
 *
 * Every public method that writes runs in a transaction and locks the slot
 * it changes. Quantities and rates are the whole numbers described in the
 * stock tables migration; App\Support\Units converts them.
 *
 * Taking stock out is never refused. A kitchen that cooked the dish used the
 * paneer whether or not the books knew it was there, so the level goes
 * negative and sits on a list until a receipt or a count explains it.
 */
class StockService
{
    /* --------------------------------------------------------------- in */

    /**
     * Put stock into a store.
     *
     * The cost rate feeds the weighted average, which is what stock value
     * and every dish's cost are worked out from - so a receipt that does not
     * know its cost should pass 0 rather than a guess.
     */
    public function receive(Store $store, StockItem $item, int $qty, int $costRate, Posting $why, ?Batch $batch = null): StockMovement
    {
        if ($qty <= 0) {
            throw new RuntimeException('Stock comes in as a quantity above zero.');
        }

        return DB::transaction(function () use ($store, $item, $qty, $costRate, $why, $batch) {
            $level = $this->lock($store, $item, $batch?->id ?? 0);

            if ($costRate > 0 && $item->last_cost_rate !== $costRate) {
                $item->forceFill(['last_cost_rate' => $costRate])->saveQuietly();
            }

            return $this->write($level, $item, $qty, $costRate > 0 ? $costRate : $this->knownRate($level, $item), $why);
        });
    }

    /* -------------------------------------------------------------- out */

    /**
     * Take stock out of a store, earliest expiry first.
     *
     * Batches are used in date order and stock with no batch after them.
     * Whatever the store cannot cover is taken from the undated slot anyway,
     * which is how a level goes negative.
     *
     * @return Collection<int, StockMovement>
     */
    public function take(Store $store, StockItem $item, int $qty, Posting $why): Collection
    {
        if ($qty <= 0) {
            throw new RuntimeException('Stock goes out as a quantity above zero.');
        }

        return DB::transaction(function () use ($store, $item, $qty, $why) {
            $movements = collect();
            $left = $qty;

            foreach ($this->pickOrder($store, $item, $why->businessDate) as $level) {
                if ($left <= 0) {
                    break;
                }

                // Read again under the lock: somebody else may have just
                // taken from this slot.
                $level = $this->lock($store, $item, $level->batch_id);
                $take = min($left, $level->qty);

                if ($take <= 0) {
                    continue;
                }

                $movements->push($this->write($level, $item, -$take, $this->knownRate($level, $item), $why));
                $left -= $take;
            }

            if ($left > 0) {
                $level = $this->lock($store, $item, 0);
                $movements->push($this->write($level, $item, -$left, $this->knownRate($level, $item), $why));
            }

            return $movements;
        });
    }

    /** Take stock out of one named batch (0 for the undated slot). */
    public function takeFrom(Store $store, StockItem $item, int $batchId, int $qty, Posting $why): StockMovement
    {
        if ($qty <= 0) {
            throw new RuntimeException('Stock goes out as a quantity above zero.');
        }

        return DB::transaction(function () use ($store, $item, $batchId, $qty, $why) {
            $level = $this->lock($store, $item, $batchId);

            return $this->write($level, $item, -$qty, $this->knownRate($level, $item), $why);
        });
    }

    /**
     * Undo earlier movements exactly: the same slots, the same quantities,
     * the same rates, the other way.
     *
     * Used when what booked them turns out not to have happened - a kitchen
     * line cancelled before cooking began. The original rows stay; the
     * ledger shows both.
     *
     * @param  iterable<StockMovement>  $movements
     * @return Collection<int, StockMovement>
     */
    public function reverse(iterable $movements, Posting $why): Collection
    {
        return DB::transaction(function () use ($movements, $why) {
            $written = collect();

            foreach ($movements as $movement) {
                $store = Store::allOutlets()->findOrFail($movement->store_id);
                $item = StockItem::allTenants()->findOrFail($movement->stock_item_id);
                $level = $this->lock($store, $item, $movement->batch_id);

                $written->push($this->write($level, $item, -$movement->qty, $movement->cost_rate, $why));
            }

            return $written;
        });
    }

    /* ------------------------------------------------------------- move */

    /**
     * Move stock between two stores of one outlet - the main store to the
     * kitchen, most days. Each batch arrives as the batch it left as, at the
     * cost it left at.
     *
     * @return Collection<int, StockMovement> the movements out
     */
    public function move(Store $from, Store $to, StockItem $item, int $qty, Posting $why): Collection
    {
        if ($from->id === $to->id) {
            throw new RuntimeException('Stock moves between two different stores.');
        }

        if ($from->outlet_id !== $to->outlet_id) {
            throw new RuntimeException('Those two stores belong to different outlets.');
        }

        return DB::transaction(function () use ($from, $to, $item, $qty, $why) {
            $out = $this->take($from, $item, $qty, $why->as(StockMovement::TRANSFER_OUT, $why->reason ?? 'To '.$to->name));

            foreach ($out as $movement) {
                $level = $this->lock($to, $item, $movement->batch_id);
                $this->write($level, $item, -$movement->qty, $movement->cost_rate, $why->as(StockMovement::TRANSFER_IN, $why->reason ?? 'From '.$from->name));
            }

            return $out;
        });
    }

    /* ------------------------------------------------------------ count */

    /**
     * Correct a slot to a counted quantity.
     *
     * Takes the target rather than the difference because that is what a
     * count produces, and working the difference out here removes the one
     * place a person would get the sign wrong. Returns null when the count
     * agreed with the books: a zero row would be noise in the ledger.
     */
    public function setTo(Store $store, StockItem $item, int $batchId, int $counted, Posting $why, ?int $costRate = null): ?StockMovement
    {
        return DB::transaction(function () use ($store, $item, $batchId, $counted, $why, $costRate) {
            $level = $this->lock($store, $item, $batchId);
            $delta = $counted - $level->qty;

            if ($delta === 0) {
                return null;
            }

            /*
             | Stock the books did not know about - opening stock, most often
             | - has to be given a cost, or it would be valued at nothing and
             | every dish cooked from it would be pure profit. The caller's
             | when it gave one, what the slot or the item last cost if not.
             */
            $rate = $costRate !== null && $costRate > 0 ? $costRate : $this->knownRate($level, $item);

            if ($delta > 0 && $costRate !== null && $costRate > 0 && $item->last_cost_rate === 0) {
                $item->forceFill(['last_cost_rate' => $costRate])->saveQuietly();
            }

            return $this->write($level, $item, $delta, $rate, $why);
        });
    }

    /* ------------------------------------------------------------- read */

    /**
     * How much of an item an outlet holds - in one store, or in all of them.
     *
     * With $usableOn, batches already past their date on that day are left
     * out: that is "what can still be cooked with", not "what is on the
     * shelf".
     */
    public function onHand(string $outletId, StockItem|int $item, ?Store $store = null, ?string $usableOn = null): int
    {
        $itemId = $item instanceof StockItem ? $item->id : $item;

        $query = StockLevel::allOutlets()
            ->where('stock_levels.outlet_id', $outletId)
            ->where('stock_levels.stock_item_id', $itemId)
            ->when($store, fn ($q) => $q->where('stock_levels.store_id', $store->id));

        if ($usableOn !== null) {
            $query->leftJoin('batches', 'batches.id', '=', 'stock_levels.batch_id')
                ->where(fn ($q) => $q->whereNull('batches.expiry_date')->orWhere('batches.expiry_date', '>=', $usableOn));
        }

        return (int) $query->sum('stock_levels.qty');
    }

    /**
     * What a base unit of an item costs at an outlet: the weighted average
     * of what is on hand, or what it last cost when there is none.
     */
    public function costRate(string $outletId, StockItem $item): int
    {
        $levels = StockLevel::allOutlets()
            ->where('outlet_id', $outletId)
            ->where('stock_item_id', $item->id)
            ->where('qty', '>', 0)
            ->get(['qty', 'cost_rate']);

        $qty = (int) $levels->sum('qty');

        if ($qty <= 0) {
            return $item->last_cost_rate;
        }

        $worth = $levels->sum(fn (StockLevel $level) => $level->qty * $level->cost_rate);

        return self::divide($worth, $qty);
    }

    /**
     * Every slot whose level does not equal the sum of its movements.
     *
     * There should never be one. The nightly check runs this to prove it.
     *
     * @return list<array{level_id: int, level: int, movements: int}>
     */
    public function reconcile(?string $outletId = null): array
    {
        $sums = StockMovement::allOutlets()
            ->when($outletId, fn ($q) => $q->where('outlet_id', $outletId))
            ->groupBy('store_id', 'stock_item_id', 'batch_id')
            ->selectRaw('store_id, stock_item_id, batch_id, SUM(qty) as total')
            ->get()
            ->keyBy(fn ($row) => $row->store_id.':'.$row->stock_item_id.':'.$row->batch_id);

        $wrong = [];

        StockLevel::allOutlets()
            ->when($outletId, fn ($q) => $q->where('outlet_id', $outletId))
            ->orderBy('id')
            ->each(function (StockLevel $level) use ($sums, &$wrong) {
                $total = (int) ($sums[$level->store_id.':'.$level->stock_item_id.':'.$level->batch_id]->total ?? 0);

                if ($total !== $level->qty) {
                    $wrong[] = ['level_id' => $level->id, 'level' => $level->qty, 'movements' => $total];
                }
            });

        return $wrong;
    }

    /* ---------------------------------------------------------- private */

    /**
     * The slots a quantity is taken from, in the order it is taken:
     * earliest expiry first, undated batches after the dated ones, stock
     * with no batch last. Only slots with something in them.
     *
     * Batches already past their date are skipped when the outlet says
     * expired stock is not to be cooked with - they are waiting to be
     * written off, not used.
     *
     * @return Collection<int, StockLevel>
     */
    private function pickOrder(Store $store, StockItem $item, ?string $businessDate): Collection
    {
        $levels = StockLevel::allOutlets()
            ->with('batch')
            ->where('store_id', $store->id)
            ->where('stock_item_id', $item->id)
            ->where('qty', '>', 0)
            ->get();

        if (Outlets::settings($store->outlet_id)->exclude_expired) {
            $day = $businessDate ?? Outlets::businessDate($store->outlet_id);
            $levels = $levels->reject(fn (StockLevel $level) => $level->batch?->isExpired($day) ?? false);
        }

        return $levels->sortBy(fn (StockLevel $level) => [
            $level->batch_id === 0 ? 2 : ($level->batch?->expiry_date === null ? 1 : 0),
            $level->batch?->expiry_date?->toDateString() ?? '',
            $level->batch_id,
        ])->values();
    }

    private function lock(Store $store, StockItem $item, int $batchId): StockLevel
    {
        $key = ['store_id' => $store->id, 'stock_item_id' => $item->id, 'batch_id' => $batchId];

        StockLevel::allOutlets()->firstOrCreate($key, [
            'tenant_id' => $store->tenant_id,
            'outlet_id' => $store->outlet_id,
            'qty' => 0,
            'cost_rate' => 0,
        ]);

        return StockLevel::allOutlets()->where($key)->lockForUpdate()->firstOrFail();
    }

    /** What a slot's stock costs, or what the item last cost when it is empty. */
    private function knownRate(StockLevel $level, StockItem $item): int
    {
        return $level->qty > 0 && $level->cost_rate > 0 ? $level->cost_rate : $item->last_cost_rate;
    }

    /** Change a locked slot and write the row that says why. */
    private function write(StockLevel $level, StockItem $item, int $qty, int $rate, Posting $why): StockMovement
    {
        $before = $level->qty;
        $after = $before + $qty;

        /*
         | Stock coming in is averaged with what is already there. Coming in
         | to an empty or overdrawn slot, there is nothing to average with:
         | the slot is simply worth what just arrived.
         */
        if ($qty > 0) {
            $level->cost_rate = $before > 0
                ? self::divide($before * $level->cost_rate + $qty * $rate, $after)
                : $rate;
        }

        $level->qty = $after;
        $level->save();

        $occurredAt = $why->occurredAt ?? now();

        $movement = StockMovement::allOutlets()->create([
            'tenant_id' => $level->tenant_id,
            'outlet_id' => $level->outlet_id,
            'store_id' => $level->store_id,
            'stock_item_id' => $level->stock_item_id,
            'batch_id' => $level->batch_id,
            'type' => $why->type,
            'qty' => $qty,
            'cost_rate' => $rate,
            'value_paise' => Units::value($qty, $rate),
            'balance_qty' => $after,
            'reference_type' => $why->reference?->getMorphClass(),
            'reference_id' => $why->reference?->getKey(),
            'event_id' => $why->eventId,
            'event_line' => $why->eventId === null ? null : ($why->eventLine ?? ''),
            'event_part' => $why->nextPart(),
            'reason' => $why->reason,
            'user_id' => $why->userId ?? Auth::id(),
            'business_date' => $why->businessDate ?? Outlets::businessDate($level->outlet_id, $occurredAt),
            'occurred_at' => $occurredAt,
        ]);

        event(new StockMoved($level->outlet_id, $level->stock_item_id));

        return $movement;
    }

    private static function divide(int $amount, int $by): int
    {
        return Decimal::mulDiv($amount, 1, $by);
    }
}
