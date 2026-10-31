<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\TableCartItem;
use App\Models\TableSession;
use App\Services\OtpService;
use App\Services\TableCartService;
use App\Services\TableOrderService;
use App\Services\Payments\GatewayManager;
use App\Services\TableSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;

/**
 * The guest's cart and the order they send from it (§3.5 - §3.10).
 *
 * Unauthenticated, like the menu it hangs off. Every action resolves the
 * sitting from the guest's own cookie and nothing takes a session id from the
 * request - a table id in a form field would let anyone with a phone order
 * onto anyone else's bill.
 *
 * The rules about what may go in a cart live in TableCartService and are
 * checked there. This class turns a RuntimeException from it into a message
 * on the page: those are the service saying "a guest did something the menu
 * should not have offered", which is a thing to explain rather than a 500.
 */
class TableOrderController extends Controller
{
    public function __construct(
        private readonly TableSessionService $sessions,
        private readonly TableCartService $cart,
        private readonly TableOrderService $orders,
        private readonly GatewayManager $gateways,
        private readonly OtpService $otp,
    ) {}

    /* ------------------------------------------------------------- the cart */

    public function cart(Request $request): View|RedirectResponse
    {
        $session = $this->session($request);

        if ($session === null) {
            return $this->expired($request);
        }

        $summary = $this->cart->summary($session);

        return view('table.cart', [
            'session' => $session,
            'table' => $session->table,
            'shop' => $session->table?->shop,
            'company' => Setting::get('company_name', config('app.name')),
            'lines' => $summary['lines'],
            'blocked' => $summary['blocked'],
            'total' => $summary['total'],
            'count' => $summary['count'],
            'placed' => $session->orders()->with('items')->get(),
        ]);
    }

