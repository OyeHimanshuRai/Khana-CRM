<?php

namespace App\Http\Middleware;

use App\Http\Controllers\TableScanController;
use App\Models\Shop;
use App\Models\TableQr;
use App\Services\TableSessionService;
use App\Support\ApiResponse;
use App\Support\SignInMode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Has the outlet a guest is ordering from bought CRM? (`guest.entitled`)
 *
 * The staff side has always been gated - see EnsureShopIsEntitled. The
 * guest's side was not, so an outlet whose CRM had been suspended or had
 * ended still took orders from its QR stickers and its storefront. This is
 * the same question asked for a guest, and answered by the same rule:
 * EnsureShopIsEntitled::usable() is the only place it is written.
 *
 * ---------------------------------------------------------------------------
 * Which outlet
 * ---------------------------------------------------------------------------
 *
 * Nobody is signed in, so there is no shop in context. The outlet is the one
 * the guest is at:
 *
 *   the storefront   the {shop} in the address
 *   a scan           the table the sticker's token belongs to
 *   everything else  the table of the sitting in the guest's own cookie
 *
 * Where none of those names a live outlet - a made-up token, a sitting that
 * ended - this steps aside and the page answers as it always has: a 404, or
 * "scan the code again".
 *
 * ---------------------------------------------------------------------------
 * Suspended is closed, for a guest
 * ---------------------------------------------------------------------------
 *
 * Staff keep a suspended outlet read only: they can look and export, because
 * the business's own records are theirs. A guest has nothing to read that is
 * not the start of an order, so for them only trial, active and grace are
 * open. They see one plain page saying online ordering is not available here
 * right now - not a menu whose every Add button fails.
 *
 * ---------------------------------------------------------------------------
 * What is not gated
 * ---------------------------------------------------------------------------
 *
 * On the routes: the guest's dead-end pages, the home-screen manifest, the
 * rating after the meal - none of them takes an order - and the browser's
 * "I have paid" confirmation, because money that has already moved is
 * recorded whatever the outlet's plan says. The provider's webhook records
 * it anyway; refusing the browser would only tell a guest who paid that
 * something went wrong.
 *
 * And on an install that does not sign people in through Accounts there is
 * no Accounts to have sold anything: this steps aside, and a standalone
 * install takes orders exactly as it did before the gate existed.
 */
class EnsureGuestOutletIsEntitled
{
    public function __construct(
        private readonly EnsureShopIsEntitled $gate,
        private readonly TableSessionService $sessions,
    ) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! SignInMode::throughAccounts()) {
            return $next($request);
        }

        $shop = $this->outlet($request);

        if ($shop === null || $this->gate->usable($shop)) {
            return $next($request);
        }

        return $this->refuse($request, $shop);
    }

    /** The outlet this guest is at, or null when nothing names one. */
    private function outlet(Request $request): ?Shop
    {
        $routed = $request->route('shop');

        if ($routed instanceof Shop) {
            return $routed;
        }

        $token = $request->route('token');

        if (is_string($token) && $token !== '') {
            // By the sticker's own shop_id, not through table.shop: that outlet
            // is shop-scoped, so a guest already seated at one outlet would
            // read null for another's and scan straight past this gate.
            $qr = TableQr::allShops()->where('token', $token)->first();

            return $qr === null ? null : Shop::query()->find($qr->shop_id);
        }

        $session = $this->sessions->resolve($request->session()->get(TableScanController::SESSION_KEY));

        return $session === null ? null : Shop::query()->find($session->shop_id);
    }

    private function refuse(Request $request, Shop $shop): Response
    {
        $heading = 'Online ordering is not available at this restaurant right now';
        $body = 'Please ask a member of staff, or call the restaurant, to place your order.';

        if ($request->expectsJson()) {
            return ApiResponse::error($heading.'. '.$body, [], 403);
        }

        return response()->view('table.notice', [
            'heading' => $heading,
            'body' => $body,
            'company' => $shop->name,
            'shop' => $shop,
        ], 403);
    }
}
