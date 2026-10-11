<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * The next number for a document at an outlet: GRN-0001, PO-0001.
 *
 * One counter row per outlet and kind, read and raised under a lock, so two
 * people receiving at the same moment get two different numbers. Call it
 * inside the transaction that writes the document - a rolled-back document
 * then gives its number back.
 */
final class Numbering
{
    public static function next(string $outletId, string $prefix): string
    {
        return DB::transaction(function () use ($outletId, $prefix) {
            $key = ['outlet_id' => $outletId, 'key' => $prefix];

            DB::table('counters')->insertOrIgnore($key + ['value' => 0]);

            $value = (int) DB::table('counters')->where($key)->lockForUpdate()->value('value') + 1;

            DB::table('counters')->where($key)->update(['value' => $value]);

            return sprintf('%s-%04d', $prefix, $value);
        });
    }
}
