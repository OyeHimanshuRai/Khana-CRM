<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Shop;
use App\Support\ApiResponse;
use App\Support\CurrentShop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The shop switcher in the header.
 *
 * Ungated by middleware on purpose: every signed-in admin may change which
 * of *their own* shops they are working in. What they may not do is pick one
 * they have no pivot row for, and CurrentShop::set() is what refuses that -
 * the authorisation lives with the data, not with a permission name.
 *
 * The switch is logged. "Which shop was this user in when they voided that
 * invoice" is a question an audit will ask.
 */
class ShopSwitchController extends Controller
{
    public function __invoke(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            // "all" is the consolidated view; anything else is a shop id.
            'shop' => ['required', 'string'],
        ]);

        $target = $validated['shop'] === 'all'
            ? null
            : (int) $validated['shop'];

        if (! CurrentShop::set($target)) {
            $message = 'You do not have access to that shop.';

            return $request->expectsJson()
                ? ApiResponse::error($message, [], 403)
                : back()->with('error', $message);
        }

        $label = $target === null
            ? 'All shops'
            : (Shop::find($target)?->name ?? 'shop #'.$target);

        ActivityLog::record('shop.switched', "Switched to {$label}");

        $message = "Now working in {$label}.";

        /*
         | A full reload rather than a fragment swap: the shop context
         | changes every list, every stat and every form default on the page,
         | so re-rendering the whole thing is both simpler and honest about
         | what just happened.
         */
        return $request->expectsJson()
            ? ApiResponse::success($message, ['shop' => $target], $this->returnTo($request))
            : back()->with('status', $message);
    }

    /**
     * Where to send the browser after switching.
     *
     * Back to the page they were on, but only when that page is ours - the
     * redirect is handed straight to window.location by app.js, so an
     * attacker-supplied Referer must not be able to steer it off-site.
     */
    private function returnTo(Request $request): string
    {
        $referer = (string) $request->headers->get('referer');
        $fallback = route('admin.dashboard');

        if ($referer === '') {
            return $fallback;
        }

        $host = parse_url($referer, PHP_URL_HOST);

        return $host !== null && $host === $request->getHost()
            ? $referer
            : $fallback;
    }
}
