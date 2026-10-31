<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuse a customer session that does not belong to the shop in the URL,
 * and undo a side effect of Laravel's own `auth:customer` middleware.
 *
 * A customer is authenticated per shop (Customer belongsTo one Shop), and
 * ShopScope does not help here - it no-ops for anything but the `web`
 * guard (see its docblock), so a Shop-A customer's session would otherwise
 * remain "logged in" on Shop-B's storefront and could reach Shop-B's
 * checkout/orders/account routes. This is the concrete mechanism that keeps
 * that from happening: pair with the `customer` guard, after it.
 *
 * The second job here is less obvious. `Illuminate\Auth\Middleware\
 * Authenticate::authenticate()` calls `Auth::shouldUse($guard)` the moment
 * `auth:customer` succeeds - that is how a bare `Auth::user()` "just works"
 * for whichever guard actually authenticated the request. It also means the
 * bare `Auth::hasUser()` that BelongsToShop's saving() guard reads (see its
 * docblock) flips true for the rest of a checkout/order/account request,
 * with `Auth::user()` returning a Customer rather than a User -
 * CurrentShop::accessible() then sees something that is not a User, reads
 * that as "no accessible shops", and refuses to save the new Order. Setting
 * the default back to `web` here restores the reading every other
 * storefront controller already assumes (explicit ::forShop() scoping,
 * never the bare guard) without touching BelongsToShop itself, which the
 * admin app's tenant isolation depends on unchanged.
 */
class EnsureCustomerBelongsToShop
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $shop = $request->route('shop');
        $customer = Auth::guard('customer')->user();

        if ($customer && $shop && (int) $customer->shop_id !== (int) $shop->id) {
            Auth::guard('customer')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('shop.login', ['shop' => $shop])
                ->with('error', 'Please sign in to this shop\'s account.');
        }

        Auth::shouldUse('web');

        return $next($request);
    }
}