    public function add(Request $request): RedirectResponse
    {
        $session = $this->session($request);

        if ($session === null) {
            return $this->expired($request);
        }

        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'product_variant_id' => ['nullable', 'integer'],
            'quantity' => ['nullable', 'integer', 'between:1,30'],
            'options' => ['nullable', 'array', 'max:20'],
            'options.*' => ['integer'],
            'note' => ['nullable', 'string', 'max:250'],
        ]);

        /*
         | Scoped to the branch the table is in. Without this a guest at one
         | restaurant could add a dish from another's menu by id, and the
         | kitchen would get a ticket for something it does not cook.
         */
        $product = Product::query()
            ->whereKey($data['product_id'])
            ->with(['variants', 'modifiers.options'])
            ->first();

        if ($product === null) {
            return back()->with('table_error', 'That is not on the menu.');
        }

        try {
            $this->cart->add(
                session: $session,
                product: $product,
                variantId: $data['product_variant_id'] ?? null,
                quantity: (int) ($data['quantity'] ?? 1),
                optionIds: $data['options'] ?? [],
                note: $data['note'] ?? null,
                device: substr((string) $request->session()->getId(), 0, 64),
            );
        } catch (RuntimeException $e) {
            return back()->with('table_error', $e->getMessage());
        }

        return back()->with('table_notice', $product->name.' added.');
    }

    public function update(Request $request, TableCartItem $item): RedirectResponse
    {
        $session = $this->session($request);

        if ($session === null) {
            return $this->expired($request);
        }

        $data = $request->validate([
            'quantity' => ['required', 'integer', 'between:0,30'],
        ]);

        try {
            $this->cart->setQuantity($session, $item, (int) $data['quantity']);
        } catch (RuntimeException $e) {
            return back()->with('table_error', $e->getMessage());
        }

        return back();
    }

    public function remove(Request $request, TableCartItem $item): RedirectResponse
    {
        $session = $this->session($request);

        if ($session === null) {
            return $this->expired($request);
        }

        try {
            $this->cart->remove($session, $item);
        } catch (RuntimeException $e) {
            return back()->with('table_error', $e->getMessage());
        }

        return back()->with('table_notice', 'Removed.');
    }

    /* ------------------------------------------------------------ the order */

    /**
     * Send the cart to the kitchen (§3.7).
     *
     * Redirects to the order page rather than rendering it, so a guest who
     * reloads after ordering does not post a second ticket - the oldest bug
     * in ordering.
     */
    public function place(Request $request): RedirectResponse
    {
        $session = $this->session($request);

        if ($session === null) {
            return $this->expired($request);
        }

        $data = $request->validate([
            'guest_name' => ['nullable', 'string', 'max:120'],
            'guest_mobile' => ['nullable', 'string', 'max:30'],
            'note' => ['nullable', 'string', 'max:250'],
        ]);

        /*
         | Named once per sitting, not once per round. A table that said who
         | they were with their first order should not be asked again for the
         | second.
         */
        if (filled($data['guest_name'] ?? null) && blank($session->guest_name)) {
            $session->forceFill([
                'guest_name' => $data['guest_name'],
                'guest_mobile' => $data['guest_mobile'] ?? $session->guest_mobile,
            ])->save();
        }

        /*
         | The optional OTP step (§3.8).
         |
         | Before the order is created, not after: the doc says "before the
         | order is sent to the kitchen", and a ticket that reached the pass
         | and was then withdrawn is worse than one that never went.
         |
         | The cart is untouched. A guest sent here has already chosen
         | everything they want, and comes back to find it exactly as they
         | left it - see TableVerifyController.
         |
         | Asked once per sitting rather than once per round, and skipped
         | entirely when the branch has not asked for it or when nothing can
         | send a message. OtpService decides all three.
         */
        $shop = $session->table?->shop;

        if ($shop !== null && $this->otp->isRequired($shop, $session)) {
            return redirect()
                ->route('table.verify')
                ->with('verify_mobile', $data['guest_mobile'] ?? $session->guest_mobile)
                ->with('table_notice', 'Please confirm your mobile number before we send this to the kitchen.');
        }

        try {
            $order = $this->orders->place($session, [
                'name' => $data['guest_name'] ?? null,
                'mobile' => $data['guest_mobile'] ?? null,
                'note' => $data['note'] ?? null,
            ]);
        } catch (RuntimeException $e) {
            return back()->with('table_error', $e->getMessage());
        }

        return redirect()
            ->route('table.orders')
            ->with('table_notice', 'Order '.$order->order_number.' is with the kitchen.');
    }

    /**
     * What this table has ordered, and where each ticket has got to (§3.10).
     *
     * Every round, not just the last: §3.11 says they all land on one bill,
     * and a guest checking on their starters should not lose sight of the
     * mains they ordered after.
     */
    public function orders(Request $request): View|RedirectResponse
    {
        $session = $this->session($request);

        if ($session === null) {
            return $this->expired($request);
        }

        $orders = $session->orders()
            ->with(['items.modifiers'])
            ->get();

        return view('table.orders', [
            'session' => $session,
            'table' => $session->table,
            'shop' => $session->table?->shop,
            'company' => Setting::get('company_name', config('app.name')),
            'orders' => $orders,
            'total' => round((float) $orders->sum('grand_total'), 2),
            'cartCount' => (int) $session->cartItems()->sum('quantity'),
            /*
             | Whether this outlet can take money online at all (§11). False
             | is a working state: the page then says "ask a member of staff",
             | which is what most restaurants do for their first month.
             */
            'canPayOnline' => $this->gateways->isLive(),
            'checkoutScript' => (string) config('payments.gateways.'
                .config('payments.gateway').'.checkout_js'),
        ]);
    }

    /**
     * Where every round and every dish has got to, for table-live.js.
     *
     * The same session cookie as the page, and the same orders - nothing a
     * URL could carry. Small on purpose: statuses and labels, not the menu,
     * because it is asked every few seconds by every phone at every table.
     *
     * A sitting that has ended answers 410 with where to go, so the page
     * stops asking and moves on instead of polling a dead table.
     */
    public function live(Request $request): JsonResponse
    {
        $session = $this->session($request);

        if ($session === null) {
            $request->session()->forget(TableScanController::SESSION_KEY);

            return response()->json([
                'success' => false,
                'message' => 'This sitting has ended.',
                'redirect' => route('table.expired'),
            ], 410);
        }

        $orders = $session->orders()
            ->with(['items' => fn ($q) => $q->orderBy('id')])
            ->get(['id', 'order_number', 'status', 'table_session_id']);

        $label = fn (?string $status) => Order::STATUSES[$status] ?? Str::headline((string) $status);

        return json_success('', [
            'orders' => $orders->map(fn (Order $order) => [
                'id' => $order->id,
                'number' => $order->order_number,
                'status' => $order->status,
                'label' => $label($order->status),
                'lines' => $order->items
                    ->filter(fn ($line) => $line->kitchen_status !== null)
                    ->map(fn ($line) => [
                        'id' => $line->id,
                        'name' => $line->product_name,
                        'status' => $line->kitchen_status,
                        'label' => $label($line->kitchen_status),
                    ])->values(),
            ])->values(),
        ]);
    }

    /* ----------------------------------------------------------- resolution */

    private function session(Request $request): ?TableSession
    {
        return $this->sessions->resolve(
            $request->session()->get(TableScanController::SESSION_KEY)
        );
    }

    private function expired(Request $request): RedirectResponse
    {
        $request->session()->forget(TableScanController::SESSION_KEY);

        return redirect()->route('table.expired');
    }
}
