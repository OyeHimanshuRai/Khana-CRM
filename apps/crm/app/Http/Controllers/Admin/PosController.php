<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\ParkedSale;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Warehouse;
use App\Services\InvoiceService;
use App\Support\ApiResponse;
use App\Support\CurrentShop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

/**
 * The counter.
 *
 * One screen, one endpoint. The terminal and the manual invoice form are the
 * same document raised two ways - the manual mode simply exposes the fields
 * a counter sale does not stop to ask for (a back-date, an explicit due
 * date, a per-line price).
 *
 * The elevated rights the SRS separates out are enforced here rather than in
 * the service, because they are about who is standing at the till, not about
 * whether the arithmetic is valid:
 *
 *   pos.terminal.discount   may change a price or give a discount
 *   pos.terminal.credit     may leave the bill unpaid
 */
class PosController extends Controller
{
    public function __construct(private readonly InvoiceService $invoices) {}

    /* ------------------------------------------------------------ screens */

    /** The fast counter flow. */
    public function terminal(): View|RedirectResponse
    {
        return $this->screen(Invoice::POS);
    }

    /** The same document with the slower fields exposed. */
    public function manual(): View|RedirectResponse
    {
        return $this->screen(Invoice::MANUAL);
    }

    private function screen(string $channel): View|RedirectResponse
    {
        $shop = CurrentShop::get();

        if ($shop === null) {
            return redirect()
                ->route('admin.dashboard')
                ->with('error', 'Choose a single shop before billing — a sale happens at one counter.');
        }

        $warehouses = Warehouse::active()->orderByDesc('is_default')->orderBy('name')->get();

        if ($warehouses->isEmpty()) {
            return redirect()
                ->route('admin.warehouses.index')
                ->with('error', 'This shop has no warehouse to sell from. Create one first.');
        }

        $menu = $this->menu();

        return view('admin.pos.terminal', [
            'shop' => $shop,
            'channel' => $channel,
            'warehouses' => $warehouses,
            'methods' => Payment::METHODS,
            'menu' => $menu,
            // Built from the dishes actually on the grid, so a category with
            // nothing sellable in it never becomes a tab that filters to an
            // empty screen, and every count is the real one.
            'menuCategories' => $this->menuCategories($menu),
            'recentOrders' => $this->recentOrders(),
            'canDiscount' => Gate::allows('pos.terminal.discount'),
            'canCredit' => Gate::allows('pos.terminal.credit'),
            // Shown on the Held Sales button, because a hold nobody picked up
            // is either a walk-out or a cashier who forgot.
            'heldCount' => ParkedSale::query()->count(),
            'nextNumber' => $channel === Invoice::POS
                ? $shop->pos_prefix.'/'.$shop->code.'/'.now()->format('Y').'/'
                    .str_pad((string) $shop->pos_next, 5, '0', STR_PAD_LEFT)
                : $shop->invoice_prefix.'/'.$shop->code.'/'.now()->format('Y').'/'
                    .str_pad((string) $shop->invoice_next, 5, '0', STR_PAD_LEFT),
        ]);
    }

    /* -------------------------------------------------------------- menu */

    /**
     * How many dishes the grid draws.
     *
     * A grid is something somebody taps, not a catalogue they page through.
     * A shop with more sellable rows than this still reaches every one of
     * them through the search box and the scanner, which query the whole
     * table - the cap only decides what is on screen before anyone types.
     */
    private const MENU_TILES = 240;

    /**
     * The dishes on the grid, each with the payload the cart already speaks.
     *
     * The payload is Product::toLookupArray - the same shape the search box
     * and a resumed hold build rows from - so tapping a tile, picking a
     * search result and scanning a barcode all end in one
     * window.LineItems.add() with one definition of a line behind them.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function menu(): Collection
    {
        $shopId = CurrentShop::id();

        /*
         | shops:id carries the per-shop price override - see
         | Product::overrideFor(). Left out, every tile asks the pivot for its
         | own selling price, cost and MRP before it can be drawn.
         */
        $products = Product::query()
            ->sellable()
            ->with(['unit', 'taxRate', 'category:id,name', 'shops:id'])
            ->orderBy('name')
            ->limit(self::MENU_TILES)
            ->get();

