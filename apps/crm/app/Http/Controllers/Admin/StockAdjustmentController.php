<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Batch;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Shop;
use App\Models\StockAdjustment;
use App\Models\Warehouse;
use App\Services\StockAdjustmentService;
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
 * Stock adjustments, as reviewable documents.
 *
 * Full pages rather than modals: a document with line items needs room, and
 * it has to be linkable - "look at ADJ/MAIN/2026/00007" is a sentence people
 * say to each other.
 *
 * Nothing here moves stock. Approval does, through StockAdjustmentService.
 */
class StockAdjustmentController extends Controller
{
    private const PAGE_SIZES = [10, 15, 25, 50];

    public function __construct(private readonly StockAdjustmentService $service) {}

    /* -------------------------------------------------------------- list */

    public function index(Request $request): View
    {
        $perPage = (int) $request->integer('per_page', 15);
        $perPage = in_array($perPage, self::PAGE_SIZES, true) ? $perPage : 15;

        $adjustments = $this->filtered($request)
            ->with(['warehouse:id,name', 'shop:id,name,code'])
            ->withCount('items')
            ->paginate($perPage)
            ->withQueryString();

        $data = [
            'adjustments' => $adjustments,
            'search' => $request->string('q')->toString(),
            'status' => $request->string('status')->toString(),
            'from' => $request->string('from')->toString(),
            'to' => $request->string('to')->toString(),
            'perPage' => $perPage,
            'pageSizes' => self::PAGE_SIZES,
            'statuses' => StockAdjustment::STATUSES,
            'showsShop' => CurrentShop::id() === null,
            'stats' => [
                'total' => StockAdjustment::count(),
                'pending' => StockAdjustment::where('status', StockAdjustment::PENDING)->count(),
                'applied' => StockAdjustment::where('status', StockAdjustment::APPROVED)->count(),
                'value' => (float) StockAdjustment::where('status', StockAdjustment::APPROVED)
                    ->sum('value_change'),
            ],
        ];

        return $request->header('X-Fragment')
            ? view('admin.stock-adjustments._list', $data)
            : view('admin.stock-adjustments.index', $data);
    }

    private function filtered(Request $request): Builder
    {
        return StockAdjustment::query()
            ->search($request->string('q')->toString())
            ->ofStatus($request->string('status')->toString())
            ->between($request->string('from')->toString(), $request->string('to')->toString())
            ->orderByDesc('adjustment_date')
            ->orderByDesc('id');
    }

    /* ---------------------------------------------------------- document */

    public function create(): View|RedirectResponse
    {
        $shop = CurrentShop::get();

        if ($shop === null) {
            return redirect()
                ->route('admin.stock-adjustments.index')
                ->with('error', 'Choose a single shop before raising an adjustment — a stock count belongs to one warehouse.');
        }

        return view('admin.stock-adjustments.form', [
            'adjustment' => new StockAdjustment([
                'adjustment_date' => today()->toDateString(),
                'reason_code' => 'count',
            ]),
            'items' => collect(),
            'shop' => $shop,
            'warehouses' => Warehouse::active()->orderByDesc('is_default')->orderBy('name')->get(),
            'reference' => StockAdjustment::nextReference($shop),
        ]);
    }

    public function edit(StockAdjustment $adjustment): View|RedirectResponse
    {
        if (! $adjustment->isEditable()) {
            return redirect()
                ->route('admin.stock-adjustments.show', $adjustment)
                ->with('error', 'This adjustment has been '.strtolower($adjustment->statusLabel()).' and can no longer be edited.');
        }

        return view('admin.stock-adjustments.form', [
            'adjustment' => $adjustment,
            'items' => $adjustment->items()->with(['product.unit', 'batch'])->get(),
            'shop' => Shop::find($adjustment->shop_id),
            'warehouses' => Warehouse::active()->orderByDesc('is_default')->orderBy('name')->get(),
            'reference' => $adjustment->reference,
        ]);
    }

    public function show(StockAdjustment $adjustment): View
    {
        return view('admin.stock-adjustments.show', [
            'adjustment' => $adjustment->load(['warehouse', 'shop', 'creator', 'approver']),
            'items' => $adjustment->items()->with(['product.unit', 'batch'])->get(),
        ]);
    }

    /* ------------------------------------------------------------ writes */

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $shop = CurrentShop::get();

        if ($shop === null) {
            return ApiResponse::error('Choose a single shop before raising an adjustment.');
        }

