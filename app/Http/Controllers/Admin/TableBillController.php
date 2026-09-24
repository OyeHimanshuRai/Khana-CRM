<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\TableSession;
use App\Models\Warehouse;
use App\Models\TableCartItem;
use App\Services\MenuService;
use App\Services\TableBillService;
use App\Services\TableCartService;
use App\Services\TableOrderService;
use App\Support\ApiResponse;
use App\Support\CurrentShop;
use App\Support\UpiQr;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

/**
 * Settling a table (§6).
 *
 * The counter's other half. PosController rings up somebody standing at the
 * till; this rings up somebody who has been sitting down, and the difference
 * is that the items are already there - they were ordered hours ago, cooked,
 * and carried out.
 *
 * So there is no basket here. The screen's job is to show what the table ate,
 * let a cashier take some or all of it onto one bill, and take the money.
 *
 * The elevated rights are checked here rather than in the service, for the
 * same reason they are in PosController: they are about who is standing at
 * the till, not about whether the arithmetic is valid.
 */
class TableBillController extends Controller
{
    public function __construct(
        private readonly TableBillService $bills,
        private readonly TableCartService $cart,
        private readonly TableOrderService $orders,
    ) {}

    /* ------------------------------------------------------------ screens */

    /** Every table the counter can currently bill. */
    public function index(Request $request): View|RedirectResponse
    {
        if (CurrentShop::id() === null) {
            return redirect()
                ->route('admin.dashboard')
                ->with('error', 'Choose a single shop before billing — a table is in one room.');
        }

        $sessions = $this->bills->openTables();

        $data = [
            'sessions' => $sessions,
            // Totals per table, so the list can be read without opening each
            // one. Small: there are as many of these as there are tables.
            'totals' => $sessions->mapWithKeys(fn (TableSession $s) => [
                $s->id => $this->bills->summary($s),
            ]),
        ];

        return $request->header('X-Fragment')
            ? view('admin.table-bills._list', $data)
            : view('admin.table-bills.index', $data);
    }

    /** One table's bill. */
    public function show(Request $request, TableSession $session): View|RedirectResponse
    {
        if (CurrentShop::id() === null) {
            return redirect()
                ->route('admin.table-bills.index')
                ->with('error', 'Choose a single shop first.');
        }

        $session->load(['table.floor', 'customer', 'invoices']);

        $data = [
            'session' => $session,
            'summary' => $this->bills->summary($session),
            'warehouses' => Warehouse::active()->orderByDesc('is_default')->orderBy('name')->get(),
            'methods' => Payment::METHODS,
            'targets' => Gate::allows('pos.tables.move')
                ? $this->bills->mergeTargets($session)
                : collect(),
            'canDiscount' => Gate::allows('pos.terminal.discount'),
            'canCredit' => Gate::allows('pos.terminal.credit'),
            'canOrder' => Gate::allows('pos.tables.order'),
            'canSettle' => Gate::allows('pos.tables.settle'),
            // What the table has picked but not yet sent. Shown on the bill so
            // a captain can see their own pad before the kitchen does.
            'picked' => $session->cartItems()->with(['product', 'variant'])->get(),
            'canMove' => Gate::allows('pos.tables.move'),
            'canWriteOff' => Gate::allows('pos.tables.write_off'),
            /*
             | Whether this branch can be paid by scanning (§8).
             |
             | Asked here so the bill screen can leave the whole panel out
             | rather than render a "show code" control that fails when it is
             | pressed. A branch with no VPA still takes UPI - it just records
             | it by reference, the way it did before there was a code.
             */
            'upiReady' => UpiQr::availableFor(CurrentShop::get()),
        ];

        return $request->header('X-Fragment')
            ? view('admin.table-bills._bill', $data)
            : view('admin.table-bills.show', $data);
    }

    /* ------------------------------------------------------------- writes */

