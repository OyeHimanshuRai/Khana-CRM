<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\PurchaseReturn;
use App\Models\Warehouse;
use App\Services\PurchaseReturnService;
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
 * Purchase returns.
 *
 * The mirror of SalesReturnController. Raised against a goods receipt,
 * which is how it should almost always happen: the lines and costs all come
 * off the original so the credit matches what was actually billed rather
 * than what the product costs to buy today.
 */
class PurchaseReturnController extends Controller
{
    private const PAGE_SIZES = [10, 15, 25, 50];

    public function __construct(private readonly PurchaseReturnService $returns) {}

    /* -------------------------------------------------------------- list */

    public function index(Request $request): View
    {
        $perPage = (int) $request->integer('per_page', 15);
        $perPage = in_array($perPage, self::PAGE_SIZES, true) ? $perPage : 15;

        $rows = $this->filtered($request)
            ->with(['goodsReceipt:id,reference', 'supplier:id,name,company', 'shop:id,name'])
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
            'statuses' => PurchaseReturn::STATUSES,
            'showsShop' => CurrentShop::id() === null,
            'stats' => [
                'count' => PurchaseReturn::query()->counted()->count(),
                'value' => (float) PurchaseReturn::query()->counted()->sum('grand_total'),
                'pending' => PurchaseReturn::where('status', PurchaseReturn::PENDING)->count(),
                'refunded' => (float) PurchaseReturn::query()->counted()->sum('refund_amount'),
            ],
        ];

