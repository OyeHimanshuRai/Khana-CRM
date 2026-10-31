<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Tenant;
use App\Support\ApiResponse;
use App\Support\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The company switcher in the header.
 *
 * The tier above ShopSwitchController and the same idea: ungated by
 * middleware, because the authorisation is the data. CurrentTenant::set()
 * refuses anything outside accessible(), and for everyone except a Super
 * Admin that list holds exactly one row - so an ordinary user cannot switch
 * anywhere, and the switcher never renders for them at all.
 *
 * Switching also clears the shop in context, because the branch that was
 * selected belongs to the company just left. CurrentTenant::set() does that;
 * it is repeated here only in the log message, which names both.
 */
class TenantSwitchController extends Controller
{
    public function __invoke(Request $request): JsonResponse|RedirectResponse
    {
        $request->validate([
            /*
             | "all" is the consolidated view; anything else is a tenant id.
             |
             | Not typed as `string`: the switcher posts a form field, but a
             | JSON caller naturally sends `{"tenant": 3}`, and a rule that
             | accepted "3" while rejecting 3 would be a trap rather than a
             | validation. CurrentTenant::set() is what actually decides
             | whether the id is reachable.
             */
            'tenant' => ['required'],
        ]);

        $raw = (string) $request->input('tenant');

        $target = $raw === 'all' ? null : (int) $raw;

        if (! CurrentTenant::set($target)) {
            $message = 'You do not have access to that company.';

            return $request->expectsJson()
                ? ApiResponse::error($message, [], 403)
                : back()->with('error', $message);
        }

        $label = $target === null
            ? 'All companies'
            : (Tenant::query()->find($target)?->name ?? 'company #'.$target);

        ActivityLog::record('tenant.switched', "Switched to {$label}");

        $message = "Now working in {$label}.";

        /*
         | A full reload, like the shop switcher: the company in context
         | changes which branches exist, which changes every list and every
         | form default on the page.
         */
        return $request->expectsJson()
            ? ApiResponse::success($message, ['tenant' => $target], $this->returnTo($request))
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
