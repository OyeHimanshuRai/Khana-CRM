<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\RestaurantTable;
use App\Models\TableSession;
use App\Support\CurrentShop;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Taking and keeping table bookings (SRS 5, 7, 21).
 *
 * ---------------------------------------------------------------------------
 * The only mistake that cannot be smoothed over
 * ---------------------------------------------------------------------------
 *
 * Almost everything a booking system gets wrong can be fixed at the door
 * with an apology and a drink. Double-booking a table cannot: two parties
 * arrive, both were promised, and one of them is going home. So the overlap
 * check is the part of this class that is written most carefully, runs
 * inside a lock, and refuses rather than warns.
 *
 * ---------------------------------------------------------------------------
 * A booking is a promise, not a table
 * ---------------------------------------------------------------------------
 *
 * Most bookings are taken without deciding which table anybody will sit at -
 * that is settled on the night by looking at the room. A reservation with no
 * table is therefore complete and valid, clashes with nobody, and is
 * assigned a table whenever the restaurant is ready to.
 */
class ReservationService
{
    /** How long after the booked time a party is still expected. */
    public const GRACE_MINUTES = 20;

    /**
     * How recent an empty sitting has to be for a booking to take it over.
     *
     * The case this exists for is minutes old: the party scanned the QR on
     * the way in, or a waiter opened the table as they walked to it. Two
     * hours is generous for that and still short enough that a sitting
     * nobody ever closed is treated as what it is - see guardHandover().
     */
    public const HANDOVER_MINUTES = 120;

    /**
     * Take a booking.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws RuntimeException when the table is already promised
     */
    public function create(array $data): Reservation
    {
        return DB::transaction(function () use ($data) {
            $tableId = $data['restaurant_table_id'] ?? null;
            $at = Carbon::parse($data['reserved_for']);
            $minutes = (int) ($data['duration_minutes'] ?? 90);

            if ($tableId !== null) {
                $this->guardTable((int) $tableId, $at, $minutes);
                $this->guardSitting((int) $tableId, $at);
            }

            return Reservation::create([
                'shop_id' => $data['shop_id'] ?? CurrentShop::idForWrite(),
                'restaurant_table_id' => $tableId,
                'customer_id' => $data['customer_id'] ?? null,
                'guest_name' => $data['guest_name'],
                'guest_mobile' => $data['guest_mobile'] ?? null,
                'guest_email' => $data['guest_email'] ?? null,
                'party_size' => (int) ($data['party_size'] ?? 2),
                'reserved_for' => $at,
                'duration_minutes' => $minutes,
                'status' => $data['status'] ?? Reservation::CONFIRMED,
                'source' => $data['source'] ?? 'phone',
                'notes' => $data['notes'] ?? null,
                'created_by' => Auth::id(),
            ]);
        });
    }

    /**
     * Change a booking - time, size, table or notes.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws RuntimeException
     */
    public function update(Reservation $reservation, array $data): Reservation
    {
        if ($reservation->status === Reservation::SEATED) {
            throw new RuntimeException(
                'They are already sitting down. Move the table from the floor plan instead.'
            );
        }

        return DB::transaction(function () use ($reservation, $data) {
            $tableId = array_key_exists('restaurant_table_id', $data)
                ? $data['restaurant_table_id']
                : $reservation->restaurant_table_id;

            $at = isset($data['reserved_for'])
                ? Carbon::parse($data['reserved_for'])
                : $reservation->reserved_for;

            $minutes = (int) ($data['duration_minutes'] ?? $reservation->duration_minutes);

            if ($tableId !== null) {
                $this->guardTable((int) $tableId, $at, $minutes, $reservation->id);
                $this->guardSitting((int) $tableId, $at);
            }

            $reservation->fill(array_merge($data, [
                'restaurant_table_id' => $tableId,
                'reserved_for' => $at,
                'duration_minutes' => $minutes,
            ]))->save();

            return $reservation->fresh();
        });
    }

    /**
     * Accept a request that was only asked for.
     */
    public function confirm(Reservation $reservation): Reservation
    {
        if ($reservation->isClosed()) {
            throw new RuntimeException('That booking is already closed.');
        }

        $reservation->forceFill([
            'status' => Reservation::CONFIRMED,
            'confirmed_at' => now(),
        ])->save();

        return $reservation;
    }

