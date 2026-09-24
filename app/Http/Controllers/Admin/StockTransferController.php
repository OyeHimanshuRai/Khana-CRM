<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Batch;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\StockTransfer;
use App\Models\Warehouse;
use App\Services\StockTransferService;
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
use RuntimeException;

/**
 * Stock transfers between warehouses, including across shops.
 *
 * The listing has two halves that matter: what this shop is sending, and
 * what is coming towards it. The second is not shop-scoped in the usual way
 * - the sending shop owns the row - so it is fetched deliberately through
 * `incomingFor`, which is the one place that boundary is crossed and the one
 * place to audit it.
 */
class StockTransferController extends Controller
{
    private const PAGE_SIZES = [10, 15, 25, 50];

    public function __construct(private readonly StockTransferService $service) {}

    /* -------------------------------------------------------------- list */

    public function index(Request $request): View
    {
        $perPage = (int) $request->integer('per_page', 15);
        $perPage = in_array($perPage, self::PAGE_SIZES, true) ? $perPage : 15;

        $direction = $request->string('direction')->toString() ?: 'outgoing';

        $transfers = $this->filtered($request, $direction)
            ->with(['fromWarehouse:id,name', 'toWarehouse:id,name', 'shop:id,name,code', 'toShop:id,name,code'])
            ->withCount('items')
            ->paginate($perPage)
            ->withQueryString();

        $data = [
            'transfers' => $transfers,
            'search' => $request->string('q')->toString(),
            'status' => $request->string('status')->toString(),
            'direction' => $direction,
            'perPage' => $perPage,
            'pageSizes' => self::PAGE_SIZES,
            'statuses' => StockTransfer::STATUSES,
            'stats' => [
                'total' => StockTransfer::count(),
                'pending' => StockTransfer::where('status', StockTransfer::PENDING)->count(),
                'in_transit' => StockTransfer::where('status', StockTransfer::DISPATCHED)->count(),
                'incoming' => StockTransfer::allShops()
                    ->incomingFor(CurrentShop::accessibleIds())
                    ->where('status', StockTransfer::DISPATCHED)
                    ->count(),
            ],
        ];

        return $request->header('X-Fragment')
            ? view('admin.stock-transfers._list', $data)
            : view('admin.stock-transfers.index', $data);
    }

    private function filtered(Request $request, string $direction): Builder
    {
        /*
         | Outgoing reads through the tenant scope as usual. Incoming has to
         | step outside it: the sending shop owns the row, so a receiving
         | shop would otherwise never see the consignment it has to book in.
         | It is narrowed to to_shop_id in the reader's own shops, which
         | keeps the escape hatch honest.
         */
        $query = $direction === 'incoming'
            ? StockTransfer::allShops()->incomingFor(CurrentShop::accessibleIds())
            : StockTransfer::query();

        return $query
            ->search($request->string('q')->toString())
            ->ofStatus($request->string('status')->toString())
            ->orderByDesc('transfer_date')
            ->orderByDesc('id');
    }

    /* ---------------------------------------------------------- document */

    public function create(): View|RedirectResponse
    {
        $shop = CurrentShop::get();

        if ($shop === null) {
            return redirect()
                ->route('admin.stock-transfers.index')
                ->with('error', 'Choose a single shop before raising a transfer — stock leaves one warehouse, not several.');
        }

        return view('admin.stock-transfers.form', [
            'transfer' => new StockTransfer([
                'transfer_date' => today()->toDateString(),
                'to_shop_id' => $shop->id,
            ]),
            'items' => collect(),
            'shop' => $shop,
            'fromWarehouses' => Warehouse::active()->orderByDesc('is_default')->orderBy('name')->get(),
            'destinations' => $this->destinations(),
            'reference' => StockTransfer::nextReference($shop),
        ]);
    }

