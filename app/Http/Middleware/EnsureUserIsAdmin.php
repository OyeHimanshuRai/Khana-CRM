<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAdmin
{
    /**
     * Reject any authenticated user that is not an administrator.
     *
     * Pair this with the `auth` middleware, which handles the
     * unauthenticated case by redirecting to the login screen.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->is_admin) {
            Auth::guard('web')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            $message = __('You do not have administrator access.');

            if ($request->expectsJson()) {
                return ApiResponse::error($message, [], 403);
            }

            return redirect()->route('admin.login')->with('error', $message);
        }

        return $next($request);
    }
}
