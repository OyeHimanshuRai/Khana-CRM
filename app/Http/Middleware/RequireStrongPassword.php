<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keep somebody who signed in with a well-known password on the screen where
 * they can change it.
 *
 * The flag is set at sign-in (LoginRequest), while the plain text is in hand,
 * so this costs a session read and no hashing. Where enforcement is on (the
 * default in production) everything except the profile and sign-out is closed
 * until the password is changed; elsewhere the sign-in only raises the
 * warning banner, because a developer's laptop with "password" on it is a
 * choice, not an incident.
 */
class RequireStrongPassword
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession() || ! $request->session()->get('password_is_weak')) {
            return $next($request);
        }

        if (! config('app.force_default_password_change')) {
            return $next($request);
        }

        if ($request->routeIs('admin.profile.*', 'admin.logout')) {
            return $next($request);
        }

        $message = 'You are signed in with a well-known password. Choose a new one to continue.';

        if ($request->expectsJson()) {
            return ApiResponse::error($message, [], 403);
        }

        return redirect()->route('admin.profile.edit')->with('error', $message);
    }
}
