<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\RestaurantTable;
use App\Models\TableQr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Issuing, regenerating and revoking table QR codes.
 *
 * One invariant, and everything here exists to hold it:
 *
 *     a table has at most one live QR code at any moment
 *
 * MySQL cannot express "unique where revoked_at is null" as an index, so the
 * guarantee is a row lock and a transaction instead. Two managers pressing
 * Regenerate at the same second is unlikely and would be very hard to
 * diagnose, which is exactly the kind of bug worth spending a lock on.
 *
 * Revoking is never a delete. A printed sticker outlives the row it came
 * from, and a scan of a withdrawn code has to be able to say "this code is
 * no longer in use" rather than 404 - see TableSessionController.
 */
class TableQrService
{
    /**
     * Give a table a code, replacing whatever it had.
     *
     * Safe to call on a table that already has one: that is what Regenerate
     * does, and the old row is revoked in the same transaction so there is no
     * instant where both are live or neither is.
     */
    public function issue(RestaurantTable $table, ?string $reason = null): TableQr
    {
        return DB::transaction(function () use ($table, $reason) {
            /*
             | Locked for the duration. Without this, two concurrent issues
             | both read "no live row", both insert, and the table quietly
             | ends up with two codes that both work.
             */
            $live = TableQr::query()
                ->where('restaurant_table_id', $table->id)
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->get();

            foreach ($live as $existing) {
                $existing->forceFill([
                    'revoked_at' => now(),
                    'revoked_reason' => $reason ?: 'Replaced by a new code',
                ])->save();
            }

            $qr = new TableQr([
                'shop_id' => $table->shop_id,
                'restaurant_table_id' => $table->id,
                'token' => $this->freshToken(),
                'issued_at' => now(),
                'issued_by' => Auth::id(),
            ]);

            $qr->save();

            ActivityLog::record(
                $live->isEmpty() ? 'table_qr.issued' : 'table_qr.regenerated',
                sprintf(
                    '%s QR code for table %s',
                    $live->isEmpty() ? 'Issued a' : 'Regenerated the',
                    $table->code,
                ),
                $qr,
            );

            return $qr;
        });
    }

    /**
     * Withdraw a table's code without issuing another.
     *
     * The table then has no QR at all and scanning the old sticker says so.
     * Used when a table is taken out of service rather than re-stickered.
     */
    public function revoke(RestaurantTable $table, ?string $reason = null): int
    {
        return DB::transaction(function () use ($table, $reason) {
            $live = TableQr::query()
                ->where('restaurant_table_id', $table->id)
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->get();

            foreach ($live as $qr) {
                $qr->forceFill([
                    'revoked_at' => now(),
                    'revoked_reason' => $reason ?: 'Withdrawn',
                ])->save();
            }

            if ($live->isNotEmpty()) {
                ActivityLog::record(
                    'table_qr.revoked',
                    "Withdrew the QR code for table {$table->code}",
                    $table,
                );
            }

            return $live->count();
        });
    }

    /**
     * Issue codes for every active table that has none.
     *
     * The "generate QR for every table" button §3.2 implies. Tables that
     * already have a live code are left alone rather than re-issued - a bulk
     * action that invalidated every sticker in the restaurant would be a very
     * expensive misclick.
     *
     * @return Collection<int, TableQr>
     */
    public function issueMissing(): Collection
    {
        $tables = RestaurantTable::query()
            ->active()
            ->whereDoesntHave('qrs', fn ($q) => $q->whereNull('revoked_at'))
            ->get();

        return $tables->map(fn (RestaurantTable $table) => $this->issue($table));
    }

    /**
     * Note that a code was scanned.
     *
     * Deliberately outside any transaction and never allowed to fail the
     * request: a counter that did not increment is not a reason to refuse a
     * customer the menu.
     */
    public function recordScan(TableQr $qr): void
    {
        try {
            $qr->forceFill([
                'scan_count' => (int) $qr->scan_count + 1,
                'last_scanned_at' => now(),
            ])->saveQuietly();
        } catch (\Throwable) {
            // Counting is a convenience. Serving the menu is not.
        }
    }

    /**
     * A token nothing else holds.
     *
     * 32 characters from Str::random, which is a CSPRNG. The uniqueness
     * check is belt and braces - at 62^32 the loop is there to make the
     * guarantee explicit rather than because it will ever run twice.
     */
    private function freshToken(): string
    {
        do {
            $token = Str::random(32);
        } while (TableQr::allShops()->where('token', $token)->exists());

        return $token;
    }
}
