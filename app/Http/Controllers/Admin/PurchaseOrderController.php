<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Shop;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Support\ApiResponse;
use App\Support\CurrentShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Purchase orders - what the shop has asked a supplier to send.
 *
 * An intention, not an event. Nothing here moves stock or money; the order's
 * own status follows the goods receipts raised against it, so nobody has to
 * remember to close one.
 */
class PurchaseOrderController extends Controller
{
    private const PAGE_SIZES = [10, 15, 25, 50];

    /* -------------------------------------------------------------- list */

    public function index(Request $request): View
    {
        $perPage = (int) $request->integer('per_page', 15);
        $perPage = in_array($perPage, self::PAGE_SIZES, true) ? $perPage : 15;

        $orders = $this->filtered($request)
            ->with(['supplier:id,name,company', 'warehouse:id,name', 'shop:id,name'])
            ->withCount('items')
            ->paginate($perPage)
            ->withQueryString();

        $data = [
            'orders' => $orders,
            'search' => $request->string('q')->toString(),
            'status' => $request->string('status')->toString(),
            'perPage' => $perPage,
            'pageSizes' => self::PAGE_SIZES,
            'statuses' => PurchaseOrder::STATUSES,
            'showsShop' => CurrentShop::id() === null,
            'stats' => [
                'total' => PurchaseOrder::count(),
                'pending' => PurchaseOrder::where('status', PurchaseOrder::PENDING)->count(),
                'open' => PurchaseOrder::query()->open()->count(),
                'value' => (float) PurchaseOrder::query()->open()->sum('grand_total'),
            ],
        ];

        return $request->header('X-Fragment')
            ? view('admin.purchase-orders._list', $data)
            : view('admin.purchase-orders.index', $data);
    }

    private function filtered(Request $request): Builder
    {
        return PurchaseOrder::query()
            ->search($request->string('q')->toString())
            ->ofStatus($request->string('status')->toString())
            ->orderByDesc('ordered_on')
            ->orderByDesc('id');
    }

    /* ---------------------------------------------------------- document */

    public function create(): View|RedirectResponse
    {
        $shop = CurrentShop::get();

        if ($shop === null) {
            return redirect()
                ->route('admin.purchase-orders.index')
                ->with('error', 'Choose a single shop before raising an order — it is placed by one branch.');
        }

        return view('admin.purchase-orders.form', [
            'order' => new PurchaseOrder([
                'ordered_on' => today()->toDateString(),
                'status' => PurchaseOrder::DRAFT,
            ]),
            'items' => collect(),
            'shop' => $shop,
            'suppliers' => Supplier::active()->orderBy('name')->get(['id', 'name', 'company']),
            'warehouses' => Warehouse::active()->orderByDesc('is_default')->orderBy('name')->get(),
            'reference' => PurchaseOrder::nextReference($shop),
        ]);
    }

    public function edit(PurchaseOrder $order): View|RedirectResponse
    {
        if (! $order->isEditable()) {
            return redirect()
                ->route('admin.purchase-orders.show', $order)
                ->with('error', 'This order has been '.strtolower($order->statusLabel()).' and can no longer be edited.');
        }

        return view('admin.purchase-orders.form', [
            'order' => $order,
            'items' => $order->items()->with('product.unit')->get(),
            'shop' => Shop::find($order->shop_id),
            'suppliers' => Supplier::active()->orderBy('name')->get(['id', 'name', 'company']),
            'warehouses' => Warehouse::active()->orderByDesc('is_default')->orderBy('name')->get(),
            'reference' => $order->reference,
        ]);
    }

    public function show(PurchaseOrder $order): View
    {
        return view('admin.purchase-orders.show', [
            'order' => $order->load(['supplier', 'warehouse', 'shop']),
            'items' => $order->items()->with('product:id,name,sku')->get(),
            'receipts' => $order->receipts()->orderByDesc('received_on')->get(),
        ]);
    }

    /* ------------------------------------------------------------ writes */

    public function store(Request $request): JsonResponse
    {
        $shop = CurrentShop::get();

        if ($shop === null) {
            return ApiResponse::error('Choose a single shop before raising an order.');
        }

        $data = $this->validated($request);

        $order = DB::transaction(function () use ($data, $shop, $request) {
            $user = Auth::user();

            $order = new PurchaseOrder($this->attributes($data) + [
                'shop_id' => $shop->id,
                'reference' => PurchaseOrder::nextReference($shop),
                'status' => $this->requestedStatus($request),
            ]);

            $order->forceFill([
                'created_by' => $user?->id,
                'created_by_name' => $user?->name ?? 'System',
            ]);

            $order->save();

            $this->syncItems($order, $data['items']);

            return $order;
        });

        ActivityLog::record(
            'purchase_order.created',
            "Raised purchase order {$order->reference}",
            $order,
        );

        return ApiResponse::success(
            "Order {$order->reference} saved.",
            ['id' => $order->id, 'reference' => $order->reference],
            route('admin.purchase-orders.show', $order),
        );
    }

    public function update(Request $request, PurchaseOrder $order): JsonResponse
    {
        if (! $order->isEditable()) {
            return ApiResponse::error(
                'This order has been '.strtolower($order->statusLabel()).' and can no longer be edited.'
            );
        }

        $data = $this->validated($request, $order);

        DB::transaction(function () use ($order, $data, $request) {
            $attributes = $this->attributes($data);
            unset($attributes['shop_id']);

            $order->fill($attributes + ['status' => $this->requestedStatus($request)])->save();

            $order->items()->delete();
            $this->syncItems($order, $data['items']);
        });

        return ApiResponse::success(
            "Order {$order->reference} saved.",
            [],
            route('admin.purchase-orders.show', $order),
        );
    }

