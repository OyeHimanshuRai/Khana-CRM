<?php

namespace App\Http\Middleware;

use App\Models\BlockedIp;
use App\Models\LoginHistory;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuse a request coming from a blocked address.
 *
 * Sits on the login routes rather than inside the controller so the block
 * is enforced before any credential is read - a blocked address cannot use
 * the form to probe which emails exist.
 *
 * Only global blocks (user_id null) can be enforced here, since the account
 * being attempted is not known until the credentials are validated. A block
 * scoped to one account is enforced in LoginRequest, once the email
 * identifies which account that is.
 */
class EnsureIpNotBlocked
{
    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $block = BlockedIp::query()
            ->enforced()
            ->whereNull('user_id')
            ->where('ip_address', $request->ip())
            ->first();

        if (! $block) {
            return $next($request);
        }

        // Only worth a row when something was actually submitted; a blocked
        // address reloading the form would otherwise flood the table.
        if ($request->isMethod('POST')) {
            LoginHistory::record(
                LoginHistory::BLOCKED,
                $request->input('email'),
                null,
                'ip_blocked',
                $request,
            );
        }

        $message = __('Sign-in from this network has been blocked. Contact your administrator.');

        if ($request->expectsJson()) {
            return ApiResponse::error($message, [], 403);
        }

        abort(403, $message);
    }
}