    public function edit(StockTransfer $transfer): View|RedirectResponse
    {
        if (! $transfer->isEditable()) {
            return redirect()
                ->route('admin.stock-transfers.show', $transfer)
                ->with('error', 'This transfer has been '.strtolower($transfer->statusLabel()).' and can no longer be edited.');
        }

        return view('admin.stock-transfers.form', [
            'transfer' => $transfer,
            'items' => $transfer->items()->with(['product.unit', 'batch'])->get(),
            'shop' => $transfer->shop,
            'fromWarehouses' => Warehouse::active()->orderByDesc('is_default')->orderBy('name')->get(),
            'destinations' => $this->destinations(),
            'reference' => $transfer->reference,
        ]);
    }

    public function show(StockTransfer $transfer): View
    {
        return view('admin.stock-transfers.show', [
            'transfer' => $transfer->load([
                'fromWarehouse', 'toWarehouse', 'shop', 'toShop', 'creator', 'approver', 'receiver',
            ]),
            'items' => $transfer->items()->with(['product.unit', 'batch'])->get(),
            'canReceive' => $transfer->isInTransit()
                && in_array((int) $transfer->to_shop_id, CurrentShop::accessibleIds(), true),
        ]);
    }

    /**
     * Where a transfer may be sent: every warehouse in every shop the user
     * can reach. Sending somewhere they cannot see would create stock they
     * could never account for.
     *
     * @return \Illuminate\Support\Collection<int, Warehouse>
     */
    private function destinations()
    {
        return Warehouse::allShops()
            ->with('shop:id,name,code')
            ->whereIn('shop_id', CurrentShop::accessibleIds() ?: [0])
            ->where('is_active', true)
            ->orderBy('shop_id')
            ->orderBy('name')
            ->get();
    }

    /* ------------------------------------------------------------ writes */

    public function store(Request $request): JsonResponse
    {
        $shop = CurrentShop::get();

        if ($shop === null) {
            return ApiResponse::error('Choose a single shop before raising a transfer.');
        }

        $data = $this->validated($request);

        $transfer = DB::transaction(function () use ($data, $shop, $request) {
            $user = Auth::user();
            $destination = Warehouse::allShops()->findOrFail($data['to_warehouse_id']);

            $transfer = new StockTransfer([
                'shop_id' => $shop->id,
                'from_warehouse_id' => $data['from_warehouse_id'],
                'to_shop_id' => $destination->shop_id,
                'to_warehouse_id' => $destination->id,
                'reference' => StockTransfer::nextReference($shop),
                'transfer_date' => $data['transfer_date'],
                'status' => $this->requestedStatus($request),
                'note' => $data['note'] ?? null,
            ]);

            $transfer->forceFill([
                'created_by' => $user?->id,
                'created_by_name' => $user?->name ?? 'System',
            ]);

            $transfer->save();

            $this->syncItems($transfer, $data['items']);

            return $transfer;
        });

        ActivityLog::record(
            'stock_transfer.created',
            "Raised stock transfer {$transfer->reference}",
            $transfer,
        );

        return ApiResponse::success(
            "Transfer {$transfer->reference} saved.",
            ['id' => $transfer->id, 'reference' => $transfer->reference],
            route('admin.stock-transfers.show', $transfer),
        );
    }

    public function update(Request $request, StockTransfer $transfer): JsonResponse
    {
        if (! $transfer->isEditable()) {
            return ApiResponse::error(
                'This transfer has been '.strtolower($transfer->statusLabel()).' and can no longer be edited.'
            );
        }

        $data = $this->validated($request, $transfer);

        DB::transaction(function () use ($transfer, $data, $request) {
            $destination = Warehouse::allShops()->findOrFail($data['to_warehouse_id']);

            $transfer->fill([
                'from_warehouse_id' => $data['from_warehouse_id'],
                'to_shop_id' => $destination->shop_id,
                'to_warehouse_id' => $destination->id,
                'transfer_date' => $data['transfer_date'],
                'status' => $this->requestedStatus($request),
                'note' => $data['note'] ?? null,
            ])->save();

            $transfer->items()->delete();
            $this->syncItems($transfer, $data['items']);
        });

        ActivityLog::record(
            'stock_transfer.updated',
            "Updated stock transfer {$transfer->reference}",
            $transfer,
        );

        return ApiResponse::success(
            "Transfer {$transfer->reference} saved.",
            [],
            route('admin.stock-transfers.show', $transfer),
        );
    }

