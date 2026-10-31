<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\RestaurantTable;
use App\Models\TableSession;
use Illuminate\Support\Collection;

/**
 * What the floor plan should flag, worked out once for the whole plan.
 *
 * One query for every table's next booking, however many tables there are.
 * The sessions arrive already loaded with the tables (`currentSession`), so
 * nothing here touches the database per tile.
 *
 * Two of the clocks are approximations because the schema keeps no dedicated
 * timestamp for them, and the plan says so rather than pretending:
 *
 *   - "bill waiting" runs from the session's last activity, which is when it
 *     was billed unless somebody has since touched it;
 *   - "cleaning" runs from the table row's last update, so moving a table that
 *     is being cleaned restarts its clock.
 */
class FloorPlanInsights
{
    public const LONG_STAY = 'long_stay';

    public const BILL_WAITING = 'bill_waiting';

    public const SLOW_CLEANING = 'slow_cleaning';

    public const LATE_BOOKING = 'late_booking';

    /** @var array<string, string> */
    public const LABELS = [
        self::LONG_STAY => 'Seated a long time',
        self::BILL_WAITING => 'Bill waiting',
        self::SLOW_CLEANING => 'Cleaning is slow',
        self::LATE_BOOKING => 'Guest is late',
    ];

    /**
     * @param  Collection<int, RestaurantTable>  $tables  with `currentSession` loaded
     * @return array<int, array{reservation: ?Reservation, alerts: array<int, string>}> keyed by table id
     */
    public function for(Collection $tables): array
    {
        if ($tables->isEmpty()) {
            return [];
        }

        $config = config('dining.plan');
        $bookings = $this->nextBookings($tables->pluck('id')->all(), (int) $config['reservation_lead_minutes']);

        $insights = [];

        foreach ($tables as $table) {
            $booking = $bookings[$table->id] ?? null;
            $alerts = $this->alertsFor($table, $booking, $config);

            $insights[$table->id] = ['reservation' => $booking, 'alerts' => $alerts];
        }

        return $insights;
    }

    /**
     * Where each tile is drawn.
     *
     * A table nobody has dragged yet sits at 0,0, which would pile every new
     * table in one corner. Those are spread over a grid instead, for display
     * only: nothing is saved until somebody places a table by hand, and a
     * table that has been placed is never moved.
     *
     * @param  Collection<int, RestaurantTable>  $tables
     * @return array<int, array{x: float, y: float, auto: bool}> keyed by table id
     */
    public function positions(Collection $tables): array
    {
        $positions = [];
        $unplaced = [];

        foreach ($tables as $table) {
            $x = (float) $table->pos_x;
            $y = (float) $table->pos_y;

            if ($x === 0.0 && $y === 0.0) {
                $unplaced[] = $table->id;

                continue;
            }

            $positions[$table->id] = ['x' => $x, 'y' => $y, 'auto' => false];
        }

        $count = count($unplaced);

        if ($count === 0) {
            return $positions;
        }

        $columns = min($count, 5);
        $rows = (int) ceil($count / $columns);
        // Kept 4% in from every edge. A tile is about a quarter of the canvas
        // tall, so rows are never squeezed tighter than that.
        $rowStep = $rows > 1 ? min(28.0, 92 / ($rows - 1)) : 0.0;

        foreach ($unplaced as $i => $id) {
            $column = $i % $columns;

            $positions[$id] = [
                'x' => $columns > 1 ? round(4 + $column * 92 / ($columns - 1), 3) : 50.0,
                'y' => round(4 + intdiv($i, $columns) * $rowStep, 3),
                'auto' => true,
            ];
        }

        return $positions;
    }

    /**
     * How many tables have at least one alert.
     *
     * @param  array<int, array{alerts: array<int, string>}>  $insights
     */
    public function needingAttention(array $insights): int
    {
        return count(array_filter($insights, fn (array $i) => $i['alerts'] !== []));
    }

    /**
     * @param  array<int, int>  $tableIds
     * @return array<int, Reservation> earliest unresolved booking per table
     */
    private function nextBookings(array $tableIds, int $leadMinutes): array
    {
        return Reservation::query()
            ->whereIn('restaurant_table_id', $tableIds)
            ->whereIn('status', [Reservation::REQUESTED, Reservation::CONFIRMED])
            ->where('reserved_for', '<=', now()->addMinutes($leadMinutes))
            // A booking nobody resolved hours ago is noise, not a late guest.
            ->where('reserved_for', '>=', now()->subHours(3))
            ->orderBy('reserved_for')
            ->get()
            ->unique('restaurant_table_id')
            ->keyBy('restaurant_table_id')
            ->all();
    }

    /**
     * @param  array<string, int>  $config
     * @return array<int, string>
     */
    private function alertsFor(RestaurantTable $table, ?Reservation $booking, array $config): array
    {
        $alerts = [];
        $session = $table->currentSession;

        if ($session !== null) {
            if ($session->status === TableSession::BILLED) {
                $since = $session->last_activity_at ?? $session->opened_at;

                if ($since !== null && $since->diffInMinutes(now()) >= $config['bill_wait_minutes']) {
                    $alerts[] = self::BILL_WAITING;
                }
            } elseif ($session->seatedMinutes() >= $config['long_stay_minutes']) {
                $alerts[] = self::LONG_STAY;
            }
        } elseif ($table->status === RestaurantTable::CLEANING
            && $table->updated_at !== null
            && $table->updated_at->diffInMinutes(now()) >= $config['cleaning_minutes']) {
            $alerts[] = self::SLOW_CLEANING;
        }

        if ($booking !== null
            && $session === null
            && $booking->reserved_for->diffInMinutes(now(), false) >= $config['reservation_late_minutes']) {
            $alerts[] = self::LATE_BOOKING;
        }

        return $alerts;
    }
}
