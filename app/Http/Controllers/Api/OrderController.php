<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Services\OrderService;
use App\Support\CurrentShop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Orders, for an aggregator or a captain's app (§2, §21).
 *
 * ---------------------------------------------------------------------------
 * Prices are never taken from the caller
 * ---------------------------------------------------------------------------
 *
 * A request says which dish and how many. What it costs comes from the menu,
 * every time. An aggregator that could name its own price could bill a
 * restaurant's customer a rupee for a biryani, and "they sent it in the
 * payload" is not a defence anybody wants to make.
 *
 * ---------------------------------------------------------------------------
 * The reference is what makes retries safe
 * ---------------------------------------------------------------------------
 *
 * Aggregators retry. Networks drop. `external_reference` is the caller's own
 * id for the order, and posting the same one twice returns the order that
 * already exists rather than making a second one - which is the difference
 * between a duplicate ticket in a kitchen and a quiet no-op.
 */
class OrderController extends Controller
{
    public function __construct(private readonly OrderService $orders) {}

    public function index(Request $request): JsonResponse
    {
        $orders = Order::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->filled('since'), fn ($q) => $q->where('created_at', '>=', $request->date('since')))
            ->with(['items:id,order_id,product_name,quantity,unit_price,line_total'])
            ->latest('id')
            ->paginate(min(100, (int) $request->integer('per_page', 50)));

        return response()->json([
            'data' => $orders->getCollection()->map(fn (Order $o) => $this->row($o)),
            'meta' => [
                'page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'total' => $orders->total(),
            ],
        ]);
    }

    public function show(Order $order): JsonResponse
    {
        return response()->json(['data' => $this->row(
            $order->load(['items', 'statusLogs'])
        , withTrail: true)]);
    }

    /**
     * Take an order.
     *
     * @throws RuntimeException translated into a 422 by the handler
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'external_reference' => ['nullable', 'string', 'max:120'],
            'order_type' => ['required', Rule::in([Order::DINE_IN, Order::TAKEAWAY, Order::DELIVERY])],

            'guest_name' => ['nullable', 'string', 'max:120'],
            'guest_mobile' => ['nullable', 'string', 'max:30'],
            'note' => ['nullable', 'string', 'max:500'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.variant_id' => ['nullable', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.note' => ['nullable', 'string', 'max:250'],
        ]);

        /*
         | The retry guard. Aggregators retry and networks drop; posting the
         | same reference twice must not put a second ticket in the kitchen.
         */
        if (filled($data['external_reference'] ?? null)) {
            $existing = Order::query()
                ->where('customer_note', 'like', '%'.$data['external_reference'].'%')
                ->first();

            if ($existing !== null) {
                return response()->json(['data' => $this->row($existing), 'duplicate' => true], 200);
            }
        }

        /*
         | Every price comes from the menu. What the caller sent is the dish
         | and the quantity, and nothing else is trusted.
         */
        $lines = [];

        foreach ($data['items'] as $line) {
            $product = Product::query()->sellable()->find($line['product_id']);

            if ($product === null) {
                return response()->json([
                    'message' => 'Item '.$line['product_id'].' is not on this menu.',
                ], 422);
            }

            if ($product->is_sold_out) {
                return response()->json([
                    'message' => $product->name.' is sold out.',
                ], 409);
            }

            $lines[] = [
                'product_id' => $product->id,
                'product_variant_id' => $line['variant_id'] ?? null,
                'quantity' => (float) $line['quantity'],
                'note' => $line['note'] ?? null,
            ];
        }

        try {
            $order = $this->orders->placeExternal([
                'shop_id' => CurrentShop::idForWrite(),
                'order_type' => $data['order_type'],
                'guest_name' => $data['guest_name'] ?? null,
                'guest_mobile' => $data['guest_mobile'] ?? null,
                'customer_note' => trim(($data['note'] ?? '').' '.
                    (filled($data['external_reference'] ?? null) ? '[ref '.$data['external_reference'].']' : '')),
                'items' => $lines,
            ]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->row($order)], 201);
    }

    /**
     * Move an order along.
     *
     * Deliberately narrow: an integrator may confirm, cancel or mark
     * delivered. Everything between those is the kitchen's own screen, and an
     * API that could set any status would let an aggregator tell a restaurant
     * its food was ready.
     */
    public function status(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in([Order::CONFIRMED, Order::DELIVERED, Order::CANCELLED])],
            'reason' => ['nullable', 'string', 'max:250'],
        ]);

        if ($order->status === $data['status']) {
            // Idempotent: a retried call is a no-op, not an error.
            return response()->json(['data' => $this->row($order)]);
        }

        try {
            /*
             | The service's own methods, so an order moved through the API
             | follows exactly the same rules as one moved on a screen -
             | stock, alerts, the status trail and all.
             |
             | They want a User because those rules are written around "who
             | did this". A token belongs to one, which is the honest answer:
             | whoever the integration was set up as.
             */
            $staff = $request->user();

            match ($data['status']) {
                Order::CONFIRMED => $this->orders->confirm($order, $staff),
                Order::DELIVERED => $this->orders->markDelivered($order),
                Order::CANCELLED => $this->orders->cancel(
                    $order,
                    $data['reason'] ?? 'Cancelled through the API',
                    $staff,
                ),
            };
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->row($order->fresh(['items']))]);
    }

    /**
     * One order, as an integrator sees it.
     *
     * @return array<string, mixed>
     */
    private function row(Order $order, bool $withTrail = false): array
    {
        $row = [
            'id' => $order->id,
            'number' => $order->order_number,
            'type' => $order->order_type,
            'status' => $order->status,
            'payment_status' => $order->payment_status,
            'guest_name' => $order->guest_name,
            'total' => (float) $order->grand_total,
            'placed_at' => $order->placed_at?->toIso8601String(),
            'items' => $order->items->map(fn ($item) => [
                'name' => $item->product_name,
                'quantity' => (float) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'total' => (float) $item->line_total,
            ])->values(),
        ];

        if ($withTrail) {
            // Every step and how long it sat there - see OrderStatusLog.
            $row['timeline'] = $order->statusLogs->map(fn ($step) => [
                'to' => $step->to_status,
                'at' => $step->created_at?->toIso8601String(),
                'waited_seconds' => $step->seconds_in_previous,
            ])->values();
        }

        return $row;
    }
}
