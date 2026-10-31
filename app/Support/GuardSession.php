<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Ending a session after one guard signs out - without signing out the other.
 *
 * Staff (`web`, /login and /admin/login) and a storefront's customers
 * (`customer`, /shop/{slug}/login) are separate guards in one session cookie. Invalidating the session throws away both, so
 * a waiter checking the storefront on the counter PC would sign the till out
 * too. When the other guard is still signed in, the session id is rotated
 * instead - which is the part of logout that matters for safety - and its
 * data, including that guard's login, is kept. Otherwise it is thrown away.
 */
final class GuardSession
{
    public static function end(Request $request, string $otherGuard): void
    {
        if (Auth::guard($otherGuard)->check()) {
            $request->session()->migrate(true);
        } else {
            $request->session()->invalidate();
        }

        $request->session()->regenerateToken();
    }
}