    /**
     * The party has arrived: open a sitting and put them at a table.
     *
     * This is the moment a promise becomes a bill. Everything downstream -
     * the cart, the kitchen, the invoice - hangs off the TableSession this
     * creates, so a booking that was seated is linked to its sitting and the
     * two can never be told apart afterwards.
     *
     * @throws RuntimeException
     */
    public function seat(Reservation $reservation, ?RestaurantTable $table = null): Reservation
    {
        if ($reservation->status === Reservation::SEATED) {
            throw new RuntimeException($reservation->guest_name.' is already seated.');
        }

        if ($reservation->isClosed()) {
            throw new RuntimeException('That booking is closed and cannot be seated.');
        }

        $table ??= $reservation->table;

        if ($table === null) {
            throw new RuntimeException('Choose a table for this booking first.');
        }

        return DB::transaction(function () use ($reservation, $table) {
            $sessions = app(TableSessionService::class);

            /*
             | An existing sitting is reused, but only when it is plausibly
             | the same party.
             |
             | Reuse is right for the ordinary case: the guests scanned the QR
             | on the way in, or a waiter opened the table thirty seconds ago,
             | and opening a second sitting would split one meal across two
             | bills - the exact thing §3.11 says must not happen.
             |
             | Reuse is catastrophic for the other case, which is what this
             | guard is for. A table somebody else is still eating at has an
             | open, unpaid bill on it; attaching a new booking to that bill
             | renames somebody else's tab and bills the wrong customer for
             | food they did not order. It is silent, it is unrecoverable
             | without unpicking the ledger, and the host who caused it was
             | told the seating had worked.
             |
             | So an occupied table refuses, by name and by amount, and says
             | what to do about it. Only an idle sitting - one nobody has
             | ordered against - is taken over.
             */
            $existing = $sessions->current($table);

            if ($existing !== null) {
                $this->guardHandover($existing, $reservation, $table);
            }

            $session = $existing ?? $sessions->openFor($table);

            $session->forceFill([
                'guest_name' => $session->guest_name ?: $reservation->guest_name,
                'guest_mobile' => $session->guest_mobile ?: $reservation->guest_mobile,
                'covers' => $session->covers ?: $reservation->party_size,
            ])->save();

            $reservation->forceFill([
                'restaurant_table_id' => $table->id,
                'table_session_id' => $session->id,
                'status' => Reservation::SEATED,
                'seated_at' => now(),
            ])->save();

            return $reservation->fresh(['table', 'session']);
        });
    }

    /** The meal is over. */
    public function complete(Reservation $reservation): Reservation
    {
        $reservation->forceFill([
            'status' => Reservation::COMPLETED,
            'closed_at' => now(),
        ])->save();

        return $reservation;
    }

    /**
     * Nobody came.
     *
     * Only ever recorded by a person. The clock can say a booking is
     * overdue; it cannot say a party did not turn up, and a system that
     * decided that on its own would mark as a no-show every table whose host
     * was too busy to tap a button.
     */
    public function noShow(Reservation $reservation, ?string $note = null): Reservation
    {
        if ($reservation->status === Reservation::SEATED) {
            throw new RuntimeException('They are sitting at a table — that is not a no-show.');
        }

        $reservation->forceFill([
            'status' => Reservation::NO_SHOW,
            'outcome_note' => $note,
            'closed_at' => now(),
        ])->save();

        return $reservation;
    }

    public function cancel(Reservation $reservation, ?string $note = null): Reservation
    {
        if ($reservation->status === Reservation::SEATED) {
            throw new RuntimeException('They are already seated. Close the booking instead.');
        }

        $reservation->forceFill([
            'status' => Reservation::CANCELLED,
            'outcome_note' => $note,
            'closed_at' => now(),
        ])->save();

        return $reservation;
    }

    /* ----------------------------------------------------- the clash check */

