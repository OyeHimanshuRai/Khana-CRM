<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Support\CurrentShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Stock on hand, and the ledger behind it.
 *
 * Read-only by design. Nothing on this screen can change a quantity: every
 * change belongs to a document - a goods receipt, an adjustment, a sale -
 * and going through one is what leaves the explanation behind.
 */
class StockController extends Controller
{
    private const PAGE_SIZES = [15, 25, 50, 100, 200];

    /* ---------------------------------------------------------- on hand */

    public function index(Request $request): View
    {
        $perPage = (int) $request->integer('per_page', 25);
        $perPage = in_array($perPage, self::PAGE_SIZES, true) ? $perPage : 25;

        $stocks = $this->filtered($request)
            ->with([
                'product:id,name,sku,barcode,unit_id,reorder_level,track_batches',
                'product.unit:id,code,name,allow_decimal,precision',
                'warehouse:id,name,code,shop_id',
                'batch:id,batch_no,expiry_date',
                'shop:id,name,code',
            ])
            ->paginate($perPage)
            ->withQueryString();

        $data = [
            'stocks' => $stocks,
            'search' => $request->string('q')->toString(),
            'warehouseId' => $request->integer('warehouse'),
            'level' => $request->string('level')->toString(),
            'expiry' => $request->string('expiry')->toString(),
            'sort' => $request->string('sort')->toString() ?: 'product',
            'perPage' => $perPage,
            'pageSizes' => self::PAGE_SIZES,
            'warehouses' => Warehouse::active()->orderBy('name')->get(['id', 'name', 'code']),
            'showsShop' => CurrentShop::id() === null,
            'stats' => $this->stats(),
        ];

        return $request->header('X-Fragment')
            ? view('admin.stock._list', $data)
            : view('admin.stock.index', $data);
    }

    /**
     * @return array<string, mixed>
     */
    private function stats(): array
    {
        $base = fn () => ProductStock::query();

        return [
            'lines' => $base()->where('quantity', '!=', 0)->count(),
            'value' => (float) $base()
                ->selectRaw('COALESCE(SUM(quantity * average_cost), 0) as total')
                ->value('total'),
            'reserved' => (float) $base()->sum('reserved'),
            // Distinct products at or under their reorder level, in this shop.
            'low' => Product::query()
                ->where('reorder_level', '>', 0)
                ->whereRaw(
                    '(SELECT COALESCE(SUM(quantity), 0) FROM product_stocks
                        WHERE product_stocks.product_id = products.id
                        AND product_stocks.shop_id IN ('.$this->shopIdList().')
                     ) <= products.reorder_level'
                )
                ->count(),
        ];
    }

    /**
     * The shop ids the current reader may see, as a SQL list.
     *
     * Built from ints that came out of the pivot, never from request input,
     * so interpolating it into the raw sub-query above is safe.
     */
    private function shopIdList(): string
    {
        $ids = CurrentShop::id() !== null
            ? [CurrentShop::id()]
            : (CurrentShop::accessibleIds() ?: [0]);

        return implode(',', array_map('intval', $ids));
    }

    private function filtered(Request $request): Builder
    {
        return ProductStock::query()
            ->when($request->string('q')->toString(), function (Builder $query, string $term) {
                $query->whereHas('product', fn (Builder $p) => $p
                    ->where('name', 'like', "%{$term}%")
                    ->orWhere('sku', 'like', "%{$term}%")
                    ->orWhere('barcode', 'like', "%{$term}%"));
            })
            ->when($request->integer('warehouse'), fn (Builder $q, int $id) => $q->where('warehouse_id', $id))
            ->when($request->string('level')->toString(), function (Builder $query, string $level) {
                match ($level) {
                    'in' => $query->where('quantity', '>', 0),
                    'zero' => $query->where('quantity', '=', 0),
                    'negative' => $query->where('quantity', '<', 0),
                    'reserved' => $query->where('reserved', '>', 0),
                    default => null,
                };
            })
            ->when($request->string('expiry')->toString(), function (Builder $query, string $expiry) {
                match ($expiry) {
                    'expired' => $query->whereHas('batch', fn ($b) => $b->expired()),
                    'near' => $query->whereHas('batch', fn ($b) => $b->nearExpiry()),
                    'batched' => $query->whereNotNull('batch_id'),
                    default => null,
                };
            })
            ->tap(function (Builder $query) use ($request) {
                match ($request->string('sort')->toString()) {
                    'quantity_desc' => $query->orderByDesc('quantity'),
                    'quantity_asc' => $query->orderBy('quantity'),
                    'value_desc' => $query->orderByRaw('quantity * average_cost DESC'),
                    'expiry' => $query->orderByRaw(
                        '(SELECT expiry_date FROM batches WHERE batches.id = product_stocks.batch_id) IS NULL,
                         (SELECT expiry_date FROM batches WHERE batches.id = product_stocks.batch_id) ASC'
                    ),
                    default => $query->orderBy(
                        Product::select('name')->whereColumn('products.id', 'product_stocks.product_id')
                    ),
                };
            });
    }

    /* ---------------------------------------------------------- movements */

    /**
     * The ledger for one product, newest first.
     *
     * Opened from the stock list, so it answers a fragment for the modal.
     */
    public function movements(Request $request, Product $product): View
    {
        $movements = StockMovement::query()
            ->with(['warehouse:id,name', 'batch:id,batch_no', 'shop:id,name,code'])
            ->where('product_id', $product->id)
            ->ofType($request->string('type')->toString())
            ->latest('id')
            ->limit(200)
            ->get();

        return view('admin.stock._movements', [
            'product' => $product->load('unit'),
            'movements' => $movements,
            'type' => $request->string('type')->toString(),
            'types' => StockMovement::TYPES,
            'onHand' => $product->stockOnHand(),
        ]);
    }

    /* ------------------------------------------------------------ export */

    public function export(Request $request): StreamedResponse
    {
        $filename = 'stock-on-hand-'.now()->format('Y-m-d-His').'.csv';

        $columns = [
            'Shop', 'Warehouse', 'SKU', 'Product', 'Batch', 'Expiry',
            'Unit', 'On Hand', 'Reserved', 'Available', 'Avg Cost', 'Value',
        ];

        $query = $this->filtered($request)->with(['product.unit', 'warehouse', 'batch', 'shop']);

        ActivityLog::record('stock.exported', 'Exported stock on hand');

        return response()->streamDownload(function () use ($query, $columns) {
            $handle = fopen('php://output', 'wb');

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $columns);

            $query->chunkById(500, function ($chunk) use ($handle) {
                foreach ($chunk as $row) {
                    fputcsv($handle, [
                        $row->shop?->name,
                        $row->warehouse?->name,
                        $row->product?->sku,
                        $row->product?->name,
                        $row->batch?->batch_no,
                        $row->batch?->expiry_date?->format('Y-m-d'),
                        $row->product?->unit?->code,
                        number_format((float) $row->quantity, 3, '.', ''),
                        number_format((float) $row->reserved, 3, '.', ''),
                        number_format($row->available(), 3, '.', ''),
                        number_format((float) $row->average_cost, 4, '.', ''),
                        number_format($row->value(), 2, '.', ''),
                    ]);
                }
            });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