    public function destroy(StockTransfer $transfer): JsonResponse
    {
        if (! $transfer->isEditable()) {
            return ApiResponse::error(
                'Only a draft or pending transfer can be deleted. This one has been '
                .strtolower($transfer->statusLabel()).'.'
            );
        }

        $reference = $transfer->reference;
        $transfer->delete();

        ActivityLog::record('stock_transfer.deleted', "Deleted stock transfer {$reference}");

        return ApiResponse::success(
            "Transfer {$reference} deleted.",
            [],
            route('admin.stock-transfers.index'),
        );
    }

    /* --------------------------------------------------------- lifecycle */

    public function approve(Request $request, StockTransfer $transfer): JsonResponse
    {
        return $this->run(
            fn () => $this->service->approve($transfer, $request->string('review_note')->toString() ?: null),
            $transfer,
            "Transfer {$transfer->reference} approved. Dispatch it when the goods leave.",
        );
    }

    public function reject(Request $request, StockTransfer $transfer): JsonResponse
    {
        return $this->run(
            fn () => $this->service->reject($transfer, $request->string('review_note')->toString() ?: null),
            $transfer,
            "Transfer {$transfer->reference} rejected.",
        );
    }

    public function dispatchGoods(StockTransfer $transfer): JsonResponse
    {
        return $this->run(
            fn () => $this->service->dispatch($transfer),
            $transfer,
            "Transfer {$transfer->reference} dispatched. The stock has left "
                .($transfer->fromWarehouse?->name ?? 'the warehouse').'.',
        );
    }

    /**
     * Book a consignment in at the receiving end.
     *
     * Only someone with access to the destination shop may do this: the
     * whole point of the two-step is that the receiver counts what arrived.
     */
    public function receive(Request $request, StockTransfer $transfer): JsonResponse
    {
        if (! in_array((int) $transfer->to_shop_id, CurrentShop::accessibleIds(), true)) {
            return ApiResponse::error('Only the receiving shop can book this consignment in.', [], 403);
        }

        $data = $request->validate([
            'received' => ['nullable', 'array'],
            'received.*' => ['nullable', 'numeric', 'min:0'],
            'review_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $received = collect($data['received'] ?? [])
            ->mapWithKeys(fn ($quantity, $itemId) => [(int) $itemId => (float) $quantity])
            ->all();

        return $this->run(
            fn () => $this->service->receive($transfer, $received, $data['review_note'] ?? null),
            $transfer,
            "Transfer {$transfer->reference} received.",
        );
    }

    public function cancel(StockTransfer $transfer): JsonResponse
    {
        return $this->run(
            fn () => $this->service->cancel($transfer),
            $transfer,
            "Transfer {$transfer->reference} cancelled.",
        );
    }

    /**
     * Run a lifecycle step, turning its refusal into a readable message.
     */
    private function run(callable $action, StockTransfer $transfer, string $message): JsonResponse
    {
        try {
            $action();
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage());
        }

        return ApiResponse::success($message, [], route('admin.stock-transfers.show', $transfer));
    }

    /* ----------------------------------------------------------- helpers */

    private function requestedStatus(Request $request): string
    {
        return $request->string('intent')->toString() === 'submit'
            ? StockTransfer::PENDING
            : StockTransfer::DRAFT;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function syncItems(StockTransfer $transfer, array $items): void
    {
        $quantity = 0.0;

        foreach ($items as $line) {
            $productId = (int) $line['product_id'];
            $batchId = isset($line['batch_id']) && $line['batch_id'] !== '' ? (int) $line['batch_id'] : null;

            $slot = ProductStock::allShops()
                ->where('shop_id', $transfer->shop_id)
                ->where('warehouse_id', $transfer->from_warehouse_id)
                ->where('product_id', $productId)
                ->where('batch_id', $batchId)
                ->first();

            $transfer->items()->create([
                'product_id' => $productId,
                'batch_id' => $batchId,
                'quantity' => (float) $line['quantity'],
                // Re-read at dispatch, when the cost is actually settled;
                // this is a hint for the document while it is still a draft.
                'unit_cost' => (float) ($slot->average_cost ?? 0),
                'note' => $line['note'] ?? null,
            ]);

            $quantity += (float) $line['quantity'];
        }

        $transfer->forceFill(['total_quantity' => $quantity])->save();
    }

    /* ------------------------------------------------------- validation */

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?StockTransfer $transfer = null): array
    {
        $shopId = $transfer?->shop_id ?? CurrentShop::id();

        return $request->validate([
            'from_warehouse_id' => [
                'required', 'integer',
                Rule::exists('warehouses', 'id')->where('shop_id', $shopId),
            ],
            'to_warehouse_id' => [
                'required', 'integer', 'different:from_warehouse_id',
                Rule::exists('warehouses', 'id')->whereIn('shop_id', CurrentShop::accessibleIds() ?: [0]),
            ],
            'transfer_date' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:2000'],
            'intent' => ['nullable', Rule::in(['save', 'submit'])],

            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->whereNull('deleted_at')],
            'items.*.batch_id' => [
                'nullable', 'integer',
                Rule::exists('batches', 'id')->where('shop_id', $shopId),
            ],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.note' => ['nullable', 'string', 'max:250'],
        ], [
            'from_warehouse_id.exists' => 'Choose a warehouse belonging to this shop.',
            'to_warehouse_id.exists' => 'You do not have access to that destination.',
            'to_warehouse_id.different' => 'Stock cannot be transferred to the warehouse it is already in.',
            'items.required' => 'Add at least one product to transfer.',
            'items.min' => 'Add at least one product to transfer.',
            'items.*.quantity.gt' => 'Transfer quantities have to be more than zero.',
        ]);
    }

