<?php

namespace App\Services;

use App\Models\StockDocument;
use App\Models\StockItem;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Store;
use App\Stock\Posting;
use App\Support\Numbering;
use App\Support\Units;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Khana\Kit\Support\Decimal;
use RuntimeException;

/**
 * A storekeeper's day, after the delivery has been taken in: issue to the
 * kitchen, log what was wasted, make a batch of gravy, count the shelf.
 *
 * Each writes one document (StockDocument) and the ledger rows it causes,
 * together or not at all. The document is what a person reads back and
 * prints; the ledger is what the numbers are made of.
 */
class StoreRoom
{
    public function __construct(
        private readonly StockService $stock,
        private readonly Recipes $recipes,
    ) {}

    /**
     * Move stock from one store to another - the main store to the kitchen,
     * most days.
     *
     * @param  list<array{item: StockItem, qty: int}>  $lines
     */
    public function issue(string $outletId, Store $from, Store $to, string $onDate, array $lines, ?string $note = null): StockDocument
    {
        return DB::transaction(function () use ($outletId, $from, $to, $onDate, $lines, $note) {
            $document = $this->document($outletId, StockDocument::ISSUE, $from, $onDate, $note, $to);
            $why = new Posting(StockMovement::TRANSFER_OUT, $document, businessDate: $onDate);
            $worth = 0;

            foreach ($lines as $line) {
                $out = $this->stock->move($from, $to, $line['item'], $line['qty'], $why);
                $value = abs((int) $out->sum('value_paise'));

                $document->lines()->create([
                    'stock_item_id' => $line['item']->id,
                    'qty' => $line['qty'],
                    'value_paise' => $value,
                ]);

                $worth += $value;
            }

            $document->update(['value_paise' => $worth]);

            return $document;
        });
    }

    /**
     * Write stock off. Every line says why: an unexplained loss is
     * indistinguishable from theft.
     *
     * @param  list<array{item: StockItem, qty: int, batch_id: int, note: ?string}>  $lines
     */
    public function waste(string $outletId, Store $store, string $onDate, string $reason, array $lines): StockDocument
    {
        if (trim($reason) === '') {
            throw new RuntimeException('Say why the stock was wasted.');
        }

        return DB::transaction(function () use ($outletId, $store, $onDate, $reason, $lines) {
            $document = $this->document($outletId, StockDocument::WASTAGE, $store, $onDate, $reason);
            $worth = 0;

            foreach ($lines as $line) {
                $why = new Posting(StockMovement::WASTAGE, $document, trim($reason.($line['note'] ? ' - '.$line['note'] : '')), businessDate: $onDate);

                // A named batch is written off as that batch; otherwise the
                // oldest goes first, as it would have in the kitchen.
                $out = ($line['batch_id'] ?? 0) > 0
                    ? collect([$this->stock->takeFrom($store, $line['item'], $line['batch_id'], $line['qty'], $why)])
                    : $this->stock->take($store, $line['item'], $line['qty'], $why);

                $value = abs((int) $out->sum('value_paise'));

                $document->lines()->create([
                    'stock_item_id' => $line['item']->id,
                    'batch_id' => $line['batch_id'] ?? 0,
                    'qty' => $line['qty'],
                    'value_paise' => $value,
                    'note' => $line['note'] ?? null,
                ]);

                $worth += $value;
            }

            $document->update(['value_paise' => $worth]);

            return $document;
        });
    }

