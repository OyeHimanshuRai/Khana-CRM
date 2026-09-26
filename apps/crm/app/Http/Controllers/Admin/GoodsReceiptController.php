<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\GoodsReceipt;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Shop;
use App\Models\Supplier;
use App\Models\TaxRate;
use App\Models\Warehouse;
use App\Services\PurchaseService;
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
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Goods receipts - what arrived, and what the supplier billed for it.
 *
 * A draft can be edited freely; posting is the point of no return, because
 * that is when stock lands and the supplier is billed. There is no edit
 * after posting: a mistake is cancelled and re-entered, which leaves both
 * on the record.
 */
class GoodsReceiptController extends Controller
{
    private const PAGE_SIZES = [10, 15, 25, 50];

    public function __construct(private readonly PurchaseService $purchases) {}

    /* -------------------------------------------------------------- list */

    public function index(Request $request): View
    {
        $perPage = (int) $request->integer('per_page', 15);
        $perPage = in_array($perPage, self::PAGE_SIZES, true) ? $perPage : 15;

        $receipts = $this->filtered($request)
            ->with(['supplier:id,name,company', 'warehouse:id,name', 'shop:id,name'])
            ->withCount('items')
            ->paginate($perPage)
            ->withQueryString();

        $data = [
            'receipts' => $receipts,
            'search' => $request->string('q')->toString(),
            'status' => $request->string('status')->toString(),
            'settlement' => $request->string('settlement')->toString(),
            'from' => $request->string('from')->toString(),
            'to' => $request->string('to')->toString(),
            'perPage' => $perPage,
            'pageSizes' => self::PAGE_SIZES,
            'statuses' => GoodsReceipt::STATUSES,
            'showsShop' => CurrentShop::id() === null,
            'stats' => [
                'count' => $this->filtered($request)->counted()->count(),
                'value' => (float) $this->filtered($request)->counted()->sum('grand_total'),
                'payable' => (float) $this->filtered($request)->counted()->sum('due_total'),
                'drafts' => GoodsReceipt::where('status', GoodsReceipt::DRAFT)->count(),
            ],
        ];

        return $request->header('X-Fragment')
            ? view('admin.receipts._list', $data)
            : view('admin.receipts.index', $data);
    }

    private function filtered(Request $request): Builder
    {
        return GoodsReceipt::query()
            ->search($request->string('q')->toString())
            ->ofStatus($request->string('status')->toString())
            ->between($request->string('from')->toString(), $request->string('to')->toString())
            ->when($request->string('settlement')->toString(), function (Builder $query, string $filter) {
                match ($filter) {
                    'unpaid' => $query->unpaid(),
                    'settled' => $query->counted()->where('due_total', '<=', 0),
                    default => null,
                };
            })
            ->orderByDesc('received_on')
            ->orderByDesc('id');
    }

    /* ---------------------------------------------------------- document */

    public function create(Request $request): View|RedirectResponse
    {
        $shop = CurrentShop::get();

        if ($shop === null) {
            return redirect()
                ->route('admin.receipts.index')
                ->with('error', 'Choose a single shop before receiving goods — a consignment arrives at one warehouse.');
        }

        // Receiving against an order pre-fills the lines with what is still
        // outstanding, which is the whole point of having ordered.
        $order = $request->integer('order')
            ? PurchaseOrder::with('items.product.unit')->find($request->integer('order'))
            : null;

        return view('admin.receipts.form', [
            'receipt' => new GoodsReceipt([
                'received_on' => today()->toDateString(),
                'supplier_id' => $order?->supplier_id,
                'warehouse_id' => $order?->warehouse_id,
            ]),
            'items' => collect(),
            'order' => $order,
            'orderLines' => $order
                ? $order->items->filter(fn ($line) => ! $line->isFullyReceived())->values()
                : collect(),
            'shop' => $shop,
            'suppliers' => Supplier::active()->orderBy('name')->get(['id', 'name', 'company', 'state_code', 'credit_days']),
            'warehouses' => Warehouse::active()->orderByDesc('is_default')->orderBy('name')->get(),
            'taxRates' => TaxRate::where('is_active', true)->orderBy('rate')->get(),
            'reference' => GoodsReceipt::nextReference($shop),
        ]);
    }

