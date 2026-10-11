<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\ApiResponse;
use App\Support\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantIsActive
{
    /**
     * Keep a suspended company's staff out, without touching their data.
     *
     * Suspension is how an unpaid or disputed SaaS account is handled: the
     * business keeps every invoice, payment and stock balance it ever
     * recorded, and simply cannot trade until the account is settled. That
     * is the whole reason suspension is a status rather than a deletion.
     *
     * Super Admin is exempt on purpose. The reason to open a suspended
     * account is to look at why it was suspended and put it right, so
     * locking the one role that can do that out of it would make the feature
     * useless.
     *
     * Pair with `auth` and `admin`, which handle the unauthenticated and
     * non-administrator cases before this runs.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->isSuperAdmin()) {
            return $next($request);
        }

        /*
         | Resolved through CurrentTenant rather than read off the account, so
         | this agrees with the scope about who somebody is.
         |
         | The case that matters is an account with no company on a
         | single-company install: accounts that predate the tenants table,
         | and anybody created by a script that had no company to name. There
         | "no company" and "the only company" are not different answers, and
         | CurrentTenant says so. Reading user->tenant directly would turn
         | every one of those accounts out at the door on upgrade.
         |
         | The fallback stops the moment a second business exists, which is
         | the safe direction: an unassigned account then resolves to null and
         | is refused below rather than being handed a choice of whose data to
         | read.
         */
        $tenant = CurrentTenant::get();

        /*
         | A null tenant on a non-super-admin is a broken account, not a free
         | pass. It reaches no shops either way - see CurrentShop - so it
         | would meet nothing but empty screens; saying so plainly is kinder
         | than letting someone wonder where their data went.
         */
        if ($tenant === null) {
            return $this->refuse(
                $request,
                __('Your account is not linked to a company. Ask an administrator to assign one.'),
            );
        }

        /*
         | Suspended or closed, by Accounts (§21).
         |
         | Suspending a business is Accounts' decision now - it is where the
         | account is paid for - and it reaches CRM as accounts.tenant.upserted
         | in the kit's reference copy. "Its staff cannot sign in" is what the
         | contract says suspension means, so staff who were already signed in
         | are turned out here too, rather than carrying on until their
         | session happens to expire.
         |
         | The local flag is still honoured: it is how a business that was
         | switched off before the split still reads.
         |
         | What a business has *bought* is a per-outlet question and not this
         | gate's: see EnsureShopIsEntitled, which refuses without signing
         | anybody out. No reference row yet means Accounts has not spoken,
         | which is not a suspension.
         */
        $status = $tenant->ulid === null
            ? null
            : DB::table('khana_tenants')->where('id', $tenant->ulid)->value('status');

        if ($tenant->isSuspended() || in_array($status, ['suspended', 'closed'], true)) {
            return $this->refuse($request, __(
                'access to :company is currently suspended. Please contact support.',
                ['company' => $tenant->name],
            ));
        }

        return $next($request);
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
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            return ApiResponse::error($message, [], 403);
        }

        return redirect()->route('admin.login')->with('error', $message);
    }
}