    /* ------------------------------------------------------------ lookup */

    /**
     * Products with their quantity in the sending warehouse.
     *
     * The figure that matters here is what can actually be put on the van,
     * so availability is read per warehouse and reservations are subtracted.
     */
    public function lookup(Request $request): JsonResponse
    {
        $term = trim($request->string('q')->toString());
        $warehouseId = $request->integer('warehouse');

        if ($term === '') {
            return ApiResponse::success('', ['exact' => null, 'results' => []]);
        }

        $exact = Product::query()
            ->active()
            ->where(fn (Builder $q) => $q->where('barcode', $term)->orWhere('sku', $term))
            ->with('unit')
            ->first();

        $products = $exact
            ? collect([$exact])
            : Product::query()->active()->search($term)->with('unit')->orderBy('name')->limit(25)->get();

        $rows = $products->map(function (Product $product) use ($warehouseId) {
            $slot = ProductStock::query()
                ->where('product_id', $product->id)
                ->forWarehouse($warehouseId)
                ->selectRaw('COALESCE(SUM(quantity), 0) as on_hand')
                ->selectRaw('COALESCE(SUM(reserved), 0) as reserved')
                ->selectRaw('COALESCE(AVG(NULLIF(average_cost, 0)), 0) as cost')
                ->first();

            $onHand = (float) ($slot->on_hand ?? 0);

            return [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'barcode' => $product->barcode,
                'unit' => $product->unit?->code,
                'allow_decimal' => (bool) $product->unit?->allow_decimal,
                'selling_price' => $product->sellingPriceFor(),
                'average_cost' => (float) ($slot->cost ?? 0),
                'on_hand' => $onHand,
                'available' => $onHand - (float) ($slot->reserved ?? 0),
                'batches' => $product->track_batches
                    ? Batch::query()
                        ->where('product_id', $product->id)
                        ->fefo()
                        ->get(['id', 'batch_no', 'expiry_date'])
                        ->map(fn (Batch $batch) => [
                            'id' => $batch->id,
                            'label' => $batch->batch_no.' · '.$batch->expiryLabel(),
                        ])->all()
                    : [],
            ];
        })->all();

        return ApiResponse::success('', [
            'exact' => $exact ? $rows[0] : null,
            'results' => $rows,
        ]);
    }
}
