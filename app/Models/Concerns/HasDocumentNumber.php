<?php

namespace App\Models\Concerns;

use App\Models\Shop;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Shop-local document numbering: GRN/MAIN/2026/00001.
 *
 * Invoices and payments already mint numbers this way; the other document
 * series - goods receipts, returns, transfers and adjustments - need the same
 * thing, and writing the same twelve lines once per series is how two of them
 * end up subtly different.
 *
 * The shape follows the existing series exactly, because a shop that reads
 * INV/MAIN/2026/00042 off one document should not have to learn a second
 * convention for the next:
 *
 *     PREFIX / shop code / year / five digits
 *
 * The series restarts each calendar year, which is what the prefix lookup below
 * gives for free - a new year has no rows matching its prefix, so the count
 * begins again at one.
 *
 * ---------------------------------------------------------------------------
 * Concurrency
 * ---------------------------------------------------------------------------
 *
 * "Highest number so far, plus one" is a read-then-write, and two counters
 * doing it in the same instant would both read the same highest. So the whole
 * thing runs inside a transaction that first takes a row lock on the shop -
 * exactly what Shop::nextNumber() does, and reusing that row rather than
 * inventing a counter table means all of a branch's series serialise against
 * one another and none of them can interleave.
 *
 * The unique index on (shop_id, number) is still the backstop. A lock is a
 * promise about this application; the index is a promise about the data.
 */
trait HasDocumentNumber
{
    /**
     * The next free number in this model's series for the given shop.
     *
     * The series prefix comes from the model's own NUMBER_PREFIX constant, so
     * a new document type declares three characters and gets numbering.
     */
    public static function nextNumber(Shop $shop): string
    {
        $prefix = sprintf(
            '%s/%s/%s/',
            static::numberPrefix(),
            $shop->code,
            now()->format('Y'),
        );

        return DB::transaction(function () use ($shop, $prefix) {
            /*
             | Lock the branch, not this table. Nothing here reads the shop's
             | columns - the lock exists purely to serialise number minting, and
             | one well-known row is a simpler thing to reason about than a gap
             | lock over whatever range the LIKE below happens to touch.
             */
            Shop::query()->lockForUpdate()->find($shop->id);

            $last = static::allShops()
                ->where('shop_id', $shop->id)
                ->where('number', 'like', $prefix.'%')
                ->orderByDesc('id')
                ->value('number');

            $sequence = $last ? ((int) Str::afterLast($last, '/')) + 1 : 1;

            return $prefix.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);
        });
    }

    /**
     * Three or four characters identifying the series.
     *
     * Overridable, but every model that uses this trait declares
     * NUMBER_PREFIX instead - the constant is visible at the top of the class
     * where somebody looking for "what do our job cards look like" will find
     * it, and a method would bury it among the relations.
     */
    protected static function numberPrefix(): string
    {
        return defined(static::class.'::NUMBER_PREFIX')
            ? constant(static::class.'::NUMBER_PREFIX')
            : Str::upper(Str::substr(class_basename(static::class), 0, 3));
    }
}
