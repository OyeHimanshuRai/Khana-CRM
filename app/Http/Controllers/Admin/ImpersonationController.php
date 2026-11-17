<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentShop;
use App\Support\CurrentTenant;
use App\Support\Modules;
use App\Support\PlanAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * "Login as owner": the platform owner steps into a company as its Tenant
 * Owner, to see exactly what the owner sees when something looks wrong.
 *
 * The real account is remembered in the session (SESSION_KEY) and nothing
 * else about the owner is touched: no login count, no session row, no sign-in
 * history. Both ends - start and stop - are written to the Activity Log under
 * the Super Admin's own name. A Super Admin can never be the target, and the
 * way back needs nothing but the session, so it still works if the company
 * has been suspended meanwhile.
 */
class ImpersonationController extends Controller
{
    public const SESSION_KEY = 'impersonator_id';

    public function start(Request $request, Tenant $tenant): RedirectResponse
    {
        $admin = $request->user();

        abort_unless($admin->isSuperAdmin() && ! $request->session()->has(self::SESSION_KEY), 403);

        $owner = User::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->where('is_admin', true)
            ->whereHas('roles', fn ($q) => $q->where('name', 'Tenant Owner'))
            ->orderBy('id')
            ->first();

        if (! $owner || $owner->isSuperAdmin()) {
            return back()->with('error', "“{$tenant->name}” has no active owner account to sign in as.");
        }

        // Written while the actor is still the Super Admin.
        ActivityLog::record(
            'auth.impersonate.start',
            "Signed in as {$owner->name} ({$owner->email}), owner of \"{$tenant->name}\"",
            $owner,
            ['tenant_id' => $tenant->id],
        );

        Auth::guard('web')->login($owner);
        $request->session()->regenerate();
        $request->session()->put(self::SESSION_KEY, $admin->id);

        $this->forgetContext();

        return redirect()->route('admin.dashboard');
    }

    public function stop(Request $request): RedirectResponse
    {
        $adminId = $request->session()->get(self::SESSION_KEY);
        $owner = $request->user();

        $admin = $adminId ? User::query()->withoutGlobalScopes()->find($adminId) : null;

        if (! $admin || ! $admin->is_active || ! $admin->isSuperAdmin()) {
            // Not a real impersonation (or the original account is gone): end it.
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('admin.login');
        }

        Auth::guard('web')->login($admin);
        $request->session()->regenerate();
        $request->session()->forget(self::SESSION_KEY);

        $this->forgetContext();

        ActivityLog::record(
            'auth.impersonate.stop',
            "Left the owner view of {$owner?->name}",
            $owner,
        );

        return redirect()->route('admin.platform.dashboard');
    }

    private function forgetContext(): void
    {
        CurrentShop::forget();
        CurrentTenant::forget();
        PlanAccess::forget();
        Modules::forget();
    }
}
