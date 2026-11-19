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
use App\Support\Realm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * "Login as owner": the platform owner steps into a company as its Tenant
 * Owner, to see exactly what the owner sees when something looks wrong.
 *
 * The Super Admin and a restaurant live in separate sessions (see
 * App\Support\Realm), so this does not swap who is signed in. start() leaves
 * the Super Admin's own session exactly as it was and hands the browser a
 * one-time link; accept() - on the restaurant's side - signs the owner in there,
 * on the normal URLs, and remembers who is looking. stop() ends only that
 * restaurant session and sends the browser back to the Super Admin, who never
 * signed out.
 *
 * The link is a 64-character random token, good for one minute and one use,
 * and it can only be minted by a signed-in Super Admin.
 */
class ImpersonationController extends Controller
{
    /** Held in the restaurant-side session: who is looking, for the banner and for stop(). */
    public const SESSION_KEY = 'impersonator_id';

    private const TOKEN_TTL_SECONDS = 60;

    public function start(Request $request, Tenant $tenant): RedirectResponse
    {
        $admin = $request->user();

        abort_unless($admin->isSuperAdmin(), 403);

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

        ActivityLog::record(
            'auth.impersonate.start',
            "Signed in as {$owner->name} ({$owner->email}), owner of \"{$tenant->name}\"",
            $owner,
            ['tenant_id' => $tenant->id],
        );

        $token = Str::random(64);

        Cache::put(self::cacheKey($token), ['owner' => $owner->id, 'admin' => $admin->id], self::TOKEN_TTL_SECONDS);

        return redirect()->to(route('impersonate.accept', ['token' => $token]));
    }

    /** The restaurant side: sign the owner in here and show the owner's dashboard. */
    public function accept(Request $request, string $token): RedirectResponse
    {
        $grant = Cache::pull(self::cacheKey($token));

        abort_unless(is_array($grant), 403, 'This link has expired. Open the company from the Super Admin again.');

        $owner = User::query()->withoutGlobalScopes()->find($grant['owner']);
        $admin = User::query()->withoutGlobalScopes()->find($grant['admin']);

        abort_unless(
            $owner && $owner->is_active && $owner->is_admin && ! $owner->isSuperAdmin()
                && $admin && $admin->is_active && $admin->isSuperAdmin(),
            403,
        );

        // Whoever was signed in on this side is replaced by the owner.
        Auth::guard('web')->logout();

        Auth::guard('web')->login($owner);
        $request->session()->regenerate();
        $request->session()->put(self::SESSION_KEY, $admin->id);

        $this->forgetContext();

        return redirect()->route('admin.dashboard');
    }

    public function stop(Request $request): RedirectResponse
    {
        $owner = $request->user();

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $this->forgetContext();

        ActivityLog::record(
            'auth.impersonate.stop',
            "Left the owner view of {$owner?->name}",
            $owner,
        );

        // The Super Admin's own session was never touched; this is their door.
        return redirect()->to(Realm::platformRoute('admin.platform.dashboard'));
    }

    private static function cacheKey(string $token): string
    {
        return 'impersonate:'.hash('sha256', $token);
    }

    private function forgetContext(): void
    {
        CurrentShop::forget();
        CurrentTenant::forget();
        PlanAccess::forget();
        Modules::forget();
    }
}
