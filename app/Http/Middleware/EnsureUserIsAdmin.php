<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Models\UserSession;
use App\Support\ApiResponse;
use App\Support\GuardSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAdmin
{
    /**
     * Reject any authenticated user that is not an active administrator.
     *
     * Read on every request rather than only at sign-in: deactivating
     * somebody, or taking away their administrator flag, must end the
     * session they already hold, not merely stop the next login. Without
     * this a leaver who never closed the tab kept the till until the
     * session cookie happened to expire.
     *
     * Pair this with the `auth` middleware, which handles the
     * unauthenticated case by redirecting to the login screen.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        $message = match (true) {
            ! $user instanceof User || ! $user->is_admin => __('You do not have administrator access.'),
            ! $user->is_active => __('This account has been deactivated. Please contact your administrator.'),
            default => null,
        };

        if ($message === null) {
            return $next($request);
        }

        if ($user instanceof User && $request->hasSession()) {
            // Closed on the Sessions screen too, so it does not read as still signed in.
            UserSession::query()
                ->where('user_id', $user->id)
                ->where('session_id', $request->session()->getId())
                ->whereNull('logout_at')
                ->first()
                ?->terminate('system');
        }

        Auth::guard('web')->logout();

        // A storefront customer on the same browser stays signed in.
        GuardSession::end($request, otherGuard: 'customer');

        if ($request->expectsJson()) {
            return ApiResponse::error($message, [], 403);
        }

        return redirect()->route('login')->with('error', $message);
    }
}
