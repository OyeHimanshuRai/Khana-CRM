<?php

namespace App\Http\Middleware;

use App\Models\Category;
use App\Support\CurrentTenant;
use App\Support\Modules;
use App\Support\PlanAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Make the route-bound {shop} available to every storefront view without
 * every controller passing it by hand.
 *
 * Also the storefront's front door, asking what the admin panel asks:
 *
 *   - a deactivated shop's storefront reads as gone (route-model binding
 *     404s on an unknown slug, not on a shop that exists and is switched off);
 *   - so does one that does not run the Online storefront module - switched
 *     off by the outlet, or not part of its plan;
 *   - one that is not trading (company suspended, subscription lapsed - see
 *     PlanAccess::isTrading) shows a "closed for now" page instead of a menu
 *     a customer would fill a cart from and be refused at checkout. Their
 *     own past orders and account stay reachable.
 */
class ShareStorefrontShop
{
    /** What a customer can still do while the shop is closed for now. */
    private const OPEN_WHILE_CLOSED = [
        'shop.login', 'shop.login.store', 'shop.logout', 'shop.password.*',
        'shop.orders.*', 'shop.account.*',
    ];

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $shop = $request->route('shop');

        abort_unless($shop?->is_active, 404);
        abort_unless(in_array('storefront', Modules::forShop($shop), true), 404);

        // The storefront sells this branch's company's catalogue and no other.
        CurrentTenant::pinForGuest($shop->tenant_id);

        View::share('shop', $shop);
        View::share('shopHeaderCategories', Category::query()
            ->roots()
            ->active()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->limit(6)
            ->get());

        if (! PlanAccess::isTrading($shop->id) && ! $request->routeIs(...self::OPEN_WHILE_CLOSED)) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => 'This shop is not taking orders online right now.'], 503);
            }

            return response()->view('shop.closed', [], 503);
        }

        return $next($request);
    }
}