    public function edit(GoodsReceipt $receipt): View|RedirectResponse
    {
        if (! $receipt->isEditable()) {
            return redirect()
                ->route('admin.receipts.show', $receipt)
                ->with('error', 'This receipt has been posted. Cancel it and enter a new one to correct it.');
        }

        return view('admin.receipts.form', [
            'receipt' => $receipt,
            'items' => $receipt->items()->with('product.unit')->get(),
            'order' => $receipt->purchaseOrder,
            'orderLines' => collect(),
            'shop' => Shop::find($receipt->shop_id),
            'suppliers' => Supplier::active()->orderBy('name')->get(['id', 'name', 'company', 'state_code', 'credit_days']),
            'warehouses' => Warehouse::active()->orderByDesc('is_default')->orderBy('name')->get(),
            'taxRates' => TaxRate::where('is_active', true)->orderBy('rate')->get(),
            'reference' => $receipt->reference,
        ]);
    }

    public function show(GoodsReceipt $receipt): View
    {
        return view('admin.receipts.show', [
            'receipt' => $receipt->load(['supplier', 'warehouse', 'shop', 'purchaseOrder']),
            'items' => $receipt->items()->with(['product:id,name,sku', 'batch:id,batch_no,expiry_date'])->get(),
            'payments' => $receipt->payments()->get(),
        ]);
    }

    /* ------------------------------------------------------------ writes */

    public function store(Request $request): JsonResponse
    {
        $shop = CurrentShop::get();

        if ($shop === null) {
            return ApiResponse::error('Choose a single shop before receiving goods.');
        }

        $data = $this->validated($request);

        try {
            $receipt = DB::transaction(function () use ($data, $shop, $request) {
                $user = Auth::user();

                $receipt = new GoodsReceipt($this->attributes($data) + [
                    'shop_id' => $shop->id,
                    'reference' => GoodsReceipt::nextReference($shop),
                    'status' => GoodsReceipt::DRAFT,
                ]);

                $receipt->forceFill([
                    'created_by' => $user?->id,
                    'created_by_name' => $user?->name ?? 'System',
                ]);

                $receipt->save();

                $this->syncItems($receipt, $data['items']);

                if ($this->wantsPosting($request)) {
                    $this->purchases->post($receipt);
                }

                return $receipt;
            });
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage());
        }

        ActivityLog::record(
            'goods_receipt.created',
            "Entered goods receipt {$receipt->reference}",
            $receipt,
        );

