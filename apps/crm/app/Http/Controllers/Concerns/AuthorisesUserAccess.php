<?php

namespace App\Http\Controllers\Concerns;

use App\Models\User;
use App\Support\CurrentTenant;

/**
 * The one answer to "may this reader touch that account?".
 *
 * It lives in a trait rather than in each controller because the guard was
 * written once, in `UserController`, and the second screen that takes a
 * {user} - the security screen, which hands back sessions, IP addresses and
 * geolocated login history - was written without it. Duplicating a check is
 * how one copy gets tightened and the other does not; there is now only the
 * one copy to tighten.
 */
trait AuthorisesUserAccess
{
    /**
     * Refuse an account this reader may not touch.
     *
     * The list is only half of it: route-model binding will hand
     * `/admin/users/9/edit` any row whose id is typed into the address bar,
     * so every screen that takes a {user} asks this first.
     */
    private function authorise(User $user): void
    {
        if (auth()->user()?->isSuperAdmin()) {
            return;
        }

        abort_unless(
            $user->tenant_id !== null
                && in_array((int) $user->tenant_id, CurrentTenant::accessibleIds(), true),
            403,
            'That account belongs to another company.',
        );
    }
}