    public function destroy(PurchaseOrder $order): JsonResponse
    {
        if (! $order->isEditable()) {
            return ApiResponse::error('Only a draft or pending order can be deleted. Cancel it instead.');
        }

        $reference = $order->reference;
        $order->delete();

        ActivityLog::record('purchase_order.deleted', "Deleted purchase order {$reference}");

        return ApiResponse::success("Order {$reference} deleted.", [], route('admin.purchase-orders.index'));
    }

    /* --------------------------------------------------------- decisions */

    public function approve(Request $request, PurchaseOrder $order): JsonResponse
    {
        if (! $order->isEditable()) {
            return ApiResponse::error('That order has already been '.strtolower($order->statusLabel()).'.');
        }

        if ($order->items()->count() === 0) {
            return ApiResponse::error('There is nothing to order — the order has no lines.');
        }

        $user = Auth::user();

        $order->forceFill([
            'status' => PurchaseOrder::APPROVED,
            'approved_by' => $user?->id,
            'approved_by_name' => $user?->name ?? 'System',
            'approved_at' => now(),
            'review_note' => $request->string('review_note')->toString() ?: $order->review_note,
        ])->save();

        ActivityLog::record(
            'purchase_order.approved',
            "Approved purchase order {$order->reference}",
            $order,
        );

        return ApiResponse::success(
            "Order {$order->reference} approved. Receive against it when the goods arrive.",
            [],
            route('admin.purchase-orders.show', $order),
        );
    }

    public function cancel(Request $request, PurchaseOrder $order): JsonResponse
    {
        if ($order->status === PurchaseOrder::RECEIVED) {
            return ApiResponse::error('This order has been received in full and cannot be cancelled.');
        }

        if ($order->status === PurchaseOrder::PARTIAL) {
            return ApiResponse::error(
                'Part of this order has already arrived. Close it by receiving the rest, or short-receive it.'
            );
        }

        $order->forceFill([
            'status' => PurchaseOrder::CANCELLED,
            'review_note' => $request->string('review_note')->toString() ?: $order->review_note,
        ])->save();

        ActivityLog::record(
            'purchase_order.cancelled',
            "Cancelled purchase order {$order->reference}",
            $order,
        );

        return ApiResponse::success(
            "Order {$order->reference} cancelled.",
            [],
            route('admin.purchase-orders.show', $order),
        );
    }

    /* ----------------------------------------------------------- helpers */

    private function requestedStatus(Request $request): string
    {
        return $request->string('intent')->toString() === 'submit'
            ? PurchaseOrder::PENDING
            : PurchaseOrder::DRAFT;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function syncItems(PurchaseOrder $order, array $items): void
    {
        $subtotal = 0.0;
        $tax = 0.0;

        foreach ($items as $line) {
            $product = Product::with('unit')->findOrFail((int) $line['product_id']);

            $quantity = (float) $line['quantity'];
            $cost = (float) $line['unit_cost'];
            $rate = (float) ($line['tax_rate'] ?? 0);

            $value = $quantity * $cost;
            $lineTax = $value * $rate / 100;

            $order->items()->create([
                'product_id' => $product->id,
                'product_name' => $product->name,
                'sku' => $product->sku,
                'unit_code' => $product->unit?->code,
                'quantity' => $quantity,
                'unit_cost' => $cost,
                'tax_rate' => $rate,
                'line_total' => round($value + $lineTax, 2),
                'note' => $line['note'] ?? null,
            ]);

            $subtotal += $value;
            $tax += $lineTax;
        }

        $order->forceFill([
            'subtotal' => round($subtotal, 2),
            'tax_total' => round($tax, 2),
            'grand_total' => round($subtotal + $tax, 2),
        ])->save();
    }

    /* ------------------------------------------------------- validation */

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?PurchaseOrder $order = null): array
    {
        $shopId = $order?->shop_id ?? CurrentShop::id();

        return $request->validate([
            'supplier_id' => [
                'required', 'integer',
                Rule::exists('suppliers', 'id')->where('shop_id', $shopId)->whereNull('deleted_at'),
            ],
            'warehouse_id' => [
                'nullable', 'integer',
                Rule::exists('warehouses', 'id')->where('shop_id', $shopId),
            ],
            'ordered_on' => ['required', 'date'],
            'expected_on' => ['nullable', 'date', 'after_or_equal:ordered_on'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'intent' => ['nullable', Rule::in(['save', 'submit'])],

            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->whereNull('deleted_at')],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0'],
            'items.*.tax_rate' => ['nullable', 'numeric', 'between:0,100'],
            'items.*.note' => ['nullable', 'string', 'max:250'],
        ], [
            'supplier_id.exists' => 'That supplier is not available in this shop.',
            'warehouse_id.exists' => 'Choose a warehouse belonging to this shop.',
            'expected_on.after_or_equal' => 'Goods cannot be expected before they are ordered.',
            'items.required' => 'Add at least one product to order.',
            'items.min' => 'Add at least one product to order.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        return [
            'supplier_id' => $data['supplier_id'],
            'warehouse_id' => $data['warehouse_id'] ?? null,
            'ordered_on' => $data['ordered_on'],
            'expected_on' => $data['expected_on'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];
    }
}