        /*
         | Stock for the whole grid in one grouped query. Left to the model,
         | each tile would ask the stock table three times - a few hundred
         | queries for a screen that is opened and re-opened all day.
         */
        $stock = ProductStock::query()
            ->whereIn('product_id', $products->modelKeys())
            ->when($shopId !== null, fn ($query) => $query->where('shop_id', $shopId))
            ->selectRaw('product_id')
            ->selectRaw('COALESCE(SUM(quantity), 0) as on_hand')
            ->selectRaw('COALESCE(SUM(quantity), 0) - COALESCE(SUM(reserved), 0) as available')
            ->groupBy('product_id')
            ->get()
            ->keyBy('product_id');

        return $products->map(function (Product $product) use ($shopId, $stock) {
            $counted = $stock->get($product->id);

            return [
                'product' => $product,
                // Why it cannot be sold right now - sold out, off the menu,
                // outside its serving hours - or null when it can.
                'reason' => $product->unavailableReason(),
                'payload' => $product->toLookupArray($shopId, [
                    'on_hand' => (float) ($counted->on_hand ?? 0),
                    'available' => (float) ($counted->available ?? 0),
                ]),
            ];
        });
    }

    /**
     * The tabs above the grid, counted from the dishes actually on it.
     *
     * @param  Collection<int, array<string, mixed>>  $menu
     * @return Collection<int, array{category: mixed, count: int}>
     */
    private function menuCategories(Collection $menu): Collection
    {
        return $menu
            ->map(fn (array $row) => $row['product']->category)
            ->filter()
            ->groupBy('id')
            ->map(fn (Collection $rows) => ['category' => $rows->first(), 'count' => $rows->count()])
            ->sortBy(fn (array $row) => $row['category']->name)
            ->values();
    }

    /**
     * The last few tickets, for the strip along the top.
     *
     * Not restricted to today: a counter opening at eleven would stare at an
     * empty strip all morning, and "no orders" reads as a broken screen
     * rather than as a quiet one. Each row carries its own age instead, so
     * nothing here pretends to be more recent than it is.
     *
     * Gated like every other list of orders - a till that may not see the
     * order book simply gets no strip.
     *
     * @return Collection<int, Order>
     */
    private function recentOrders(): Collection
    {
        if (! Gate::allows('sales.orders.view')) {
            return collect();
        }

        return Order::query()
            ->with('customer:id,name')
            ->latest('id')
            ->limit(6)
            ->get();
    }

    /* -------------------------------------------------------------- sale */

    /**
     * Complete a sale.
     *
     * Everything that can refuse the sale does so before anything is
     * written: the permission checks here, then the service's own checks on
     * stock and credit, all inside one transaction. A half-rung sale is
     * worse than none.
     */
    public function store(Request $request): JsonResponse
    {
        $shop = CurrentShop::get();

        if ($shop === null) {
            return ApiResponse::error('Choose a single shop before billing.');
        }

        $data = $this->validated($request);

        $customer = isset($data['customer_id'])
            ? Customer::find($data['customer_id'])
            : null;

        if (isset($data['customer_id']) && $customer === null) {
            return ApiResponse::error('That customer is not available in this shop.', [], 404);
        }

        $paid = collect($data['payments'] ?? [])->sum(fn (array $p) => (float) ($p['amount'] ?? 0));

        if ($refusal = $this->refuseElevated($data, $paid)) {
            return ApiResponse::error($refusal, [], 403);
        }

        try {
            $invoice = $this->invoices->create([
                'shop' => $shop,
                'warehouse' => Warehouse::find($data['warehouse_id']),
                'customer' => $customer,
                'channel' => $data['channel'],
                'invoiced_at' => $data['invoiced_at'] ?? null,
                /*
                 | The counter works in what the customer pays, so every
                 | price coming off this screen includes tax whatever the
                 | catalogue's own convention is. Saying so explicitly here
                 | is what keeps the two from disagreeing.
                 */
                'items' => collect($data['items'])
                    ->map(fn (array $item) => $item + ['price_includes_tax' => true])
                    ->all(),
                'payments' => $data['payments'] ?? [],
                'invoice_discount' => (float) ($data['invoice_discount'] ?? 0),
                'invoice_discount_percent' => (float) ($data['invoice_discount_percent'] ?? 0),
                'due_date' => $data['due_date'] ?? null,
                'notes' => $data['notes'] ?? null,
                'walk_in_name' => $data['walk_in_name'] ?? null,
                'walk_in_mobile' => $data['walk_in_mobile'] ?? null,
            ]);
        } catch (RuntimeException $e) {
            // The service's refusals are written for the person at the till,
            // so they are passed through unchanged.
            return ApiResponse::error($e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return ApiResponse::error('The sale could not be completed. Nothing has been charged or taken from stock.');
        }

        return ApiResponse::success(
            sprintf('%s · ₹%s%s',
                $invoice->number,
                number_format((float) $invoice->grand_total, 2),
                (float) $invoice->due_total > 0
                    ? ' · ₹'.number_format((float) $invoice->due_total, 2).' on account'
                    : ' · paid',
            ),
            [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'grand_total' => (float) $invoice->grand_total,
                'paid_total' => (float) $invoice->paid_total,
                'due_total' => (float) $invoice->due_total,
                'receipt_url' => route('admin.invoices.print', $invoice),
                'invoice_url' => route('admin.invoices.show', $invoice),
            ],
        );
    }

    /**
     * The elevated rights the counter may not have.
     *
     * Returned as a message rather than thrown, so the till gets one clear
     * sentence instead of a stack trace - and so the two refusals can be
     * worded differently, because they are different conversations with a
     * supervisor.
     *
     * @param  array<string, mixed>  $data
     */
    private function refuseElevated(array $data, float $paid): ?string
    {
        if ($this->wantsDiscount($data) && ! Gate::allows('pos.terminal.discount')) {
            return 'Changing a price or giving a discount needs a supervisor. Ask for the discount override right.';
        }

        // A bill with nothing tendered, or not enough, is a credit sale
        // whatever the form calls it.
        $isCredit = $paid <= 0.004 || ! empty($data['is_credit']);

        if ($isCredit && ! Gate::allows('pos.terminal.credit')) {
            return 'Leaving a bill unpaid needs the credit sale right. Take payment in full, or ask a supervisor.';
        }

        return null;
    }

    /**
     * Whether anything on this sale gives away money the cashier may not.
     *
     * The rate box posts on every line whether or not anyone touched it - a
     * readonly input still submits - so its mere presence proves nothing.
     * Treating it as proof locked cashiers without the override out of every
     * sale, including the ordinary ones. What counts is a price *below* what
     * the shop asks for; charging more is somebody else's problem.
     *
     * @param  array<string, mixed>  $data
     */
    private function wantsDiscount(array $data): bool
    {
        if ((float) ($data['invoice_discount'] ?? 0) > 0
            || (float) ($data['invoice_discount_percent'] ?? 0) > 0) {
            return true;
        }

        $items = collect($data['items']);

        if ($items->contains(fn (array $item) => (float) ($item['discount_percent'] ?? 0) > 0
            || (float) ($item['discount_amount'] ?? 0) > 0)) {
            return true;
        }

        $priced = $items->filter(
            fn (array $item) => isset($item['unit_price']) && $item['unit_price'] !== ''
        );

        if ($priced->isEmpty()) {
            return false;
        }

        $shopId = CurrentShop::id();

        $products = Product::with(['taxRate', 'shops:id'])
            ->whereIn('id', $priced->pluck('product_id')->unique()->all())
            ->get()
            ->keyBy('id');

        return $priced->contains(function (array $item) use ($products, $shopId) {
            $product = $products->get((int) $item['product_id']);

            // A product that is not there fails validation anyway; it is not
            // this method's job to decide that.
            if ($product === null) {
                return false;
            }

            // A paisa of rounding either way is not a discount.
            return (float) $item['unit_price'] < $product->counterPriceFor($shopId) - 0.01;
        });
    }

    /* ------------------------------------------------------- validation */

    /**
     * @return array<string, mixed>
     */
    /* -------------------------------------------------------- hold / resume */

    /**
     * Put the current sale to one side (§6).
     *
     * Deliberately NOT an invoice. Nothing is numbered, no stock moves and no
     * ledger is written - a held sale is a note the till can pick up again,
     * and treating it as a document would put a hole in the invoice series
     * every time somebody changed their mind at the counter.
     *
     * Validated loosely on purpose: a cart is held precisely because it is
     * not finished. A customer who has not decided, a card that will not
     * read, somebody who went back for one more thing - none of those should
     * have to pass the checks that a completed sale does.
     */
    public function park(Request $request): JsonResponse
    {
        $shop = CurrentShop::get();

        if ($shop === null) {
            return ApiResponse::error('Choose a single shop before holding a sale.');
        }

        $data = $request->validate([
            'channel' => ['required', Rule::in([Invoice::POS, Invoice::MANUAL])],
            'label' => ['nullable', 'string', 'max:120'],
            'customer_id' => ['nullable', 'integer'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
        ], [
            'items.required' => 'There is nothing on this sale to hold.',
            'items.min' => 'There is nothing on this sale to hold.',
        ]);

        /*
         | The payload is whatever the terminal sent, minus the CSRF token.
         | Stored verbatim so resuming gives back exactly what the customer
         | was quoted - see the migration for why it is not re-priced.
         */
        $payload = $request->except(['_token', 'label']);

        $sale = ParkedSale::create([
            'shop_id' => $shop->id,
            'reference' => ParkedSale::nextReference($shop->id),
            'label' => $data['label'] ?? null,
            'customer_id' => $data['customer_id'] ?? null,
            'channel' => $data['channel'],
            'payload' => $payload,
            'total' => $this->parkedTotal($request, $data['items']),
            'line_count' => count($data['items']),
            'user_id' => $request->user()?->id,
        ]);

        return ApiResponse::success(
            sprintf('Held as %s. Pick it up from Held Sales.', $sale->reference),
            ['reference' => $sale->reference, 'id' => $sale->id],
        );
    }

    /**
     * What a held basket comes to.
     *
     * The till sends its own running total, which is the figure the customer
     * was actually quoted - a negotiated price, a discount a supervisor gave -
     * and that is what should show on the Held Sales list.
     *
     * But it is a number from a browser, so it is only believed when it is
     * a sane one. Anything missing, negative or absurd falls back to pricing
     * the lines from the menu here. The column exists so a cashier can tell
     * two held baskets apart; a zero against every one of them is the same as
     * no column at all, which is what it was.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    private function parkedTotal(Request $request, array $items): float
    {
        // Priced from one read of the basket's products, the same way a
        // resumed hold builds its rows. A find() inside the sum was a query
        // per line, each then asking again for the pivot and the tax rate.
        $products = Product::query()
            ->with(['taxRate', 'shops:id'])
            ->whereIn('id', collect($items)->pluck('product_id')->filter()->unique())
            ->get()
            ->keyBy('id');

        $priced = round((float) collect($items)->sum(function (array $line) use ($products) {
            $product = $products->get((int) $line['product_id']);

            $unit = isset($line['unit_price']) && $line['unit_price'] !== ''
                ? (float) $line['unit_price']
                : (float) ($product?->counterPriceFor(CurrentShop::id()) ?? 0);

            return $unit * (float) $line['quantity'];
        }), 2);

        $sent = (float) $request->input('grand_total', 0);

        // Believed when it is in the same world as the menu's own arithmetic:
        // a discount can take it down, nothing should take it far above.
        if ($sent > 0 && $sent <= $priced + 0.5) {
            return round($sent, 2);
        }

        return $priced;
    }

    /** Everything this branch is currently holding. */
    public function parked(Request $request): View|JsonResponse
    {
        $sales = ParkedSale::query()
            ->with(['customer:id,name', 'user:id,name'])
            ->latest('id')
            ->limit(100)
            ->get();

        if ($request->expectsJson()) {
            return ApiResponse::success('', ['sales' => $sales->map(fn (ParkedSale $sale) => [
                'id' => $sale->id,
                'reference' => $sale->reference,
                'label' => $sale->displayLabel(),
                'total' => (float) $sale->total,
                'lines' => $sale->line_count,
                'held_by' => $sale->user?->name,
                'held_at' => $sale->created_at?->format('g:i a'),
                'stale' => $sale->isStale(),
            ])->all()]);
        }

        return view('admin.pos.parked', ['sales' => $sales]);
    }

    /**
     * Hand a held sale back to the till.
     *
     * Read and deleted in one transaction, because two cashiers resuming the
     * same hold would otherwise both get it and the shop would bill twice for
     * one basket.
     */
    public function resume(ParkedSale $sale): JsonResponse
    {
        $payload = DB::transaction(function () use ($sale) {
            $locked = ParkedSale::query()->whereKey($sale->id)->lockForUpdate()->first();

            if ($locked === null) {
                return null;
            }

            $payload = $locked->payload;
            $locked->delete();

            return $payload;
        });

        if ($payload === null) {
            return ApiResponse::error('Somebody else has already picked that one up.');
        }

        /*
         | The payload names products by id; the line editor needs the whole
         | product to draw a row. Resolved here rather than in the browser so
         | a resumed sale is built from the same shape a scan produces - see
         | Product::toLookupArray.
         |
         | A product deleted while the sale was held simply does not come
         | back. Saying so is better than a row with a blank name that
         | fails on submit.
         */
        $ids = collect($payload['items'] ?? [])->pluck('product_id')->filter()->unique();

        $products = Product::query()
            ->with(['unit:id,code,allow_decimal', 'taxRate', 'shops:id'])
            ->whereIn('id', $ids)
            ->get()
            ->mapWithKeys(fn (Product $product) => [
                $product->id => $product->toLookupArray(CurrentShop::id()),
            ]);

        $missing = $ids->diff($products->keys())->count();

        return ApiResponse::success(
            $missing > 0
                ? sprintf('Resumed. %d line%s could not be brought back — the item is no longer on the menu.',
                    $missing, $missing === 1 ? '' : 's')
                : 'Resumed.',
            ['payload' => $payload, 'products' => $products],
        );
    }

    /** Throw a held sale away. */
    public function discard(ParkedSale $sale): JsonResponse
    {
        $reference = $sale->reference;
        $sale->delete();

        ActivityLog::record('pos.hold_discarded', "Discarded held sale {$reference}", $sale);

        return ApiResponse::success("{$reference} discarded.");
    }

    private function validated(Request $request): array
    {
        $shopId = CurrentShop::id();

        return $request->validate([
            'channel' => ['required', Rule::in([Invoice::POS, Invoice::MANUAL])],

            'warehouse_id' => [
                'required', 'integer',
                Rule::exists('warehouses', 'id')->where('shop_id', $shopId),
            ],

            'customer_id' => [
                'nullable', 'integer',
                Rule::exists('customers', 'id')->where('shop_id', $shopId)->whereNull('deleted_at'),
            ],

            // For a walk-in the counter may still want a name on the receipt.
            'walk_in_name' => ['nullable', 'string', 'max:150'],
            'walk_in_mobile' => ['nullable', 'string', 'max:20'],

            // Only the manual form offers this; the terminal always bills now.
            'invoiced_at' => ['nullable', 'date', 'before_or_equal:now'],
            'due_date' => ['nullable', 'date', 'after_or_equal:today'],
            'is_credit' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],

            'invoice_discount' => ['nullable', 'numeric', 'min:0'],
            'invoice_discount_percent' => ['nullable', 'numeric', 'between:0,100'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => [
                'required', 'integer',
                Rule::exists('products', 'id')->whereNull('deleted_at')->where('is_active', true),
            ],
            'items.*.batch_id' => [
                'nullable', 'integer',
                Rule::exists('batches', 'id')->where('shop_id', $shopId),
            ],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.discount_percent' => ['nullable', 'numeric', 'between:0,100'],
            'items.*.discount_amount' => ['nullable', 'numeric', 'min:0'],

            'payments' => ['nullable', 'array'],
            'payments.*.method' => ['required_with:payments', Rule::in(array_keys(Payment::METHODS))],
            'payments.*.amount' => ['required_with:payments', 'numeric', 'gt:0'],
            'payments.*.transaction_ref' => ['nullable', 'string', 'max:120'],
            'payments.*.bank_name' => ['nullable', 'string', 'max:120'],
            'payments.*.cheque_date' => ['nullable', 'date'],
        ], [
            'items.required' => 'Scan or add at least one product before completing the sale.',
            'items.min' => 'Scan or add at least one product before completing the sale.',
            'items.*.quantity.gt' => 'Every line needs a quantity above zero.',
            'customer_id.exists' => 'That customer is not available in this shop.',
            'warehouse_id.exists' => 'Choose a warehouse belonging to this shop.',
            'invoiced_at.before_or_equal' => 'An invoice cannot be dated in the future.',
            'due_date.after_or_equal' => 'A due date in the past is already overdue — set a real one.',
        ]);
    }
}
