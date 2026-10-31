<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureEmailIsConfirmed
{
    /** What an unconfirmed account may still reach: the confirmation screen, its resend, and signing out. */
    private const OPEN = ['admin.verification.*', 'admin.logout'];

    /**
     * Keep a self-signed-up account shut until its email is confirmed.
     *
     * See User::needsEmailVerification for who is asked. Everything else
     * redirects to the screen that says where the link went and sends it
     * again; an AJAX call gets the same sentence as a 403.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->needsEmailVerification() || $request->routeIs(...self::OPEN)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return ApiResponse::error(
                sprintf('Please confirm your email address first. We sent a link to %s.', $user->email),
                [],
                403,
            );
        }

        return redirect()->route('admin.verification.notice');
    }
}