    /**
     * Make a prepared item - a gravy base - from its recipe.
     *
     * The ingredients leave the store in proportion to how much is being
     * made, and what was made comes in worth exactly what went into it.
     */
    public function produce(string $outletId, Store $store, StockItem $prepared, int $qty, string $onDate, ?string $note = null): StockDocument
    {
        $recipe = $this->recipes->forPrepared($outletId, $prepared->id);

        if ($recipe === null) {
            throw new RuntimeException($prepared->name.' has no recipe yet. Write one before making a batch.');
        }

        if ($qty <= 0) {
            throw new RuntimeException('Say how much was made.');
        }

        return DB::transaction(function () use ($outletId, $store, $prepared, $qty, $onDate, $note, $recipe) {
            $document = $this->document($outletId, StockDocument::PRODUCTION, $store, $onDate, $note);
            $why = new Posting(StockMovement::PRODUCTION_OUT, $document, 'Making '.$prepared->name, businessDate: $onDate);
            $spent = 0;

            foreach ($recipe->lines as $line) {
                $used = Decimal::mulDiv($line->qty, $qty, $recipe->yield_qty);

                if ($used <= 0) {
                    continue;
                }

                $out = $this->stock->take($store, $line->item, $used, $why);
                $value = abs((int) $out->sum('value_paise'));

                $document->lines()->create([
                    'stock_item_id' => $line->stock_item_id,
                    'qty' => -$used,
                    'value_paise' => $value,
                ]);

                $spent += $value;
            }

            $this->stock->receive($store, $prepared, $qty, Units::rate($spent, $qty), $why->as(StockMovement::PRODUCTION_IN, 'Made in-house'));

            $document->lines()->create(['stock_item_id' => $prepared->id, 'qty' => $qty, 'value_paise' => $spent]);
            $document->update(['value_paise' => $spent]);

            return $document;
        });
    }

    /**
     * Post a stock count: each counted slot is set to what was found, and
     * only the difference is booked.
     *
     * A row gives the item, the batch (0 for none) and what was counted.
     * Slots that were not counted are left alone - counting the cold room
     * says nothing about the dry store. `cost_rate` is for stock the books
     * have never seen, an opening count most of all.
     *
     * @param  list<array{item: StockItem, batch_id: int, qty: int, cost_rate?: ?int}>  $rows
     */
    public function count(string $outletId, Store $store, string $onDate, array $rows, ?string $note = null, bool $opening = false): StockDocument
    {
        return DB::transaction(function () use ($outletId, $store, $onDate, $rows, $note, $opening) {
            $document = $this->document($outletId, StockDocument::COUNT, $store, $onDate, $note);
            $why = new Posting(
                $opening ? StockMovement::OPENING : StockMovement::ADJUSTMENT,
                $document,
                $opening ? 'Opening stock' : 'Stock count '.$document->number,
                businessDate: $onDate,
            );
            $difference = 0;

            foreach ($rows as $row) {
                $batchId = $row['batch_id'] ?? 0;

                $expected = (int) StockLevel::allOutlets()
                    ->where('store_id', $store->id)
                    ->where('stock_item_id', $row['item']->id)
                    ->where('batch_id', $batchId)
                    ->value('qty');

                $movement = $this->stock->setTo($store, $row['item'], $batchId, $row['qty'], $why, $row['cost_rate'] ?? null);

                $document->lines()->create([
                    'stock_item_id' => $row['item']->id,
                    'batch_id' => $batchId,
                    'qty' => $movement?->qty ?? 0,
                    'expected_qty' => $expected,
                    'counted_qty' => $row['qty'],
                    'value_paise' => $movement?->value_paise ?? 0,
                ]);

                $difference += $movement?->value_paise ?? 0;
            }

            $document->update(['value_paise' => $difference]);

            return $document;
        });
    }

    private function document(string $outletId, string $kind, Store $store, string $onDate, ?string $note, ?Store $to = null): StockDocument
    {
        if ($store->outlet_id !== $outletId || ($to !== null && $to->outlet_id !== $outletId)) {
            throw new RuntimeException('That store belongs to another outlet.');
        }

        return StockDocument::allOutlets()->create([
            'outlet_id' => $outletId,
            'kind' => $kind,
            'number' => Numbering::next($outletId, StockDocument::PREFIXES[$kind]),
            'on_date' => $onDate,
            'store_id' => $store->id,
            'to_store_id' => $to?->id,
            'note' => $note,
            'user_id' => Auth::id(),
        ]);
    }
}
