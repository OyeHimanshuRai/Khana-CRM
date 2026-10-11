<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RestaurantTable;
use App\Services\ReservationService;
use Illuminate\Http\JsonResponse;

/**
 * The floor, for a captain's app (§12, §21).
 *
 * Read-only. Seating a party, moving an order and clearing a table all go
 * through the admin's own screens, which already carry the rules about what
 * may happen to a sitting - and an API that reimplemented them would be a
 * second set of rules to keep in step with the first.
 *
 * The next booking is included because it is the one thing a captain
 * standing in front of a free table actually needs to know: free now, or free
 * now and promised at eight.
 */
class TableController extends Controller
{
    public function __construct(private readonly ReservationService $reservations) {}

    public function index(): JsonResponse
    {
        $tables = RestaurantTable::query()
            ->where('is_active', true)
            ->with(['floor:id,name', 'currentSession:id,restaurant_table_id,opened_at,covers'])
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $tables->map(function (RestaurantTable $table) {
                $next = $this->reservations->nextBookingFor($table);

                return [
                    'id' => $table->id,
                    'name' => $table->name,
                    'code' => $table->code,
                    'area' => $table->floor?->name,
                    'seats' => $table->capacity,
                    'status' => $table->status,
                    'status_label' => $table->statusLabel(),
                    'session' => $table->currentSession === null ? null : [
                        'id' => $table->currentSession->id,
                        'opened_at' => $table->currentSession->opened_at?->toIso8601String(),
                        'covers' => $table->currentSession->covers,
                    ],
                    /*
                     | Free now, or free now and promised at eight. The one
                     | thing a captain in front of an empty table needs.
                     */
                    'next_booking' => $next === null ? null : [
                        'name' => $next->guest_name,
                        'party_size' => $next->party_size,
                        'at' => $next->reserved_for->toIso8601String(),
                    ],
                ];
            }),
        ]);
    }
}
