<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\SalesReturn;
use App\Models\SalesReturnItem;
use App\Models\Shop;
use App\Models\Warehouse;
use App\Services\SalesReturnService;
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
 * Sales returns.
 *
 * Raised against an invoice, which is how it should almost always happen:
 * the lines, prices and costs all come off the original so the credit
 * matches what was charged rather than what the product costs today.
 *
 * The only decision the person at the counter has to make per line is the
 * quantity and the condition - and the condition is the one that decides
 * whether the goods go back on the shelf.
 */
class SalesReturnController extends Controller
{
    private const PAGE_SIZES = [10, 15, 25, 50];

    public function __construct(private readonly SalesReturnService $returns) {}

    /* -------------------------------------------------------------- list */

    public function index(Request $request): View
    {
        $perPage = (int) $request->integer('per_page', 15);
        $perPage = in_array($perPage, self::PAGE_SIZES, true) ? $perPage : 15;

        $rows = $this->filtered($request)
            ->with(['invoice:id,number', 'customer:id,name', 'shop:id,name'])
            ->withCount('items')
            ->paginate($perPage)
            ->withQueryString();

        $data = [
            'returns' => $rows,
            'search' => $request->string('q')->toString(),
            'status' => $request->string('status')->toString(),
            'from' => $request->string('from')->toString(),
            'to' => $request->string('to')->toString(),
            'perPage' => $perPage,
            'pageSizes' => self::PAGE_SIZES,
            'statuses' => SalesReturn::STATUSES,
            'showsShop' => CurrentShop::id() === null,
            'stats' => [
                'count' => SalesReturn::query()->counted()->count(),
                'value' => (float) SalesReturn::query()->counted()->sum('grand_total'),
                'pending' => SalesReturn::where('status', SalesReturn::PENDING)->count(),
                'refunded' => (float) SalesReturn::query()->counted()->sum('refund_amount'),
            ],
        ];

        return $request->header('X-Fragment')
            ? view('admin.sales-returns._list', $data)
            : view('admin.sales-returns.index', $data);
    }

    private function filtered(Request $request): Builder
    {
        return SalesReturn::query()
            ->search($request->string('q')->toString())
            ->ofStatus($request->string('status')->toString())
            ->between($request->string('from')->toString(), $request->string('to')->toString())
            ->orderByDesc('returned_on')
            ->orderByDesc('id');
    }

    /* ---------------------------------------------------------- document */

    /**
     * Start a return.
     *
     * Without an invoice the screen is a search for one: a return raised
     * against the original bill gets its prices and costs right for free,
     * and guessing them is how a credit note ends up disagreeing with what
     * the customer paid.
     */
    public function create(Request $request): View|RedirectResponse
    {
        $shop = CurrentShop::get();

        if ($shop === null) {
            return redirect()
                ->route('admin.sales-returns.index')
                ->with('error', 'Choose a single shop before taking a return.');
        }

        $invoice = $request->integer('invoice')
            ? Invoice::with(['items.product.unit', 'items.batch', 'customer'])->find($request->integer('invoice'))
            : null;

        if ($invoice && $invoice->isCancelled()) {
            return redirect()
                ->route('admin.sales-returns.create')
                ->with('error', 'That invoice was cancelled — its goods are already back on the shelf.');
        }

        return view('admin.sales-returns.form', [
            'return' => new SalesReturn([
                'returned_on' => today()->toDateString(),
                'settlement' => SalesReturn::CREDIT,
            ]),
            'invoice' => $invoice,
            'lines' => $invoice
                ? $invoice->items->reject(fn (InvoiceItem $i) => $i->isFullyReturned())->values()
                : collect(),
            'shop' => $shop,
            'warehouses' => Warehouse::active()->orderByDesc('is_default')->orderBy('name')->get(),
            'reference' => SalesReturn::nextReference($shop),
            'recent' => Invoice::query()
                ->counted()
                ->with('customer:id,name')
                ->orderByDesc('invoiced_at')
                ->limit(15)
                ->get(),
        ]);
    }

    public function show(SalesReturn $return): View
    {
        return view('admin.sales-returns.show', [
            'return' => $return->load(['invoice', 'customer', 'warehouse', 'shop']),
            'items' => $return->items()->with('product:id,name,sku')->get(),
        ]);
    }

    /* ------------------------------------------------------------ writes */

    public function store(Request $request): JsonResponse
    {
        $shop = CurrentShop::get();

        if ($shop === null) {
            return ApiResponse::error('Choose a single shop before taking a return.');
        }

        $data = $this->validated($request);

        $invoice = isset($data['invoice_id']) ? Invoice::find($data['invoice_id']) : null;

        if ($invoice && $invoice->isCancelled()) {
            return ApiResponse::error('That invoice was cancelled — its goods are already back on the shelf.');
        }

        try {
            $return = DB::transaction(function () use ($data, $shop, $invoice, $request) {
                $user = Auth::user();

                $return = new SalesReturn([
                    'shop_id' => $shop->id,
                    'warehouse_id' => $data['warehouse_id'],
                    'invoice_id' => $invoice?->id,
                    'customer_id' => $invoice?->customer_id,
                    'customer_name' => $invoice?->billedTo(),
                    'reference' => SalesReturn::nextReference($shop),
                    'returned_on' => $data['returned_on'],
                    'status' => SalesReturn::PENDING,
                    'reason_code' => $data['reason_code'],
                    'reason' => $data['reason'] ?? null,
                    'settlement' => $data['settlement'],
                ]);

                $return->forceFill([
                    'created_by' => $user?->id,
                    'created_by_name' => $user?->name ?? 'System',
                ]);

                $return->save();

                $this->syncItems($return, $data['items'], $invoice);

                if ($request->string('intent')->toString() === 'approve'
                    && auth()->user()->can('sales.returns.approve')) {
                    $this->returns->approve($return);
                }

                return $return;
            });
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage());
        }