        return ApiResponse::success(
            $receipt->isPosted()
                ? "Receipt {$receipt->reference} posted. The stock is on the shelf."
                : "Receipt {$receipt->reference} saved as a draft. Nothing has moved yet.",
            ['id' => $receipt->id, 'reference' => $receipt->reference],
            route('admin.receipts.show', $receipt),
        );
    }

    public function update(Request $request, GoodsReceipt $receipt): JsonResponse
    {
        if (! $receipt->isEditable()) {
            return ApiResponse::error(
                'This receipt has been posted. Cancel it and enter a new one to correct it.'
            );
        }

        $data = $this->validated($request, $receipt);

        try {
            DB::transaction(function () use ($receipt, $data, $request) {
                $attributes = $this->attributes($data);
                // The shop is fixed; everything else on a draft is fair game.
                unset($attributes['shop_id']);

                $receipt->fill($attributes)->save();

                $receipt->items()->delete();
                $this->syncItems($receipt, $data['items']);

                if ($this->wantsPosting($request)) {
                    $this->purchases->post($receipt);
                }
            });
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage());
        }

        return ApiResponse::success(
            $receipt->fresh()->isPosted()
                ? "Receipt {$receipt->reference} posted. The stock is on the shelf."
                : "Receipt {$receipt->reference} saved.",
            [],
            route('admin.receipts.show', $receipt),
        );
    }

    public function destroy(GoodsReceipt $receipt): JsonResponse
    {
        if (! $receipt->isEditable()) {
            return ApiResponse::error('Only a draft receipt can be deleted. Cancel a posted one instead.');
        }

        $reference = $receipt->reference;
        $receipt->delete();

        ActivityLog::record('goods_receipt.deleted', "Deleted draft receipt {$reference}");

        return ApiResponse::success("Draft {$reference} deleted.", [], route('admin.receipts.index'));
    }

    /* --------------------------------------------------------- lifecycle */

    /** Put a draft's stock on the shelf and bill the supplier. */
    public function post(GoodsReceipt $receipt): JsonResponse
    {
        try {
            $this->purchases->post($receipt);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage());
        }

        return ApiResponse::success(
            "Receipt {$receipt->reference} posted. The stock is on the shelf and the supplier is billed.",
            [],
            route('admin.receipts.show', $receipt),
        );
    }

    public function cancel(Request $request, GoodsReceipt $receipt): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:250'],
        ], [
            'reason.required' => 'Say why this receipt is being cancelled.',
        ]);

        try {
            $this->purchases->cancel($receipt, $data['reason']);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage());
        }

        return ApiResponse::success(
            "Receipt {$receipt->reference} cancelled. The stock is back off the shelf.",
            [],
            route('admin.receipts.show', $receipt),
        );
    }

    public function cancelForm(GoodsReceipt $receipt): View
    {
        return view('admin.receipts._cancel', ['receipt' => $receipt]);
    }

    /* ----------------------------------------------------------- helpers */

    private function wantsPosting(Request $request): bool
    {
        return $request->string('intent')->toString() === 'post';
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function syncItems(GoodsReceipt $receipt, array $items): void
    {
        foreach ($items as $line) {
            $product = Product::with('unit')->findOrFail((int) $line['product_id']);

            $receipt->items()->create([
                'product_id' => $product->id,
                'product_name' => $product->name,
                'sku' => $product->sku,
                'unit_code' => $product->unit?->code,
                'batch_no' => $line['batch_no'] ?? null,
                'mfg_date' => $line['mfg_date'] ?? null,
                'expiry_date' => $line['expiry_date'] ?? null,
                'quantity' => (float) $line['quantity'],
                'free_quantity' => (float) ($line['free_quantity'] ?? 0),
                'unit_cost' => (float) $line['unit_cost'],
                'discount_percent' => (float) ($line['discount_percent'] ?? 0),
                'tax_rate' => (float) ($line['tax_rate'] ?? 0),
                'mrp' => (float) ($line['mrp'] ?? 0),
                'selling_price' => (float) ($line['selling_price'] ?? 0),
                'note' => $line['note'] ?? null,
            ]);
        }
    }

    /* ------------------------------------------------------------ export */

    public function export(Request $request): StreamedResponse
    {
        $filename = 'goods-receipts-'.now()->format('Y-m-d-His').'.csv';

        $columns = [
            'Reference', 'Received', 'Shop', 'Supplier', 'Bill number', 'Bill date',
            'Sub-total', 'Tax', 'Charges', 'Total', 'Paid', 'Due', 'Due date', 'Status',
        ];

        $query = $this->filtered($request)->with(['supplier', 'shop']);

        ActivityLog::record('goods_receipt.exported', 'Exported the goods receipt register');

        return response()->streamDownload(function () use ($query, $columns) {
            $handle = fopen('php://output', 'wb');

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $columns);

            $query->chunkById(300, function ($chunk) use ($handle) {
                foreach ($chunk as $receipt) {
                    fputcsv($handle, [
                        $receipt->reference,
                        $receipt->received_on?->format('Y-m-d'),
                        $receipt->shop?->name,
                        $receipt->supplier?->displayName(),
                        $receipt->bill_number,
                        $receipt->bill_date?->format('Y-m-d'),
                        number_format((float) $receipt->subtotal, 2, '.', ''),
                        number_format((float) $receipt->tax_total, 2, '.', ''),
                        number_format((float) $receipt->other_charges, 2, '.', ''),
                        number_format((float) $receipt->grand_total, 2, '.', ''),
                        number_format((float) $receipt->paid_total, 2, '.', ''),
                        number_format((float) $receipt->due_total, 2, '.', ''),
                        $receipt->due_date?->format('Y-m-d'),
                        $receipt->statusLabel(),
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
    private function validated(Request $request, ?GoodsReceipt $receipt = null): array
    {
        $shopId = $receipt?->shop_id ?? CurrentShop::id();

        return $request->validate([
            'supplier_id' => [
                'required', 'integer',
                Rule::exists('suppliers', 'id')->where('shop_id', $shopId)->whereNull('deleted_at'),
            ],
            'warehouse_id' => [
                'required', 'integer',
                Rule::exists('warehouses', 'id')->where('shop_id', $shopId),
            ],
            'purchase_order_id' => [
                'nullable', 'integer',
                Rule::exists('purchase_orders', 'id')->where('shop_id', $shopId),
            ],

            'received_on' => ['required', 'date', 'before_or_equal:today'],

            /*
             | Unique per supplier: the same bill number arriving twice is
             | nearly always a duplicate entry rather than a real second
             | invoice, and catching it here saves a reconciliation later.
             */
            'bill_number' => [
                'nullable', 'string', 'max:60',
                Rule::unique('goods_receipts', 'bill_number')
                    ->where('shop_id', $shopId)
                    ->where('supplier_id', $request->integer('supplier_id'))
                    ->ignore($receipt?->id),
            ],
            'bill_date' => ['nullable', 'date', 'before_or_equal:today'],
            'due_date' => ['nullable', 'date'],

            'other_charges' => ['nullable', 'numeric', 'min:0'],
            'is_inter_state' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'intent' => ['nullable', Rule::in(['save', 'post'])],

            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->whereNull('deleted_at')],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.free_quantity' => ['nullable', 'numeric', 'min:0'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0'],
            'items.*.discount_percent' => ['nullable', 'numeric', 'between:0,100'],
            'items.*.tax_rate' => ['nullable', 'numeric', 'between:0,100'],
            'items.*.batch_no' => ['nullable', 'string', 'max:80'],
            'items.*.mfg_date' => ['nullable', 'date', 'before_or_equal:today'],
            'items.*.expiry_date' => ['nullable', 'date'],
            'items.*.mrp' => ['nullable', 'numeric', 'min:0'],
            'items.*.selling_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.note' => ['nullable', 'string', 'max:250'],
        ], [
            'supplier_id.exists' => 'That supplier is not available in this shop.',
            'warehouse_id.exists' => 'Choose a warehouse belonging to this shop.',
            'bill_number.unique' => 'This supplier has already been entered with that bill number.',
            'received_on.before_or_equal' => 'Goods cannot be received in the future.',
            'items.required' => 'Add at least one line before saving.',
            'items.min' => 'Add at least one line before saving.',
            'items.*.quantity.gt' => 'Every line needs a quantity above zero.',
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
            'warehouse_id' => $data['warehouse_id'],
            'purchase_order_id' => $data['purchase_order_id'] ?? null,
            'received_on' => $data['received_on'],
            'bill_number' => $data['bill_number'] ?? null,
            'bill_date' => $data['bill_date'] ?? null,
            'due_date' => $data['due_date'] ?? null,
            'other_charges' => (float) ($data['other_charges'] ?? 0),
            'is_inter_state' => (bool) ($data['is_inter_state'] ?? false),
            'notes' => $data['notes'] ?? null,
        ];
    }
}
