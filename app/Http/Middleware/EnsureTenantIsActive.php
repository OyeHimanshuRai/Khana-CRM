<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\AccountAccess;
use App\Support\ApiResponse;
use App\Support\GuardSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantIsActive
{
    /**
     * Routes a lapsed account's owner may still use: the billing screen and
     * its endpoints (pay, ask for a renewal), their own profile, and signing
     * out. The profile because RequireStrongPassword sends a well-known
     * password there first - without it the two rules bounced the owner
     * between each other forever.
     */
    private const BILLING_ONLY = ['admin.subscription.*', 'admin.profile.*', 'admin.logout'];

    /**
     * Keep a suspended or lapsed company's staff out, without touching their data.
     *
     * Suspension is how an unpaid or disputed SaaS account is handled: the
     * business keeps every invoice, payment and stock balance it ever
     * recorded, and simply cannot trade until the account is settled. That
     * is the whole reason Tenant::suspend() sets a flag rather than deleting
     * anything.
     *
     * Who gets what is decided by AccountAccess, which the sign-in form asks
     * too: the owner of a lapsed account is kept in on the billing screen
     * (with or without online payment - without it, they can ask for a
     * renewal there), everybody else is signed out with the reason.
     *
     * Pair with `auth` and `admin`, which handle the unauthenticated and
     * non-administrator cases before this runs.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        [$state, $message] = AccountAccess::decide($user);

        return match ($state) {
            AccountAccess::OPEN => $next($request),
            AccountAccess::BILLING_ONLY => $this->billingOnly($request, $next, $message),
            default => $this->refuse($request, $message),
        };
    }

    /**
     * Let the billing screen through and send everything else to it.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    private function billingOnly(Request $request, Closure $next, string $message): Response
    {
        if ($request->routeIs(...self::BILLING_ONLY)) {
            return $next($request);
        }

        if ($request->expectsJson() || ! $request->hasSession()) {
            return ApiResponse::error($message, [], 403);
        }

        return redirect()->route('admin.subscription.mine')->with('error', $message);
    }

    /**
     * Sign the user out and send them back to the login screen.
     *
     * Logging out rather than merely redirecting: leaving a live session on
     * a suspended account means every subsequent request has to be caught by
     * this same check, and one route that forgets the middleware is a way
     * back in.
     */
    private function refuse(Request $request, string $message): Response
    {
        /*
         | An API call carries a token, not a session: there is nothing to
         | sign out of, and touching the session store would throw. The token
         | stays valid on purpose - it starts working again the moment the
         | account does, without the integrator having to mint a new one.
         */
        if (! $request->hasSession()) {
            return ApiResponse::error($message, [], 403);
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
