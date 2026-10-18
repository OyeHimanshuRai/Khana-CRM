<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\RestaurantTable;
use App\Services\ReservationService;
use App\Services\TableBillService;
use App\Services\TableCartService;
use App\Services\TableOrderService;
use App\Services\TableSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The floor, for a captain's app (§12, §21).
 *
 * Ordering goes through the same services the QR pad uses; moving an order
 * and clearing a table stay on the admin's own screens.
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

    // One table with what its party has ordered and owes, for the captain standing at it.
    public function show(RestaurantTable $table, TableBillService $bills): JsonResponse
    {
        $table->load(['floor:id,name', 'currentSession']);
        $session = $table->currentSession;
        $summary = $session === null ? null : $bills->summary($session);

        return response()->json([
            'id' => $table->id,
            'name' => $table->name,
            'area' => $table->floor?->name,
            'seats' => $table->capacity,
            'status' => $table->status,
            'status_label' => $table->statusLabel(),
            'session' => $session === null ? null : [
                'id' => $session->id,
                'status' => $session->status,
                'opened_at' => $session->opened_at?->toIso8601String(),
                'covers' => $session->covers,
                'party' => $session->partyName(),
                'unbilled' => $summary['unbilled'],
                'billed' => $summary['billed'],
                'paid' => $summary['paid'],
                'due' => $summary['due'],
                'in_kitchen' => $summary['in_kitchen'],
                'lines' => $summary['lines']->map(fn (OrderItem $line) => [
                    'order_number' => $line->order?->order_number,
                    'name' => $line->product_name,
                    'size' => $line->variant_name,
                    'add_ons' => $line->modifiers->pluck('option_name')->values(),
                    'quantity' => $line->unsettledQuantity(),
                    'unit_price' => (float) $line->unit_price,
                    'kitchen_status' => $line->kitchen_status,
                    'note' => $line->note,
                ])->values(),
            ],
        ]);
    }

    // A captain's order for a table: seats it if nobody has, puts every dish on its pad and sends it to the kitchen, all or nothing.
    public function order(
        Request $request,
        RestaurantTable $table,
        TableSessionService $sessions,
        TableCartService $cart,
        TableOrderService $orders,
    ): JsonResponse {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.variant_id' => ['nullable', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:30'],
            'items.*.options' => ['nullable', 'array'],
            'items.*.options.*' => ['integer'],
            'items.*.note' => ['nullable', 'string', 'max:190'],
        ]);

        $by = 'staff:'.$request->user()->name;

        try {
            $order = DB::transaction(function () use ($data, $table, $sessions, $cart, $orders, $by) {
                $session = $sessions->openFor($table);

                foreach ($data['items'] as $item) {
                    $product = Product::query()->find($item['product_id']);

                    if ($product === null) {
                        throw new RuntimeException('One of those dishes is not on this branch\'s menu.');
                    }

                    $cart->add($session, $product, $item['variant_id'] ?? null, (int) $item['quantity'], $item['options'] ?? [], $item['note'] ?? null, $by);
                }

                return $orders->place($session, ['name' => $session->guest_name, 'mobile' => $session->guest_mobile]);
            });
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => $order->order_number.' sent to the kitchen.',
            'order' => ['id' => $order->id, 'number' => $order->order_number, 'status' => $order->status],
        ], 201);
    }
}
