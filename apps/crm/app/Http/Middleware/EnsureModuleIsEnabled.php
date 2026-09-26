<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use App\Support\CurrentShop;
use App\Support\Modules;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuse a route that belongs to a line of business this branch does not run.
 *
 * The server-side half of shop-level module visibility. Hiding a sidebar
 * entry is presentation; this is the part that means anything, because a
 * disabled module has to be unreachable by typed URL and by API call, not
 * merely absent from the menu.
 *
 * **It reads the route's own `permission:` middleware rather than being told
 * again.** Every guarded admin route already declares which permission it
 * needs; config/modules.php says which permissions belong to which module.
 * Deriving one from the other means a route can never end up permission-
 * guarded but module-open, which is exactly the gap somebody would find six
 * months from now - and it costs no per-route wiring at all.
 *
 * A route with no permission middleware is not module-gated. That is the
 * dashboard, the shop switcher, sign-out and the profile: platform, not a
 * trade a shop opts into.
 *
 * Applied once, to the whole authenticated admin group.
 */
class EnsureModuleIsEnabled
{
    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $permission = $this->permissionFor($request);

        if ($permission === null || Modules::allows($permission)) {
            return $next($request);
        }

        return $this->refuse($request, $permission);
    }

    /**
     * The permission this route declares, if it declares one.
     *
     * Only the first is read. A route guarded by two permissions does not
     * exist in this app, and guessing which of them should decide the module
     * would be worse than the honest single answer.
     */
    private function permissionFor(Request $request): ?string
    {
        $route = $request->route();

        if ($route === null) {
            return null;
        }

        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware) || ! str_starts_with($middleware, 'permission:')) {
                continue;
            }

            $names = explode(',', substr($middleware, strlen('permission:')));

            return trim($names[0]) ?: null;
        }

        return null;
    }

    private function refuse(Request $request, string $permission): Response
    {
        $module = $this->labelFor($permission);
        $shop = CurrentShop::get();

        $message = $shop === null
            ? sprintf('%s is not switched on for any of your branches.', $module)
            : sprintf('%s is not switched on for %s.', $module, $shop->name);

        /*
         | 403 rather than 404. The route exists and the person may well hold
         | the permission for it - what is missing is the shop's decision to
         | run that line of business, and saying so is what lets an owner go
         | and switch it on instead of filing a bug.
         */
        if ($request->expectsJson()) {
            return ApiResponse::error($message, [], 403);
        }

        abort(403, $message);
    }

    /** The module's own label, so the refusal names what to switch on. */
    private function labelFor(string $permission): string
    {
        $catalogue = Modules::catalogue();

        $labels = array_map(
            fn (string $key) => $catalogue[$key]['label'] ?? $key,
            Modules::ownersFor($permission),
        );

        return $labels === [] ? 'That module' : implode(' / ', $labels);
    }
}
