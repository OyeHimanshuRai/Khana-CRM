<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Batch;
use App\Models\Product;
use App\Models\ProductStock;
use App\Support\ApiResponse;
use App\Support\CurrentShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Batches and expiry.
 *
 * Batches are normally created by a goods receipt rather than by hand, so
 * this screen is mostly a register: what is held, what is close to its date,
 * what has passed it. Creating one manually is still allowed - opening stock
 * has to come from somewhere - but the form says as much.
 */
class BatchController extends Controller
{
    private const PAGE_SIZES = [15, 25, 50, 100];

    /* -------------------------------------------------------------- list */

    public function index(Request $request): View
    {
        $perPage = (int) $request->integer('per_page', 25);
        $perPage = in_array($perPage, self::PAGE_SIZES, true) ? $perPage : 25;

        $batches = $this->filtered($request)
            ->with(['product:id,name,sku,unit_id', 'product.unit:id,code', 'shop:id,name,code'])
            ->withSum('stocks as on_hand', 'quantity')
            ->paginate($perPage)
            ->withQueryString();

        $data = [
            'batches' => $batches,
            'search' => $request->string('q')->toString(),
            'expiry' => $request->string('expiry')->toString(),
            'stocked' => $request->string('stocked')->toString(),
            'sort' => $request->string('sort')->toString() ?: 'expiry',
            'perPage' => $perPage,
            'pageSizes' => self::PAGE_SIZES,
            'showsShop' => CurrentShop::id() === null,
            'nearDays' => Batch::NEAR_EXPIRY_DAYS,
            'stats' => [
                'total' => Batch::count(),
                'expired' => Batch::query()->expired()->count(),
                'near' => Batch::query()->nearExpiry()->count(),
                'value_at_risk' => (float) ProductStock::query()
                    ->whereHas('batch', fn ($b) => $b->nearExpiry())
                    ->selectRaw('COALESCE(SUM(quantity * average_cost), 0) as total')
                    ->value('total'),
            ],
        ];

        return $request->header('X-Fragment')
            ? view('admin.batches._list', $data)
            : view('admin.batches.index', $data);
    }

    private function filtered(Request $request): Builder
    {
        return Batch::query()
            ->search($request->string('q')->toString())
            ->when($request->string('expiry')->toString(), function (Builder $query, string $filter) {
                match ($filter) {
                    'expired' => $query->expired(),
                    'near' => $query->nearExpiry(),
                    'ok' => $query->where(fn (Builder $q) => $q
                        ->whereNull('expiry_date')
                        ->orWhereDate('expiry_date', '>', today()->addDays(Batch::NEAR_EXPIRY_DAYS))),
                    'undated' => $query->whereNull('expiry_date'),
                    default => null,
                };
            })
            ->when($request->string('stocked')->toString(), function (Builder $query, string $filter) {
                match ($filter) {
                    // "Held" means there is quantity somewhere, which is what
                    // decides whether an expiry actually costs anything.
                    'held' => $query->whereHas('stocks', fn ($s) => $s->where('quantity', '>', 0)),
                    'empty' => $query->whereDoesntHave('stocks', fn ($s) => $s->where('quantity', '>', 0)),
                    default => null,
                };
            })
            ->tap(function (Builder $query) use ($request) {
                match ($request->string('sort')->toString()) {
                    'newest' => $query->latest('id'),
                    'batch' => $query->orderBy('batch_no'),
                    'product' => $query->orderBy(
                        Product::select('name')->whereColumn('products.id', 'batches.product_id')
                    ),
                    default => $query->fefo(),
                };
            });
    }

    /* ------------------------------------------------------ modal screens */

    public function create(): View
    {
        return view('admin.batches._form', [
            'batch' => new Batch(),
            'products' => $this->batchedProducts(),
        ]);
    }

    public function edit(Batch $batch): View
    {
        return view('admin.batches._form', [
            'batch' => $batch,
            'products' => $this->batchedProducts(),
        ]);
    }

    public function show(Batch $batch): View
    {
        return view('admin.batches._show', [
            'batch' => $batch->load(['product.unit', 'shop']),
            'stockRows' => ProductStock::with('warehouse:id,name')
                ->where('batch_id', $batch->id)
                ->get(),
        ]);
    }