        return $request->header('X-Fragment')
            ? view('admin.purchase-returns._list', $data)
            : view('admin.purchase-returns.index', $data);
    }

    private function filtered(Request $request): Builder
    {
        return PurchaseReturn::query()
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
     * Without a receipt the screen is a search for one, for the same reason
     * SalesReturnController::create() searches for an invoice first: prices
     * and costs come off the original for free, and guessing them is how a
     * credit note ends up disagreeing with what was actually billed.
     */
    public function create(Request $request): View|RedirectResponse
    {
        $shop = CurrentShop::get();

        if ($shop === null) {
            return redirect()
                ->route('admin.purchase-returns.index')
                ->with('error', 'Choose a single shop before taking a return.');
        }

        $receipt = $request->integer('receipt')
            ? GoodsReceipt::with(['items.product.unit', 'items.batch', 'supplier'])->find($request->integer('receipt'))
            : null;

        if ($receipt && ! $receipt->isPosted()) {
            return redirect()
                ->route('admin.purchase-returns.create')
                ->with('error', 'Only a posted receipt has stock on the shelf to send back.');
        }

        return view('admin.purchase-returns.form', [
            'return' => new PurchaseReturn([
                'returned_on' => today()->toDateString(),
                'settlement' => PurchaseReturn::CREDIT,
            ]),
            'receipt' => $receipt,
            'lines' => $receipt
                ? $receipt->items->reject(fn (GoodsReceiptItem $i) => $i->isFullyReturned())->values()
                : collect(),
            'shop' => $shop,
            'warehouses' => Warehouse::active()->orderByDesc('is_default')->orderBy('name')->get(),
            'reference' => PurchaseReturn::nextReference($shop),
            'recent' => GoodsReceipt::query()
                ->counted()
                ->with('supplier:id,name,company')
                ->orderByDesc('received_on')
                ->limit(15)
                ->get(),
        ]);
    }

    public function show(PurchaseReturn $return): View
    {
        return view('admin.purchase-returns.show', [
            'return' => $return->load(['goodsReceipt', 'supplier', 'warehouse', 'shop']),
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

        $receipt = isset($data['goods_receipt_id']) ? GoodsReceipt::find($data['goods_receipt_id']) : null;

        if ($receipt && ! $receipt->isPosted()) {
            return ApiResponse::error('Only a posted receipt has stock on the shelf to send back.');
        }

        try {
            $return = DB::transaction(function () use ($data, $shop, $receipt, $request) {
                $user = Auth::user();

                $return = new PurchaseReturn([
                    'shop_id' => $shop->id,
                    'warehouse_id' => $data['warehouse_id'],
                    'goods_receipt_id' => $receipt?->id,
                    'supplier_id' => $receipt?->supplier_id,
                    'supplier_name' => $receipt?->supplier?->displayName(),
                    'reference' => PurchaseReturn::nextReference($shop),
                    'returned_on' => $data['returned_on'],
                    'status' => PurchaseReturn::PENDING,
                    'reason_code' => $data['reason_code'],
                    'reason' => $data['reason'] ?? null,
                    'settlement' => $data['settlement'],
                ]);

                $return->forceFill([
                    'created_by' => $user?->id,
                    'created_by_name' => $user?->name ?? 'System',
                ]);

                $return->save();

                $this->syncItems($return, $data['items'], $receipt);

                if ($request->string('intent')->toString() === 'approve'
                    && auth()->user()->can('purchasing.returns.approve')) {
                    $this->returns->approve($return);
                }

                return $return;
            });
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage());
        }

        ActivityLog::record(
            'purchase_return.created',
            "Raised purchase return {$return->reference}",
            $return,
        );

        return ApiResponse::success(
            $return->fresh()->isAccepted()
                ? "Return {$return->reference} accepted."
                : "Return {$return->reference} saved, awaiting approval.",
            ['id' => $return->id, 'reference' => $return->reference],
            route('admin.purchase-returns.show', $return),
        );
    }

    public function destroy(PurchaseReturn $return): JsonResponse
    {
        if (! $return->isEditable()) {
            return ApiResponse::error(
                'Only a return still awaiting a decision can be deleted. This one has been '
                .strtolower($return->statusLabel()).'.'
            );
        }

        $reference = $return->reference;
        $return->delete();

        ActivityLog::record('purchase_return.deleted', "Deleted purchase return {$reference}");

        return ApiResponse::success("Return {$reference} deleted.", [], route('admin.purchase-returns.index'));
    }

    /* --------------------------------------------------------- decisions */

    public function approve(Request $request, PurchaseReturn $return): JsonResponse
    {
        try {
            $this->returns->approve($return, $request->string('review_note')->toString() ?: null);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage());
        }

        return ApiResponse::success(
            "Return {$return->reference} accepted. The goods have left the shelf.",
            [],
            route('admin.purchase-returns.show', $return),
        );
    }

    public function reject(Request $request, PurchaseReturn $return): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:250'],
        ], [
            'reason.required' => 'Say why the return is being refused.',
        ]);

        try {
            $this->returns->reject($return, $data['reason']);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage());
        }

        return ApiResponse::success("Return {$return->reference} refused.", [],
            route('admin.purchase-returns.show', $return));
    }

    public function rejectForm(PurchaseReturn $return): View
    {
        return view('admin.purchase-returns._reject', ['return' => $return]);
    }

    /* ----------------------------------------------------------- helpers */

    /**
     * Write the lines, copying costs off the receipt.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    private function syncItems(PurchaseReturn $return, array $items, ?GoodsReceipt $receipt): void
    {
        foreach ($items as $line) {
            $quantity = (float) $line['quantity'];

            if ($quantity <= 0) {
                continue;
            }

            $source = isset($line['goods_receipt_item_id'])
                ? GoodsReceiptItem::find((int) $line['goods_receipt_item_id'])
                : null;

            if ($source === null) {
                continue;
            }

            /*
             | Never more than is left on the line - the shelf must not lose
             | a unit that was never received in the first place.
             */
            $returnable = $source->returnableQuantity();

            if ($quantity > $returnable + 0.0005) {
                throw new RuntimeException(sprintf(
                    'Only %s of "%s" is left to return on %s.',
                    rtrim(rtrim(number_format($returnable, 3, '.', ''), '0'), '.'),
                    $source->product_name,
                    $receipt?->reference ?? 'that receipt',
                ));
            }

            $unitCost = (float) ($source->landed_cost ?: $source->unit_cost);

            $return->items()->create([
                'goods_receipt_item_id' => $source->id,
                'product_id' => $source->product_id,
                'batch_id' => $source->batch_id,
                'product_name' => $source->product_name,
                'sku' => $source->sku,
                'unit_code' => $source->unit_code,
                'batch_no' => $source->batch_no,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'tax_rate' => (float) $source->tax_rate,
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
            'goods_receipt_id' => [
                'required', 'integer',
                Rule::exists('goods_receipts', 'id')->where('shop_id', $shopId),
            ],
            'warehouse_id' => [
                'required', 'integer',
                Rule::exists('warehouses', 'id')->where('shop_id', $shopId),
            ],
            'returned_on' => ['required', 'date', 'before_or_equal:today'],
            'reason_code' => ['required', Rule::in(array_keys(PurchaseReturn::REASONS))],
            'reason' => ['nullable', 'string', 'max:2000'],
            'settlement' => ['required', Rule::in(array_keys(PurchaseReturn::SETTLEMENTS))],
            'intent' => ['nullable', Rule::in(['save', 'approve'])],

            'items' => ['required', 'array', 'min:1'],
            'items.*.goods_receipt_item_id' => ['required', 'integer', 'exists:goods_receipt_items,id'],
            'items.*.quantity' => ['required', 'numeric', 'min:0'],
            'items.*.note' => ['nullable', 'string', 'max:250'],
        ], [
            'goods_receipt_id.required' => 'Find the receipt the goods arrived on first.',
            'goods_receipt_id.exists' => 'That receipt is not available in this shop.',
            'warehouse_id.exists' => 'Choose a warehouse belonging to this shop.',
            'returned_on.before_or_equal' => 'Goods cannot leave in the future.',
            'items.required' => 'Enter a quantity against at least one line.',
        ]);
    }
}
