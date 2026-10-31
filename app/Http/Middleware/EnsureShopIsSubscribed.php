<?php

namespace App\Http\Middleware;

use App\Models\Subscription;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\CurrentShop;
use App\Support\PlanAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureShopIsSubscribed
{
    /**
     * Routes that stay open for an outlet whose subscription has lapsed.
     *
     * Enough to see where things stand, move to another outlet, pay and sign
     * out. The dashboard is in the list because it is where a refused request
     * is sent back to; without it the redirect would loop. The subscription
     * screens are in it because the person who has to settle the bill has to
     * be able to reach the place where they do.
     */
    private const OPEN = [
        'admin.dashboard',
        'admin.logout',
        'admin.shops.switch',
        'admin.tenants.switch',
        'admin.profile.*',
        'admin.notifications.*',
        'admin.subscription.*',
    ];

    /**
     * Stop an outlet whose own subscription has lapsed, and only that outlet.
     *
     * The tenant-level check (EnsureTenantIsActive) signs everyone out when
     * the business's blanket subscription ends. This one is narrower: it
     * refuses the request, keeps the session, and leaves every other outlet
     * the user can reach working. "All shops" view has no single outlet to
     * judge, so it passes; the outlet-specific actions inside it are covered
     * once somebody switches in.
     *
     * Past due is allowed through, as in EnsureTenantIsActive: the grace
     * window exists so a restaurant is not locked out mid-service.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->isSuperAdmin() || $request->routeIs(...self::OPEN)) {
            return $next($request);
        }

        $shop = CurrentShop::get();

        if ($shop === null) {
            return $next($request);
        }

        $subscription = PlanAccess::subscriptionForShop($shop->id);

        if ($subscription === null || $subscription->isUsable()) {
            return $next($request);
        }

        $message = $subscription->state() === Subscription::CANCELLED
            ? __('The subscription for :shop has been cancelled. Please contact support.', ['shop' => $shop->name])
            : __('The subscription for :shop ended on :date. Please contact support to renew.', [
                'shop' => $shop->name,
                'date' => $subscription->ends_at?->format('j M Y') ?? '',
            ]);

        if ($request->expectsJson() || ! $request->hasSession()) {
            return ApiResponse::error($message, [], 403);
        }

        // Whoever can settle it goes straight to the plans; everybody else to the dashboard.
        return redirect()
            ->route($user->can('settings.subscriptions.pay') ? 'admin.subscription.mine' : 'admin.dashboard')
            ->with('error', $message);
    }
}