        $adjustment = DB::transaction(function () use ($data, $shop, $request) {
            $user = Auth::user();

            $adjustment = new StockAdjustment([
                'shop_id' => $shop->id,
                'warehouse_id' => $data['warehouse_id'],
                'reference' => StockAdjustment::nextReference($shop),
                'adjustment_date' => $data['adjustment_date'],
                'status' => $this->requestedStatus($request),
                'reason_code' => $data['reason_code'],
                'reason' => $data['reason'] ?? null,
            ]);

            $adjustment->forceFill([
                'created_by' => $user?->id,
                'created_by_name' => $user?->name ?? 'System',
            ]);

            $adjustment->save();

            $this->syncItems($adjustment, $data['items']);

            return $adjustment;
        });

        ActivityLog::record(
            'stock_adjustment.created',
            "Raised stock adjustment {$adjustment->reference}",
            $adjustment,
        );

        return ApiResponse::success(
            "Adjustment {$adjustment->reference} saved.",
            ['id' => $adjustment->id, 'reference' => $adjustment->reference],
            route('admin.stock-adjustments.show', $adjustment),
        );
    }

    public function update(Request $request, StockAdjustment $adjustment): JsonResponse
    {
        if (! $adjustment->isEditable()) {
            return ApiResponse::error(
                'This adjustment has been '.strtolower($adjustment->statusLabel()).' and can no longer be edited.'
            );
        }

        $data = $this->validated($request, $adjustment);

        DB::transaction(function () use ($adjustment, $data, $request) {
            $adjustment->fill([
                'warehouse_id' => $data['warehouse_id'],
                'adjustment_date' => $data['adjustment_date'],
                'status' => $this->requestedStatus($request),
                'reason_code' => $data['reason_code'],
                'reason' => $data['reason'] ?? null,
            ])->save();

            // Replaced wholesale rather than diffed: a draft's lines have no
            // downstream references, and matching them up by hand would be a
            // lot of code to make the same thing happen.
            $adjustment->items()->delete();
            $this->syncItems($adjustment, $data['items']);
        });

        ActivityLog::record(
            'stock_adjustment.updated',
            "Updated stock adjustment {$adjustment->reference}",
            $adjustment,
        );

        return ApiResponse::success(
            "Adjustment {$adjustment->reference} saved.",
            [],
            route('admin.stock-adjustments.show', $adjustment),
        );
    }

    public function destroy(StockAdjustment $adjustment): JsonResponse
    {
        if ($adjustment->status === StockAdjustment::APPROVED) {
            return ApiResponse::error(
                'An applied adjustment cannot be deleted. Raise a correcting adjustment instead.'
            );
        }

        $reference = $adjustment->reference;
        $adjustment->delete();

        ActivityLog::record('stock_adjustment.deleted', "Deleted stock adjustment {$reference}");

        return ApiResponse::success(
            "Adjustment {$reference} deleted.",
            [],
            route('admin.stock-adjustments.index'),
        );
    }

    /* ---------------------------------------------------------- decisions */

    public function approve(Request $request, StockAdjustment $adjustment): JsonResponse
    {
        $note = $request->string('review_note')->toString();

        try {
            $this->service->approve($adjustment, $note ?: null);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage());
        }

        return ApiResponse::success(
            "Adjustment {$adjustment->reference} applied. Stock has been corrected.",
            [],
            route('admin.stock-adjustments.show', $adjustment),
        );
    }

    public function reject(Request $request, StockAdjustment $adjustment): JsonResponse
    {
        $note = $request->string('review_note')->toString();

        try {
            $this->service->reject($adjustment, $note ?: null);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage());
        }

        return ApiResponse::success(
            "Adjustment {$adjustment->reference} rejected.",
            [],
            route('admin.stock-adjustments.show', $adjustment),
        );
    }

    public function cancel(StockAdjustment $adjustment): JsonResponse
    {
        try {
            $this->service->cancel($adjustment);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage());
        }

        return ApiResponse::success(
            "Adjustment {$adjustment->reference} cancelled.",
            [],
            route('admin.stock-adjustments.show', $adjustment),
        );
    }

    /* ----------------------------------------------------------- helpers */

    /**
     * Whether the user pressed Save or Submit for approval.
     *
     * Two buttons on one form rather than two forms, because the lines are
     * the same either way and only the intent differs.
     */
    private function requestedStatus(Request $request): string
    {
        return $request->string('intent')->toString() === 'submit'
            ? StockAdjustment::PENDING
            : StockAdjustment::DRAFT;
    }

    /**
     * Write the document's lines.
     *
     * The system quantity is read here, from the real slot, rather than
     * trusted from the form: the browser's copy is a hint that was true when
     * the page loaded, and the difference has to be worked out against what
     * is actually on the shelf.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    private function syncItems(StockAdjustment $adjustment, array $items): void
    {
        foreach ($items as $line) {
            $productId = (int) $line['product_id'];
            $batchId = isset($line['batch_id']) && $line['batch_id'] !== '' ? (int) $line['batch_id'] : null;

            $slot = ProductStock::allShops()
                ->where('shop_id', $adjustment->shop_id)
                ->where('warehouse_id', $adjustment->warehouse_id)
                ->where('product_id', $productId)
                ->where('batch_id', $batchId)
                ->first();

            $system = (float) ($slot->quantity ?? 0);
            $counted = (float) $line['counted_quantity'];

            $adjustment->items()->create([
                'product_id' => $productId,
                'batch_id' => $batchId,
                'system_quantity' => $system,
                'counted_quantity' => $counted,
                'difference' => $counted - $system,
                'unit_cost' => $this->lineCost($slot, $productId, $batchId, $adjustment->shop_id),
                'note' => $line['note'] ?? null,
            ]);
        }
    }

    /**
     * What one unit of this line is worth.
     *
     * The slot's weighted average when there is one. When there is not -
     * which is the normal case for opening stock, where the slot has never
     * existed - fall back to the batch's cost and then the product's, so
     * the value of the correction is not silently zero.
     */
    private function lineCost(?ProductStock $slot, int $productId, ?int $batchId, int $shopId): float
    {
        $cost = (float) ($slot->average_cost ?? 0);

        if ($cost > 0) {
            return $cost;
        }

        if ($batchId !== null) {
            $batchCost = (float) (Batch::allShops()->whereKey($batchId)->value('purchase_price') ?? 0);

            if ($batchCost > 0) {
                return $batchCost;
            }
        }

        return (float) (Product::withTrashed()->find($productId)?->purchasePriceFor($shopId) ?? 0);
    }

    /* ------------------------------------------------------- validation */

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?StockAdjustment $adjustment = null): array
    {
        $shopId = $adjustment?->shop_id ?? CurrentShop::id();

        return $request->validate([
            'warehouse_id' => [
                'required', 'integer',
                Rule::exists('warehouses', 'id')->where('shop_id', $shopId),
            ],
            'adjustment_date' => ['required', 'date', 'before_or_equal:today'],
            'reason_code' => ['required', Rule::in(array_keys(StockAdjustment::REASONS))],
            'reason' => ['nullable', 'string', 'max:2000'],
            'intent' => ['nullable', Rule::in(['save', 'submit'])],

            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->whereNull('deleted_at')],
            'items.*.batch_id' => [
                'nullable', 'integer',
                Rule::exists('batches', 'id')->where('shop_id', $shopId),
            ],
            'items.*.counted_quantity' => ['required', 'numeric', 'between:0,99999999'],
            'items.*.note' => ['nullable', 'string', 'max:250'],
        ], [
            'warehouse_id.exists' => 'Choose a warehouse belonging to this shop.',
            'adjustment_date.before_or_equal' => 'A count cannot be dated in the future.',
            'items.required' => 'Add at least one product to count.',
            'items.min' => 'Add at least one product to count.',
            'items.*.batch_id.exists' => 'That batch does not belong to this shop.',
        ]);
    }

    /* ------------------------------------------------------------ lookup */

    /**
     * Products with their current quantity in one warehouse.
     *
     * A thin wrapper over the product lookup that swaps shop-wide stock for
     * the figure that actually matters on a count sheet: what this warehouse
     * is supposed to be holding.
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

        $rows = $products->map(fn (Product $product) => $this->lookupRow($product, $warehouseId))->all();

        return ApiResponse::success('', [
            'exact' => $exact ? $rows[0] : null,
            'results' => $rows,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function lookupRow(Product $product, ?int $warehouseId): array
    {
        $slot = ProductStock::query()
            ->where('product_id', $product->id)
            ->forWarehouse($warehouseId)
            ->selectRaw('COALESCE(SUM(quantity), 0) as on_hand')
            ->selectRaw('COALESCE(AVG(NULLIF(average_cost, 0)), 0) as cost')
            ->first();

        return [
            'id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'barcode' => $product->barcode,
            'unit' => $product->unit?->code,
            'allow_decimal' => (bool) $product->unit?->allow_decimal,
            'track_batches' => $product->track_batches,
            'selling_price' => $product->sellingPriceFor(),
            'average_cost' => (float) ($slot->cost ?? 0),
            'on_hand' => (float) ($slot->on_hand ?? 0),
            'available' => (float) ($slot->on_hand ?? 0),
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
    }
}