    /**
     * Bookings that overlap the given window on the given table.
     *
     * The comparison people get wrong is this one, so it is written the
     * unambiguous way: two windows overlap when each starts before the other
     * ends. Touching windows - one ending exactly as the next begins - do
     * NOT overlap, which is what makes back-to-back sittings possible at all.
     *
     * @return Collection<int, Reservation>
     */
    public function clashesFor(
        int $tableId,
        Carbon $at,
        int $minutes,
        ?int $ignoreId = null,
    ): Collection {
        $ends = $at->copy()->addMinutes($minutes);

        return Reservation::query()
            ->where('restaurant_table_id', $tableId)
            ->live()
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            // Starts before our window ends...
            ->where('reserved_for', '<', $ends)
            /*
             | ...and ends after ours starts. Expressed with the duration
             | added in SQL would need a dialect-specific interval, and the
             | candidate set here is tiny - one table, one evening - so the
             | window is narrowed by date in SQL and judged in PHP.
             */
            ->where('reserved_for', '>', $at->copy()->subDay())
            ->get()
            ->filter(fn (Reservation $other) => $other->endsAt()->gt($at))
            ->values();
    }

    /**
     * Is anybody sitting at this table right now?
     *
     * Asked of the sitting rather than of `restaurant_tables.status`, because
     * the status column is a flag somebody sets and the sitting is the thing
     * that owns a bill. When the two disagree it is the bill that decides
     * whether a table is free.
     */
    public function currentSitting(int $tableId): ?TableSession
    {
        return TableSession::query()
            ->where('restaurant_table_id', $tableId)
            ->openOrBilled()
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Refuse to promise a table somebody is sitting at, for a booking that
     * starts now.
     *
     * Only for a booking that starts now-ish, deliberately. A table occupied
     * at six tells you nothing about nine o'clock, and refusing the evening's
     * bookings because the lunch party has not left would make the book
     * useless. What it cannot be is a booking the host is about to walk
     * somebody to.
     *
     * @throws RuntimeException
     */
    private function guardSitting(int $tableId, Carbon $at): void
    {
        if ($at->greaterThan(now()->addMinutes(self::GRACE_MINUTES))) {
            return;
        }

        $sitting = $this->currentSitting($tableId);

        if ($sitting === null) {
            return;
        }

        throw new RuntimeException(sprintf(
            '%s is sitting at that table now (%s). Pick another table, or book a later time.',
            $sitting->partyName(),
            $this->sittingLabel($sitting),
        ));
    }

    /**
     * Whether this booking may take over a sitting that is already open.
     *
     * Allowed when it is this booking's own sitting, and when the sitting is
     * idle - opened but never ordered against, which is what a guest who
     * scanned the QR at the door looks like. Refused the moment there is a
     * bill on it, because that bill belongs to whoever is eating.
     *
     * @throws RuntimeException
     */
    private function guardHandover(TableSession $session, Reservation $reservation, RestaurantTable $table): void
    {
        // Their own sitting - they scanned, or a waiter opened it for them.
        if ($reservation->table_session_id === $session->id) {
            return;
        }

        $busy = $session->orders()->exists()
            || $session->cartItems()->exists()
            || $session->invoices()->exists();

        // Nothing ordered and only just opened: this is the party arriving.
        if (! $busy && $session->isOpen() && $session->seatedMinutes() <= self::HANDOVER_MINUTES) {
            return;
        }

        if ($busy) {
            throw new RuntimeException(sprintf(
                'Table %s is not free: %s has been sitting there %s and the bill is still open (%s). '
                .'Settle and close that sitting first, or seat %s at another table.',
                $table->name,
                $session->partyName(),
                $this->minutesLabel($session->seatedMinutes()),
                $this->sittingLabel($session),
                $reservation->guest_name,
            ));
        }

        /*
         | Open for hours with nothing on it.
         |
         | Harmless to absorb - there is no bill to merge into - but the floor
         | plan has been showing that table as occupied the whole time, so
         | somebody may well be sitting at it. Quietly renaming a sitting that
         | old would hide the one fact the host needs before they walk guests
         | across the room.
         */
        throw new RuntimeException(sprintf(
            'Table %s has a sitting open since %s with nothing ordered on it. Clear it from Table Bills '
            .'(and check nobody is at the table) before seating %s there.',
            $table->name,
            $session->opened_at?->format('j M, g:i a') ?? 'earlier',
            $reservation->guest_name,
        ));
    }

    /** "45 minutes", "2 days" - whichever reads as the truth. */
    private function minutesLabel(int $minutes): string
    {
        if ($minutes < 90) {
            return $minutes.' minute'.($minutes === 1 ? '' : 's');
        }

        $hours = (int) round($minutes / 60);

        if ($hours < 48) {
            return $hours.' hour'.($hours === 1 ? '' : 's');
        }

        $days = (int) round($hours / 24);

        return $days.' day'.($days === 1 ? '' : 's');
    }

    /** How a sitting reads in a refusal: the party, and what they owe. */
    private function sittingLabel(TableSession $session): string
    {
        $orders = $session->orders()->count();

        return $orders > 0
            ? $orders.' order'.($orders === 1 ? '' : 's').' on it'
            : 'opened '.$this->minutesLabel($session->seatedMinutes()).' ago';
    }

    /** @throws RuntimeException */
    private function guardTable(int $tableId, Carbon $at, int $minutes, ?int $ignoreId = null): void
    {
        $clashes = $this->clashesFor($tableId, $at, $minutes, $ignoreId);

        if ($clashes->isEmpty()) {
            return;
        }

        $other = $clashes->first();

        throw new RuntimeException(sprintf(
            'That table is already promised to %s from %s. Pick another table or another time.',
            $other->guest_name,
            $other->windowLabel(),
        ));
    }

    /* ------------------------------------------------------------ reading */

    /**
     * Which tables are free for a party at a time.
     *
     * @return Collection<int, RestaurantTable>
     */
    public function availableTables(Carbon $at, int $minutes, int $partySize = 1, ?int $ignoreId = null): Collection
    {
        $tables = RestaurantTable::query()
            ->where('is_active', true)
            ->when($partySize > 1, fn ($q) => $q->where('capacity', '>=', $partySize))
            ->orderBy('name')
            ->get();

        /*
         | A table with people at it is not free, whatever the book says.
         |
         | Only for a window that starts now-ish: this list is read at the
         | door, and offering a host a table that somebody is currently eating
         | at is how two parties end up on one bill. Later in the evening it
         | is a perfectly good table again - see guardSitting().
         */
        $occupied = $at->lessThanOrEqualTo(now()->addMinutes(self::GRACE_MINUTES))
            ? $this->occupiedTableIds()
            : [];

        return $tables
            ->reject(fn (RestaurantTable $table) => in_array($table->id, $occupied, true))
            ->filter(
                fn (RestaurantTable $table) => $this->clashesFor($table->id, $at, $minutes, $ignoreId)->isEmpty()
            )->values();
    }

    /**
     * Every table with somebody sitting at it right now.
     *
     * One query for a whole floor plan or a whole picker, rather than one per
     * table - the booking form lists every table in the building.
     *
     * @return array<int, int>
     */
    public function occupiedTableIds(): array
    {
        return TableSession::query()
            ->openOrBilled()
            ->pluck('restaurant_table_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * What the floor should say about a table right now.
     *
     * Reservations do not write to `restaurant_tables.status`, and that is
     * deliberate: a table is "reserved" only for a window, and a stored flag
     * would need something to run to clear it. Asking the question when the
     * floor plan is drawn is both cheaper and always right.
     */
    public function nextBookingFor(RestaurantTable $table, int $withinMinutes = 120): ?Reservation
    {
        return Reservation::query()
            ->where('restaurant_table_id', $table->id)
            ->whereIn('status', [Reservation::REQUESTED, Reservation::CONFIRMED])
            ->whereBetween('reserved_for', [now()->subMinutes(self::GRACE_MINUTES), now()->addMinutes($withinMinutes)])
            ->orderBy('reserved_for')
            ->first();
    }

    /**
     * Counts for the day's header.
     *
     * @return array<string, int>
     */
    public function summaryFor(Carbon $day): array
    {
        $rows = Reservation::query()->forDay($day)->get();

        return [
            'total' => $rows->count(),
            'covers' => (int) $rows->whereIn('status', Reservation::HOLDS_A_TABLE)->sum('party_size'),
            'requested' => $rows->where('status', Reservation::REQUESTED)->count(),
            'confirmed' => $rows->where('status', Reservation::CONFIRMED)->count(),
            'seated' => $rows->where('status', Reservation::SEATED)->count(),
            'no_show' => $rows->where('status', Reservation::NO_SHOW)->count(),
            'overdue' => $rows->filter(fn (Reservation $r) => $r->isOverdue(self::GRACE_MINUTES))->count(),
        ];
    }
}
