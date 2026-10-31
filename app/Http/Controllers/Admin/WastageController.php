<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\StockWastage;
use App\Models\Warehouse;
use App\Services\WastageService;
use App\Support\ApiResponse;
use App\Support\CurrentShop;
use App\Support\ReportFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * What the kitchen threw away (§10).
 *
 * The screen is built around the value rather than the quantity. "Four kilos
 * of paneer" is a fact; "₹1,360 this week, most of it spoiled" is the sentence
 * that makes somebody change how much paneer they order.
 */
class WastageController extends Controller
{
    private const PAGE_SIZES = [10, 15, 25, 50, 100];

    public function __construct(private readonly WastageService $wastage) {}

    public function index(Request $request): View|RedirectResponse
    {
        if (CurrentShop::id() === null) {
            return redirect()
                ->route('admin.dashboard')
                ->with('error', 'Choose a single shop — stock is thrown away in one kitchen.');
        }

        $range = ReportFilters::fromRequest($request);

        $perPage = (int) $request->integer('per_page', 25);
        $perPage = in_array($perPage, self::PAGE_SIZES, true) ? $perPage : 25;

        $entries = StockWastage::query()
            ->between($range->from, $range->to)
            ->search($request->string('q')->toString())
            ->ofReason($request->string('reason')->toString())
            ->with(['product:id,name,sku,unit_id', 'product.unit:id,code', 'warehouse:id,name'])
            ->orderByDesc('wasted_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        $data = [
            'entries' => $entries,
            'range' => $range,
            'presets' => ReportFilters::PRESETS,
            'search' => $request->string('q')->toString(),
            'reason' => $request->string('reason')->toString(),
            'reasons' => StockWastage::REASONS,
            'perPage' => $perPage,
            'pageSizes' => self::PAGE_SIZES,
            'summary' => $this->wastage->summary($range->from, $range->to),
        ];

        return $request->header('X-Fragment')
            ? view('admin.wastage._list', $data)
            : view('admin.wastage.index', $data);
    }

    /* ------------------------------------------------------ modal screens */

    public function create(): View
    {
        return view('admin.wastage._form', [
            'products' => $this->wastage->writableOff()
                ->with('unit:id,code')
                ->get(['id', 'name', 'sku', 'unit_id', 'is_ingredient']),
            'warehouses' => Warehouse::active()->orderByDesc('is_default')->orderBy('name')->get(),
            'reasons' => StockWastage::REASONS,
        ]);
    }

    /* ------------------------------------------------------------- writes */

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'quantity' => ['required', 'numeric', 'min:0.0001', 'max:1000000'],
            'reason_code' => ['required', Rule::in(array_keys(StockWastage::REASONS))],
            'warehouse_id' => ['nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:250'],
        ], [
            'quantity.min' => 'Say how much was thrown away.',
            'reason_code.required' => 'Say why it was thrown away.',
        ]);

        $product = Product::query()->find($data['product_id']);

        if ($product === null) {
            return ApiResponse::error('That item is not available in this shop.', [], 404);
        }

        try {
            $wastage = $this->wastage->record([
                'product' => $product,
                'quantity' => (float) $data['quantity'],
                'reason_code' => $data['reason_code'],
                'note' => $data['note'] ?? null,
                'warehouse' => isset($data['warehouse_id'])
                    ? Warehouse::find($data['warehouse_id'])
                    : null,
            ]);
        } catch (RuntimeException $e) {
            // The service's refusals are written for whoever is holding the
            // empty tray.
            return ApiResponse::error($e->getMessage());
        }

        return ApiResponse::success(
            sprintf(
                '%s of %s written off — ₹%s.',
                $wastage->label(),
                $product->name,
                number_format((float) $wastage->cost_value, 2),
            ),
            ['id' => $wastage->id, 'value' => (float) $wastage->cost_value],
        );
    }

    /**
     * Put it back.
     *
     * A reversal rather than a delete at the ledger level - the stock returns
     * through a fresh movement, so "why is there 3 kg less than yesterday"
     * stays answerable even when yesterday was a mistake.
     */
    public function destroy(Request $request, StockWastage $wastage): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:190'],
        ]);

        $name = $wastage->product?->name ?? 'that item';
        $label = $wastage->label();

        try {
            $this->wastage->reverse($wastage, $data['reason'] ?? null);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage());
        }

        return ApiResponse::success(sprintf('%s of %s returned to stock.', $label, $name));
    }

    /* ------------------------------------------------------------ export */

    public function export(Request $request): StreamedResponse
    {
        $range = ReportFilters::fromRequest($request);

        $query = StockWastage::query()
            ->between($range->from, $range->to)
            ->ofReason($request->string('reason')->toString())
            ->with(['product:id,name,sku,unit_id', 'product.unit:id,code'])
            ->orderBy('wasted_at');

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'wb');
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, ['Date', 'Item', 'SKU', 'Quantity', 'Unit', 'Reason', 'Value', 'Note', 'Recorded by']);

            $query->chunkById(500, function ($chunk) use ($handle) {
                foreach ($chunk as $row) {
                    fputcsv($handle, [
                        $row->wasted_at?->format('Y-m-d H:i'),
                        $row->product?->name,
                        $row->product?->sku,
                        rtrim(rtrim(number_format((float) $row->quantity, 4, '.', ''), '0'), '.'),
                        $row->product?->unit?->code,
                        $row->reasonLabel(),
                        number_format((float) $row->cost_value, 2, '.', ''),
                        $row->note,
                        $row->recorded_by_name,
                    ]);
                }
            });

            fclose($handle);
        }, 'wastage-'.now()->format('Y-m-d').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