        ActivityLog::record(
            'sales_return.created',
            "Raised sales return {$return->reference}",
            $return,
        );

        return ApiResponse::success(
            $return->fresh()->isAccepted()
                ? "Return {$return->reference} accepted."
                : "Return {$return->reference} saved, awaiting approval.",
            ['id' => $return->id, 'reference' => $return->reference],
            route('admin.sales-returns.show', $return),
        );
    }

    public function destroy(SalesReturn $return): JsonResponse
    {
        if (! $return->isEditable()) {
            return ApiResponse::error(
                'Only a return still awaiting a decision can be deleted. This one has been '
                .strtolower($return->statusLabel()).'.'
            );
        }

        $reference = $return->reference;
        $return->delete();

        ActivityLog::record('sales_return.deleted', "Deleted sales return {$reference}");

        return ApiResponse::success("Return {$reference} deleted.", [], route('admin.sales-returns.index'));
    }

    /* --------------------------------------------------------- decisions */

    public function approve(Request $request, SalesReturn $return): JsonResponse
    {
        try {
            $this->returns->approve($return, $request->string('review_note')->toString() ?: null);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage());
        }

        return ApiResponse::success(
            "Return {$return->reference} accepted. Resalable goods are back on the shelf.",
            [],
            route('admin.sales-returns.show', $return),
        );
    }

    public function reject(Request $request, SalesReturn $return): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:250'],
        ], [
            'reason.required' => 'Say why the return is being refused — the customer will ask.',
        ]);

        try {
            $this->returns->reject($return, $data['reason']);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage());
        }

        return ApiResponse::success("Return {$return->reference} refused.", [],
            route('admin.sales-returns.show', $return));
    }

    public function rejectForm(SalesReturn $return): View
    {
        return view('admin.sales-returns._reject', ['return' => $return]);
    }

    /* ----------------------------------------------------------- helpers */

    /**
     * Write the lines, copying prices and costs off the invoice.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    private function syncItems(SalesReturn $return, array $items, ?Invoice $invoice): void
    {
        foreach ($items as $line) {
            $quantity = (float) $line['quantity'];

            if ($quantity <= 0) {
                continue;
            }

            $source = isset($line['invoice_item_id'])
                ? InvoiceItem::find((int) $line['invoice_item_id'])
                : null;

            if ($source === null) {
                continue;
            }

            /*
             | Never more than is left on the line. Someone returning three
             | of two is either mis-keying or trying it on, and either way
             | the shelf must not gain a unit that never left it.
             */
            $returnable = $source->returnableQuantity();

            if ($quantity > $returnable + 0.0005) {
                throw new RuntimeException(sprintf(
                    'Only %s of "%s" is left to return on %s.',
                    rtrim(rtrim(number_format($returnable, 3, '.', ''), '0'), '.'),
                    $source->product_name,
                    $invoice?->number ?? 'that invoice',
                ));
            }

            $return->items()->create([
                'invoice_item_id' => $source->id,
                'product_id' => $source->product_id,
                'batch_id' => $source->batch_id,
                'product_name' => $source->product_name,
                'sku' => $source->sku,
                'unit_code' => $source->unit_code,
                'batch_no' => $source->batch_no,
                'quantity' => $quantity,
                'condition' => $line['condition'] ?? SalesReturnItem::RESALABLE,
                // Copied so the credit matches what was charged, discount
                // and all - not the product's price today.
                'unit_price' => (float) $source->quantity > 0
                    ? (float) $source->taxable_value / (float) $source->quantity
                    : (float) $source->unit_price,
                'tax_rate' => (float) $source->tax_rate,
                'unit_cost' => (float) $source->unit_cost,
                'note' => $line['note'] ?? null,
            ]);
        }

        if ($return->items()->count() === 0) {
            throw new RuntimeException('Add at least one line with a quantity to return.');
        }
    }

    /* ------------------------------------------------------- validation */

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $shopId = CurrentShop::id();

        return $request->validate([
            'invoice_id' => [
                'required', 'integer',
                Rule::exists('invoices', 'id')->where('shop_id', $shopId),
            ],
            'warehouse_id' => [
                'required', 'integer',
                Rule::exists('warehouses', 'id')->where('shop_id', $shopId),
            ],
            'returned_on' => ['required', 'date', 'before_or_equal:today'],
            'reason_code' => ['required', Rule::in(array_keys(SalesReturn::REASONS))],
            'reason' => ['nullable', 'string', 'max:2000'],
            'settlement' => ['required', Rule::in(array_keys(SalesReturn::SETTLEMENTS))],
            'intent' => ['nullable', Rule::in(['save', 'approve'])],

            'items' => ['required', 'array', 'min:1'],
            'items.*.invoice_item_id' => ['required', 'integer', 'exists:invoice_items,id'],
            'items.*.quantity' => ['required', 'numeric', 'min:0'],
            'items.*.condition' => ['nullable', Rule::in(array_keys(SalesReturnItem::CONDITIONS))],
            'items.*.note' => ['nullable', 'string', 'max:250'],
        ], [
            'invoice_id.required' => 'Find the invoice the goods were sold on first.',
            'invoice_id.exists' => 'That invoice is not available in this shop.',
            'warehouse_id.exists' => 'Choose a warehouse belonging to this shop.',
            'returned_on.before_or_equal' => 'Goods cannot come back in the future.',
            'items.required' => 'Enter a quantity against at least one line.',
        ]);
    }
}