    /**
     * Raise the bill.
     *
     * One endpoint for the whole bill and for a split: a split is the same
     * act with a list of lines attached, and two endpoints would be two
     * places for the settled quantities to be got wrong.
     */
    /**
     * A scannable code for one tender (§8).
     *
     * Its own endpoint rather than something rendered with the bill, because
     * the amount is not known until the cashier has said how the table is
     * splitting it: ₹200 in cash and ₹60 scanned needs a code for sixty, and
     * the bill fragment was built before anybody typed either number.
     *
     * Read-only and gated on `settle` rather than `view`. Nothing here moves
     * money or writes a row - the platform is not in the payment path at all,
     * see App\Support\UpiQr - but a code that says "pay this branch sixty
     * rupees" is part of taking the money, and the people who may not take it
     * have no use for one.
     */
    public function upiQr(Request $request, TableSession $session): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
        ]);

        $code = UpiQr::render(
            CurrentShop::get(),
            (float) $data['amount'],
            // What the guest sees in their banking app. The table rather than
            // the sitting id: one is on a sign in front of them and the other
            // is a number out of our database.
            'Table '.($session->table?->code ?? $session->table?->name ?? ''),
        );

        if ($code === null) {
            return ApiResponse::error(
                'This branch has no UPI ID set, so there is no code to show. Add one on the '
                ."branch's settings, or take the payment by reference."
            );
        }

        return ApiResponse::success('Ready to scan.', $code);
    }

    public function settle(Request $request, TableSession $session): JsonResponse
    {
        $data = $request->validate([
            'lines' => ['nullable', 'array'],
            'lines.*.order_item_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0'],
            'customer_id' => ['nullable', 'integer'],
            'warehouse_id' => ['nullable', 'integer'],
            'payments' => ['nullable', 'array'],
            'payments.*.method' => ['required', Rule::in(array_keys(Payment::METHODS))],
            'payments.*.amount' => ['required', 'numeric', 'min:0'],
            // transaction_ref, not "reference": it is what InvoiceService
            // writes onto the Payment row, and one name for one field means
            // the till's markup and this one cannot drift apart.
            'payments.*.transaction_ref' => ['nullable', 'string', 'max:120'],
            'payments.*.bank_name' => ['nullable', 'string', 'max:120'],
            'payments.*.cheque_date' => ['nullable', 'date'],
            'invoice_discount' => ['nullable', 'numeric', 'min:0'],
            'invoice_discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $paid = collect($data['payments'] ?? [])->sum(fn (array $p) => (float) ($p['amount'] ?? 0));

        if ($refusal = $this->refuseElevated($data, $paid)) {
            return ApiResponse::error($refusal, [], 403);
        }

        $customer = isset($data['customer_id']) ? Customer::find($data['customer_id']) : null;

        if (isset($data['customer_id']) && $customer === null) {
            return ApiResponse::error('That customer is not available in this shop.', [], 404);
        }

        try {
            $invoice = $this->bills->settle($session, [
                // An empty array from the form means "nothing chosen", which
                // is not the same as "everything" - only an absent key is.
                'lines' => $data['lines'] ?? null,
                'customer' => $customer,
                'warehouse' => isset($data['warehouse_id']) ? Warehouse::find($data['warehouse_id']) : null,
                'payments' => $data['payments'] ?? [],
                'invoice_discount' => (float) ($data['invoice_discount'] ?? 0),
                'invoice_discount_percent' => (float) ($data['invoice_discount_percent'] ?? 0),
                'notes' => $data['notes'] ?? null,
            ]);
        } catch (RuntimeException $e) {
            // The service's refusals are written for the person at the till.
            return ApiResponse::error($e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return ApiResponse::error('The bill could not be raised. Nothing has been charged.');
        }

        $left = $this->bills->summary($session->fresh())['unbilled'];

        return ApiResponse::success(
            sprintf(
                '%s · ₹%s%s',
                $invoice->number,
                number_format((float) $invoice->grand_total, 2),
                $left > 0
                    ? ' · ₹'.number_format($left, 2).' still on the table'
                    : ' · table settled',
            ),
            [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'grand_total' => (float) $invoice->grand_total,
                'due_total' => (float) $invoice->due_total,
                'remaining' => $left,
                'receipt_url' => route('admin.invoices.print', $invoice),
                'invoice_url' => route('admin.invoices.show', $invoice),
            ],
        );
    }

    /** Put this table's tickets onto another table's bill. */
    public function merge(Request $request, TableSession $session): JsonResponse
    {
        $data = $request->validate([
            'into' => ['required', 'integer'],
        ]);

        $into = TableSession::find($data['into']);

        if ($into === null) {
            return ApiResponse::error('That table is not open.', [], 404);
        }

        try {
            $this->bills->merge($session, $into);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage());
        }

        return ApiResponse::success(
            sprintf(
                'Table %s is now on table %s.',
                $session->table?->code ?? '?',
                $into->table?->code ?? '?',
            ),
            [],
            // The table this one was folded into is where the cashier now
            // needs to be - the screen they are on has just been closed.
            route('admin.table-bills.show', $into),
        );
    }

    /** Move one ticket to another table. */
    public function move(Request $request, TableSession $session): JsonResponse
    {
        $data = $request->validate([
            'order_id' => ['required', 'integer'],
            'into' => ['required', 'integer'],
        ]);

        $order = $session->orders()->find($data['order_id']);

        if ($order === null) {
            return ApiResponse::error('That ticket is not on this table.', [], 404);
        }

        $into = TableSession::find($data['into']);

        if ($into === null) {
            return ApiResponse::error('That table is not open.', [], 404);
        }

        try {
            $this->bills->moveOrder($order, $into);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage());
        }

        return ApiResponse::success(sprintf(
            '%s moved to table %s.',
            $order->order_number,
            $into->table?->code ?? '?',
        ));
    }

    /**
     * Clear a table that left without paying.
     *
     * A reason is required and not optional. This is the only action here
     * that ends a sitting with money owed and no document raised, and one
     * nobody can explain a week later is how a shop stops trusting its own
     * sales figures.
     */
    public function writeOff(Request $request, TableSession $session): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:190'],
        ], [
            'reason.required' => 'Say why this table is being cleared unpaid.',
        ]);

        try {
            $this->bills->abandon($session, $data['reason']);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage());
        }

        return ApiResponse::success(sprintf(
            'Table %s cleared.',
            $session->table?->code ?? '?',
        ));
    }

    /* --------------------------------------------- taking the order (§6) */

    /**
     * The menu, for somebody standing at the table with a pad.
     *
     * Deliberately the same cart and the same service the guest's phone uses.
     * A captain and a guest must not be able to put different things on one
     * table, and two paths into one cart would be two sets of rules about
     * sizes, add-ons and what is sold out.
     */
    public function orderForm(TableSession $session, MenuService $menu): View
    {
        return view('admin.table-bills._order', [
            'session' => $session,
            'sections' => $session->shop ? $menu->card($session->shop) : collect(),
            'picked' => $session->cartItems()->with(['product', 'variant'])->get(),
            'menu' => $menu,
        ]);
    }

    /** Put one line on the table's pad. */
    public function addToCart(Request $request, TableSession $session): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'product_variant_id' => ['nullable', 'integer'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:30'],
            'options' => ['nullable', 'array'],
            'options.*' => ['integer'],
            'note' => ['nullable', 'string', 'max:190'],
        ]);

        $product = Product::query()->find($data['product_id']);

        if ($product === null) {
            return ApiResponse::error('That dish is not on this branch\'s menu.', [], 404);
        }

        try {
            $line = $this->cart->add(
                $session,
                $product,
                $data['product_variant_id'] ?? null,
                (int) ($data['quantity'] ?? 1),
                $data['options'] ?? [],
                $data['note'] ?? null,
                // Who put it on the pad, so a guest's phone and a captain's
                // additions are distinguishable in the cart.
                'staff:'.(auth()->user()?->name ?? 'counter'),
            );
        } catch (RuntimeException $e) {
            // The service's refusals are written for whoever is ordering.
            return ApiResponse::error($e->getMessage());
        }

        return ApiResponse::success($line->title().' added.', [
            'id' => $line->id,
            'quantity' => (int) $line->quantity,
        ]);
    }

    /** Take a line off the pad before it has been sent. */
    public function removeFromCart(TableSession $session, TableCartItem $item): JsonResponse
    {
        if ((int) $item->table_session_id !== (int) $session->id) {
            return ApiResponse::error('That line is not on this table.', [], 404);
        }

        $title = $item->title();

        $this->cart->remove($session, $item);

        return ApiResponse::success($title.' removed.');
    }

    /**
     * Send the pad to the kitchen.
     *
     * Through TableOrderService, so a captain's round becomes exactly the kind
     * of ticket a guest's round does - numbered, routed to its stations, and
     * on the kitchen display in seconds.
     */
    public function send(TableSession $session): JsonResponse
    {
        try {
            $order = $this->orders->place($session, [
                'name' => $session->guest_name,
                'mobile' => $session->guest_mobile,
            ]);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage());
        }

        return ApiResponse::success(
            sprintf('%s sent to the kitchen.', $order->order_number),
            ['id' => $order->id, 'number' => $order->order_number],
        );
    }

    /* ------------------------------------------------------ modal screens */

    public function splitForm(TableSession $session): View
    {
        return view('admin.table-bills._split', [
            'session' => $session,
            'summary' => $this->bills->summary($session),
        ]);
    }

    public function mergeForm(TableSession $session): View
    {
        return view('admin.table-bills._merge', [
            'session' => $session,
            'targets' => $this->bills->mergeTargets($session),
            'orders' => $session->orders()->get(),
        ]);
    }

    public function writeOffForm(TableSession $session): View
    {
        return view('admin.table-bills._write-off', [
            'session' => $session,
            'summary' => $this->bills->summary($session),
        ]);
    }

    /* ----------------------------------------------------------- helpers */

    /**
     * The elevated rights the counter may not have.
     *
     * Word for word the pair PosController enforces, because they are the
     * same two conversations with a supervisor and a cashier who learned one
     * wording at the till should not meet a different one at a table.
     *
     * @param  array<string, mixed>  $data
     */
    private function refuseElevated(array $data, float $paid): ?string
    {
        $wantsDiscount = (float) ($data['invoice_discount'] ?? 0) > 0
            || (float) ($data['invoice_discount_percent'] ?? 0) > 0;

        if ($wantsDiscount && ! Gate::allows('pos.terminal.discount')) {
            return 'Giving a discount needs a supervisor. Ask for the discount override right.';
        }

        // A bill with nothing tendered is a credit sale whatever it is called.
        if ($paid <= 0.004 && ! Gate::allows('pos.terminal.credit')) {
            return 'Leaving a bill unpaid needs the credit sale right. Take payment in full, or ask a supervisor.';
        }

        return null;
    }
}
