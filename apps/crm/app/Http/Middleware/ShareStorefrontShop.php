<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Make the route-bound {shop} available to every storefront view without
 * every controller passing it by hand.
 *
 * Also closes a gap route-model binding leaves open: `{shop:slug}` 404s on
 * an unknown slug but not on a shop that exists and has simply been
 * deactivated - a deactivated shop's storefront must read as gone too.
 */
class ShareStorefrontShop
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $shop = $request->route('shop');

        abort_unless($shop?->is_active, 404);

        View::share('shop', $shop);

        return $next($request);
    }
}
