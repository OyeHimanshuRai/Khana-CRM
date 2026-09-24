<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\RestaurantTable;
use App\Models\TableQr;
use App\Models\TableSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Opening, joining and closing a sitting at a table.
 *
 * One invariant, and everything here exists to hold it:
 *
 *     a table has at most one open session at any moment
 *
 * That is what makes §3.11 work - "additional orders from the same table
 * remain linked to the same bill". Three friends at one table scanning the
 * same sticker within the same second must land in one session, not three:
 * otherwise the table ends up with three bills and the waiter with an
 * argument.
 *
 * MySQL cannot express "unique where status = open" as an index, so the
 * guarantee is a row lock on the table inside a transaction. The lock is on
 * `restaurant_tables`, not on `table_sessions`, because the row being
 * protected is the one that does not exist yet.
 *
 * Sessions are never deleted. A closed sitting is the history behind a bill,
 * and §17 wants that auditable.
 */
class TableSessionService
{
    /**
     * Find the open session for a table, or start one.
     *
     * The whole of the scan flow, in one call. Everything about it is
     * deliberately idempotent: a guest who scans, backgrounds their phone and
     * scans again has not started a second party.
     */
    public function openFor(RestaurantTable $table, ?TableQr $qr = null): TableSession
    {
        return DB::transaction(function () use ($table, $qr) {
            /*
             | Lock the table, then look. Reading first and locking after
             | would let two concurrent scans both see "no open session" and
             | both insert one.
             */
            RestaurantTable::query()
                ->whereKey($table->id)
                ->lockForUpdate()
                ->first();

            $existing = TableSession::query()
                ->where('restaurant_table_id', $table->id)
                ->live()
                ->orderByDesc('id')
                ->first();

            if ($existing !== null) {
                $existing->forceFill(['last_activity_at' => now()])->save();

                return $existing;
            }

            $session = new TableSession([
                'shop_id' => $table->shop_id,
                'restaurant_table_id' => $table->id,
                'table_qr_id' => $qr?->id,
                'token' => $this->freshToken(),
                'status' => TableSession::OPEN,
                'opened_at' => now(),
                'last_activity_at' => now(),
            ]);

            $session->save();

            /*
             | The room now says what the system says. A guest who has scanned
             | is sitting there whether or not anybody pressed anything, and a
             | plan that still showed the table free - or reserved, or waiting
             | to be wiped - would be lying to the floor about a table that
             | now has an open session on it.
             |
             | `reserved` and `cleaning` are promoted too, and that is the
             | point rather than an oversight: the party with the booking has
             | arrived, or new guests have sat at a table just vacated. Both
             | are exactly what a scan means.
             |
             | `billing` is the one exception. That is a party mid-settlement,
             | and one of them re-scanning the sticker must not tell the floor
             | the bill has been cancelled.
             |
             | Only on a *new* session: a re-scan of an open one changes
             | nothing, so a status a member of staff set during the sitting
             | survives the guest reloading their phone.
             */
            if ($table->status !== RestaurantTable::BILLING) {
                $table->forceFill(['status' => RestaurantTable::OCCUPIED])->save();
            }

            ActivityLog::record(
                'table_session.opened',
                "Table {$table->code}: a party was seated",
                $session,
            );

            return $session;
        });
    }

    /**
     * The open session for a table, without starting one.
     *
     * For the POS and the floor plan, which need to know whether anybody is
     * sitting there and must not seat them by asking.
     */
    public function current(RestaurantTable $table): ?TableSession
    {
        return TableSession::query()
            ->where('restaurant_table_id', $table->id)
            ->openOrBilled()
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Mark a session as being settled.
     *
     * Stops further ordering against it - see TableSession::scopeLive. The
     * table goes to `billing` so the floor knows not to re-seat it while the
     * card machine is out.
     */
    public function bill(TableSession $session): TableSession
    {
        if (! $session->isOpen()) {
            return $session;
        }

        return DB::transaction(function () use ($session) {
            $session->forceFill([
                'status' => TableSession::BILLED,
                'last_activity_at' => now(),
            ])->save();

            $table = $session->table;

            if ($table && $table->status === RestaurantTable::OCCUPIED) {
                $table->forceFill(['status' => RestaurantTable::BILLING])->save();
            }

            return $session;
        });
    }

    /**
     * End a sitting and release the table.
     *
     * The table goes to `cleaning` rather than straight to `available`: a
     * party has just left it, and a plan that offered it to the next guests
     * before anybody wiped it down would be lying about the room.
     */
    public function close(TableSession $session, ?string $reason = null): TableSession
    {
        if ($session->isClosed()) {
            return $session;
        }

        return DB::transaction(function () use ($session, $reason) {
            $session->forceFill([
                'status' => TableSession::CLOSED,
                'closed_at' => now(),
                'closed_reason' => $reason,
            ])->save();

            $table = $session->table;

            if ($table && $table->isSeated()) {
                $table->forceFill(['status' => RestaurantTable::CLEANING])->save();
            }

            ActivityLog::record(
                'table_session.closed',
                sprintf(
                    'Table %s: the party left after %d minute%s',
                    $table?->code ?? '?',
                    $session->seatedMinutes(),
                    $session->seatedMinutes() === 1 ? '' : 's',
                ),
                $session,
            );

            return $session;
        });
    }

    /**
     * Resolve a guest's own session token.
     *
     * Returns null for anything that is not an open session, which covers a
     * token from a sitting that ended, a token for another branch, and a
     * token somebody made up. The caller sends them back to the QR rather
     * than telling them which of the three it was.
     */
    public function resolve(?string $token): ?TableSession
    {
        if (blank($token)) {
            return null;
        }

        return TableSession::allShops()
            ->where('token', $token)
            ->openOrBilled()
            ->with('table.floor')
            ->first();
    }

    private function freshToken(): string
    {
        do {
            $token = Str::random(40);
        } while (TableSession::allShops()->where('token', $token)->exists());

        return $token;
    }
}
