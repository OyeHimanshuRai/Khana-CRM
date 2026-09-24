<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Reservation;
use App\Models\RestaurantTable;
use App\Services\ReservationService;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/**
 * The evening's book (SRS 7, 21).
 *
 * ---------------------------------------------------------------------------
 * A day at a time
 * ---------------------------------------------------------------------------
 *
 * The list is a single day, not a paginated history, because that is the
 * question a host actually asks: "what is booked tonight". A booking from
 * March is a reporting matter, and this is an operational screen - it is
 * read standing up, at the door, while somebody waits.
 *
 * Which is also why the default sort is the booked time rather than when it
 * was taken, and why overdue bookings are counted separately: the one thing
 * the screen exists to prevent is a party standing in the doorway while
 * nobody can find their name.
 */
class ReservationController extends Controller
{
    public function __construct(private readonly ReservationService $reservations) {}

    /* -------------------------------------------------------------- list */

    public function index(Request $request): View
    {
        $day = $this->day($request);

        $rows = $this->filtered($request, $day)
            ->with(['table:id,name,code', 'session:id,status'])
            ->orderBy('reserved_for')
            ->get();

        $data = [
            'reservations' => $rows,
            'day' => $day,
            'search' => $request->string('q')->toString(),
            'status' => $request->string('status')->toString(),
            'statuses' => Reservation::STATUSES,
            'stats' => $this->reservations->summaryFor($day),
            'grace' => ReservationService::GRACE_MINUTES,
        ];

        return $request->header('X-Fragment')
            ? view('admin.reservations._list', $data)
            : view('admin.reservations.index', $data);
    }

