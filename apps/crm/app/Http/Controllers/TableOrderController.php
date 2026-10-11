<?php

namespace App\Http\Controllers;

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

    public function cart(Request $request): View|RedirectResponse|JsonResponse
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

    public function add(Request $request): RedirectResponse|JsonResponse
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
            // What this outlet may actually sell: its company's catalogue,
            // minus anything withdrawn from this branch. Until the catalogue
            // had an owner this line could not be written, and the comment
            // above described a filter that was not there.
            ->availableAt((int) $session->shop_id)
            ->whereKey($data['product_id'])
            ->with(['variants', 'modifiers.options'])
            ->first();

        if ($product === null) {
            $message = 'That is not on the menu.';

            if ($request->expectsJson()) {
                return json_error($message);
            }

            return back()->with('table_error', $message);
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
            if ($request->expectsJson()) {
                return json_error($e->getMessage());
            }

            return back()->with('table_error', $e->getMessage());
        }

        $message = $product->name.' added.';

        /*
         | The cart bar is the only thing on the menu derived from the cart,
         | so the new count travels back with the answer and the bar is
         | redrawn from it. A card that says "2 items" after the third tap is
         | worse than the reload this saves.
         */
        if ($request->expectsJson()) {
            return json_success($message, [
                'cart_count' => (int) $session->cartItems()->sum('quantity'),
            ]);
        }

        return back()->with('table_notice', $message);
    }

    public function update(Request $request, TableCartItem $item): RedirectResponse|JsonResponse
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

    public function remove(Request $request, TableCartItem $item): RedirectResponse|JsonResponse
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
    public function place(Request $request): RedirectResponse|JsonResponse
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
            $mobile = $data['guest_mobile'] ?? $session->guest_mobile;
            $message = 'Please confirm your mobile number before we send this to the kitchen.';

            if ($request->expectsJson()) {
                /*
                 | The number is still flashed: the verify screen reads it off
                 | the session to decide which of its two forms leads, so a
                 | guest sent there by a script has to arrive at the same one
                 | the redirect would have shown them. The sentence is not -
                 | it has already been said on the screen they are leaving.
                 */
                $request->session()->flash('verify_mobile', $mobile);

                return json_success($message, [], route('table.verify'));
            }

            return redirect()
                ->route('table.verify')
                ->with('verify_mobile', $mobile)
                ->with('table_notice', $message);
        }

        try {
            $order = $this->orders->place($session, [
                'name' => $data['guest_name'] ?? null,
                'mobile' => $data['guest_mobile'] ?? null,
                'note' => $data['note'] ?? null,
            ]);
        } catch (RuntimeException $e) {
            if ($request->expectsJson()) {
                return json_error($e->getMessage());
            }

            return back()->with('table_error', $e->getMessage());
        }

        $message = 'Order '.$order->order_number.' is with the kitchen.';

        if ($request->expectsJson()) {
            return json_success($message, [], route('table.orders'));
        }

        return redirect()
            ->route('table.orders')
            ->with('table_notice', $message);
    }

    /**
     * What this table has ordered, and where each ticket has got to (§3.10).
     *
     * Every round, not just the last: §3.11 says they all land on one bill,
     * and a guest checking on their starters should not lose sight of the
     * mains they ordered after.
     */
    public function orders(Request $request): View|RedirectResponse|JsonResponse
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

    /* ----------------------------------------------------------- resolution */

    private function session(Request $request): ?TableSession
    {
        return $this->sessions->resolve(
            $request->session()->get(TableScanController::SESSION_KEY)
        );
    }

    private function expired(Request $request): RedirectResponse|JsonResponse
    {
        $request->session()->forget(TableScanController::SESSION_KEY);

        /*
         | A sitting that ended while the page was still open. A script has no
         | screen to be sent to, so it is told in words what the redirect
         | would have shown - the same answer TablePaymentController gives.
         */
        if ($request->expectsJson()) {
            return json_error('Please scan the code on your table again.', [], 419);
        }

        return redirect()->route('table.expired');
    }
}