    private function batchedProducts()
    {
        return Product::query()
            ->active()
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'track_batches']);
    }

    /* ------------------------------------------------------------ writes */

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $batch = Batch::create($this->attributes($data) + [
            'shop_id' => CurrentShop::idForWrite(),
        ]);

        ActivityLog::record(
            'batch.created',
            "Created batch \"{$batch->batch_no}\" for ".($batch->product?->name ?? 'a product'),
            $batch,
        );

        return ApiResponse::success("Batch \"{$batch->batch_no}\" created.", $this->payload($batch));
    }

    public function update(Request $request, Batch $batch): JsonResponse
    {
        $data = $this->validated($request, $batch);

        // The product is fixed: stock rows and movements already point at
        // this lot, and re-pointing it would move quantities between
        // products without a single ledger entry saying so.
        $attributes = $this->attributes($data);
        unset($attributes['product_id']);

        $batch->fill($attributes)->save();

        ActivityLog::record('batch.updated', "Updated batch \"{$batch->batch_no}\"", $batch);

        return ApiResponse::success("Batch \"{$batch->batch_no}\" updated.", $this->payload($batch));
    }

    /**
     * Remove a batch.
     *
     * Refused while it still holds stock: the quantity would have nowhere to
     * go, and cascading it away would silently destroy stock the shop owns.
     */
    public function destroy(Batch $batch): JsonResponse
    {
        $held = (float) ProductStock::where('batch_id', $batch->id)->sum('quantity');

        if (abs($held) > 0.0005) {
            return ApiResponse::error(sprintf(
                'Batch "%s" still holds %s. Sell, transfer or adjust it out first.',
                $batch->batch_no,
                rtrim(rtrim(number_format($held, 3, '.', ''), '0'), '.'),
            ));
        }

        $number = $batch->batch_no;
        $batch->delete();

        ActivityLog::record('batch.deleted', "Deleted batch \"{$number}\"");

        return ApiResponse::success("Batch \"{$number}\" deleted.");
    }

    /**
     * Stop a lot being sold without deleting it.
     *
     * The usual reason is a recall or a failed quality check - the stock is
     * still physically there and still has to be counted, it simply may not
     * leave the counter.
     */
    public function toggleStatus(Batch $batch): JsonResponse
    {
        $active = ! $batch->is_active;

        $batch->forceFill(['is_active' => $active])->save();

        ActivityLog::record(
            $active ? 'batch.released' : 'batch.blocked',
            ($active ? 'Released' : 'Blocked')." batch \"{$batch->batch_no}\" for sale",
            $batch,
        );

        return ApiResponse::success(
            $active
                ? "Batch \"{$batch->batch_no}\" can be sold again."
                : "Batch \"{$batch->batch_no}\" is blocked from sale. Its stock still counts.",
            ['is_active' => $active],
        );
    }

    /* ------------------------------------------------------------ export */

    public function export(Request $request): StreamedResponse
    {
        $filename = 'batches-'.now()->format('Y-m-d-His').'.csv';

        $columns = [
            'Shop', 'SKU', 'Product', 'Batch', 'Mfg Date', 'Expiry', 'Days Left',
            'On Hand', 'Purchase Price', 'MRP', 'Selling Price', 'Status',
        ];

        $query = $this->filtered($request)
            ->with(['product', 'shop'])
            ->withSum('stocks as on_hand', 'quantity');

        ActivityLog::record('batch.exported', 'Exported the batch register');

        return response()->streamDownload(function () use ($query, $columns) {
            $handle = fopen('php://output', 'wb');

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $columns);

            $query->chunkById(500, function ($chunk) use ($handle) {
                foreach ($chunk as $batch) {
                    fputcsv($handle, [
                        $batch->shop?->name,
                        $batch->product?->sku,
                        $batch->product?->name,
                        $batch->batch_no,
                        $batch->mfg_date?->format('Y-m-d'),
                        $batch->expiry_date?->format('Y-m-d'),
                        $batch->daysToExpiry(),
                        number_format((float) ($batch->on_hand ?? 0), 3, '.', ''),
                        number_format((float) $batch->purchase_price, 2, '.', ''),
                        number_format((float) $batch->mrp, 2, '.', ''),
                        number_format((float) $batch->selling_price, 2, '.', ''),
                        $batch->is_active ? 'Sellable' : 'Blocked',
                    ]);
                }
            });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /* ------------------------------------------------------- validation */

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Batch $batch = null): array
    {
        $shopId = $batch?->shop_id ?? CurrentShop::idForWrite();
        $productId = $batch?->product_id ?? $request->integer('product_id');

        return $request->validate([
            'product_id' => [$batch ? 'nullable' : 'required', 'integer', 'exists:products,id'],

            'batch_no' => [
                'required', 'string', 'max:80',
                Rule::unique('batches', 'batch_no')
                    ->where('shop_id', $shopId)
                    ->where('product_id', $productId)
                    ->ignore($batch?->id),
            ],

            'mfg_date' => ['nullable', 'date', 'before_or_equal:today'],
            // Not required: plenty of hardware has no date at all.
            'expiry_date' => ['nullable', 'date', 'after:mfg_date'],

            'purchase_price' => ['nullable', 'numeric', 'between:0,99999999999'],
            'mrp' => ['nullable', 'numeric', 'between:0,99999999999'],
            'selling_price' => ['nullable', 'numeric', 'between:0,99999999999'],

            'supplier_batch_ref' => ['nullable', 'string', 'max:80'],
            'is_active' => ['boolean'],
        ], [
            'batch_no.unique' => 'This product already has a batch with that number in this shop.',
            'expiry_date.after' => 'The expiry date has to be after the manufacturing date.',
            'mfg_date.before_or_equal' => 'A manufacturing date cannot be in the future.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        return [
            'product_id' => $data['product_id'] ?? null,
            'batch_no' => $data['batch_no'],
            'mfg_date' => $data['mfg_date'] ?? null,
            'expiry_date' => $data['expiry_date'] ?? null,
            'purchase_price' => (float) ($data['purchase_price'] ?? 0),
            'mrp' => (float) ($data['mrp'] ?? 0),
            'selling_price' => (float) ($data['selling_price'] ?? 0),
            'supplier_batch_ref' => $data['supplier_batch_ref'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Batch $batch): array
    {
        return [
            'id' => $batch->id,
            'batch_no' => $batch->batch_no,
            'expiry' => $batch->expiry_date?->toDateString(),
            'is_active' => $batch->is_active,
        ];
    }
}