    private function filtered(Request $request, Carbon $day): Builder
    {
        return Reservation::query()
            ->forDay($day)
            ->when($request->string('q')->toString(), function (Builder $query, string $term) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

                $query->where(fn (Builder $q) => $q
                    ->where('guest_name', 'like', $like)
                    ->orWhere('guest_mobile', 'like', $like)
                    ->orWhere('notes', 'like', $like));
            })
            ->when($request->string('status')->toString(), function (Builder $query, string $status) {
                $query->where('status', $status);
            });
    }

    /**
     * Which day is on screen.
     *
     * Today unless asked otherwise, and a nonsense date is today rather than
     * an error - a malformed query string should not be able to break the
     * screen somebody is standing at.
     */
    private function day(Request $request): Carbon
    {
        $raw = $request->string('day')->toString();

        if ($raw === '') {
            return Carbon::today();
        }

        try {
            return Carbon::parse($raw)->startOfDay();
        } catch (\Throwable $e) {
            return Carbon::today();
        }
    }

    /* ------------------------------------------------------ modal screens */

    public function create(Request $request): View
    {
        $at = $this->day($request)->copy()->setTime(19, 0);

        return view('admin.reservations._form', [
            'reservation' => new Reservation([
                'reserved_for' => $at,
                'duration_minutes' => 90,
                'party_size' => 2,
                'status' => Reservation::CONFIRMED,
                'source' => 'phone',
            ]),
            'tables' => $this->tables(),
        ]);
    }

    public function edit(Reservation $reservation): View
    {
        return view('admin.reservations._form', [
            'reservation' => $reservation,
            'tables' => $this->tables(),
        ]);
    }

    public function show(Reservation $reservation): View
    {
        return view('admin.reservations._show', [
            'reservation' => $reservation->load(['table', 'session', 'creator:id,name']),
            // Offered at the door: a party that booked without a table, or
            // whose table is now wanted, is seated wherever there is room.
            'free' => $this->reservations->availableTables(
                $reservation->reserved_for,
                $reservation->duration_minutes,
                $reservation->party_size,
                $reservation->id,
            ),
            'grace' => ReservationService::GRACE_MINUTES,
            /*
             | Whether the table this party was promised has somebody else at
             | it. Seating onto it is refused by the service either way; the
             | screen says so first, because a host reads this standing at the
             | door with the guests in front of them.
             */
            'bookedSitting' => $reservation->restaurant_table_id
                ? $this->reservations->currentSitting($reservation->restaurant_table_id)
                : null,
        ]);
    }

    /* ------------------------------------------------------------ writes */

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        try {
            $reservation = $this->reservations->create($data);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage());
        }

        ActivityLog::record(
            'reservation.created',
            sprintf('Booked %s for %d at %s', $reservation->guest_name, $reservation->party_size, $reservation->reserved_for->format('j M, g:i a')),
            $reservation,
        );

        return ApiResponse::success(sprintf(
            '%s is booked for %s.',
            $reservation->guest_name,
            $reservation->reserved_for->format('j M, g:i a'),
        ));
    }

    public function update(Request $request, Reservation $reservation): JsonResponse
    {
        $data = $this->validated($request, $reservation);

        try {
            $this->reservations->update($reservation, $data);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage());
        }

        ActivityLog::record('reservation.updated', "Updated the booking for {$reservation->guest_name}", $reservation);

        return ApiResponse::success('Booking updated.');
    }

    /** Accept a request that was only asked for. */
    public function confirm(Reservation $reservation): JsonResponse
    {
        try {
            $this->reservations->confirm($reservation);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage());
        }

        return ApiResponse::success("{$reservation->guest_name} is confirmed.");
    }

    /** They have arrived. */
    public function seat(Request $request, Reservation $reservation): JsonResponse
    {
        $data = $request->validate([
            'restaurant_table_id' => ['nullable', 'integer', 'exists:restaurant_tables,id'],
        ]);

        $table = isset($data['restaurant_table_id'])
            ? RestaurantTable::find($data['restaurant_table_id'])
            : null;

        try {
            $seated = $this->reservations->seat($reservation, $table);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage());
        }

        ActivityLog::record(
            'reservation.seated',
            sprintf('Seated %s at table %s', $seated->guest_name, $seated->table?->name ?? '?'),
            $seated,
        );

        return ApiResponse::success(sprintf(
            '%s is seated at table %s. Their sitting is open.',
            $seated->guest_name,
            $seated->table?->name ?? '?',
        ));
    }

    public function complete(Reservation $reservation): JsonResponse
    {
        $this->reservations->complete($reservation);

        return ApiResponse::success("{$reservation->guest_name}'s booking is closed.");
    }

    public function noShow(Request $request, Reservation $reservation): JsonResponse
    {
        $data = $request->validate([
            'outcome_note' => ['nullable', 'string', 'max:190'],
        ]);

        try {
            $this->reservations->noShow($reservation, $data['outcome_note'] ?? null);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage());
        }

        ActivityLog::record('reservation.no_show', "{$reservation->guest_name} did not arrive", $reservation);

        return ApiResponse::success("Recorded as a no-show. The table is free again.");
    }

    public function cancel(Request $request, Reservation $reservation): JsonResponse
    {
        $data = $request->validate([
            'outcome_note' => ['nullable', 'string', 'max:190'],
        ]);

        try {
            $this->reservations->cancel($reservation, $data['outcome_note'] ?? null);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage());
        }

        ActivityLog::record('reservation.cancelled', "Cancelled the booking for {$reservation->guest_name}", $reservation);

        return ApiResponse::success('Booking cancelled. The table is free again.');
    }

    public function destroy(Reservation $reservation): JsonResponse
    {
        if ($reservation->status === Reservation::SEATED) {
            return ApiResponse::error('They are sitting at a table. Close the booking rather than deleting it.');
        }

        $name = $reservation->guest_name;
        $reservation->delete();

        ActivityLog::record('reservation.deleted', "Deleted the booking for {$name}", $reservation);

        return ApiResponse::success('Booking removed.');
    }

    /* -------------------------------------------------------- validation */

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Reservation $reservation = null): array
    {
        return $request->validate([
            'guest_name' => ['required', 'string', 'max:120'],
            'guest_mobile' => ['nullable', 'string', 'max:30'],
            'guest_email' => ['nullable', 'email', 'max:150'],
            'party_size' => ['required', 'integer', 'min:1', 'max:200'],

            'reserved_for' => ['required', 'date'],
            'duration_minutes' => ['required', 'integer', 'min:15', 'max:600'],

            'restaurant_table_id' => ['nullable', 'integer', 'exists:restaurant_tables,id'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],

            'status' => ['nullable', Rule::in(array_keys(Reservation::STATUSES))],
            'source' => ['nullable', Rule::in(Reservation::SOURCES)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    /**
     * The tables the booking form offers.
     *
     * With their floor, and with whoever is sitting at them right now.
     *
     * Both matter at the door. Every floor numbers its tables from one, so
     * "1 (4 seats)" names three different tables in this building and a host
     * taking a booking over the phone cannot tell which one they picked. And
     * a table that somebody is currently eating at should not read the same
     * as an empty one - see ReservationService::guardSitting(), which refuses
     * it anyway, but a form that only refuses after submitting is a form that
     * wastes the caller's time.
     *
     * @return \Illuminate\Support\Collection<int, RestaurantTable>
     */
    private function tables()
    {
        $occupied = $this->reservations->occupiedTableIds();

        return RestaurantTable::query()
            ->where('is_active', true)
            ->with('floor:id,name,sort_order')
            ->get(['id', 'floor_id', 'name', 'code', 'capacity'])
            ->each(fn (RestaurantTable $table) => $table->setAttribute(
                'is_occupied_now', in_array($table->id, $occupied, true),
            ))
            ->sortBy([
                fn (RestaurantTable $a, RestaurantTable $b) => ($a->floor?->sort_order ?? 0) <=> ($b->floor?->sort_order ?? 0),
                fn (RestaurantTable $a, RestaurantTable $b) => strnatcasecmp((string) $a->code, (string) $b->code),
            ])
            ->values();
    }
}
