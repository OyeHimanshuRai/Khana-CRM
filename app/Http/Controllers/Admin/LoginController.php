<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LoginRequest;
use App\Models\ActivityLog;
use App\Models\LoginHistory;
use App\Models\UserSession;
use App\Support\CurrentShop;
use App\Support\GuardSession;
use App\Support\SiteBrand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class LoginController extends Controller
{
    /**
     * /admin/login - the platform owner's sign-in, and nobody else's.
     */
    public function create(): View
    {
        return view('admin.auth.login');
    }

    /**
     * /login - the sign-in every restaurant's owner and staff use.
     *
     * The same accounts table and the same back office as /admin/login; a
     * different door, so the platform's own sign-in is not the page a
     * restaurant is handed. See LoginRequest::authenticate().
     */
    public function restaurant(): View
    {
        return view('auth.login', ['site' => SiteBrand::get()]);
    }

    public function store(LoginRequest $request): RedirectResponse|JsonResponse
    {
        return $this->signIn($request, LoginRequest::PLATFORM_DOOR);
    }

    public function storeRestaurant(LoginRequest $request): RedirectResponse|JsonResponse
    {
        return $this->signIn($request, LoginRequest::RESTAURANT_DOOR);
    }

    /**
     * Sign in through one of the two doors.
     *
     * Answers JSON for the AJAX form and a plain redirect when JavaScript is
     * unavailable, so the login screen keeps working either way.
     */
    private function signIn(LoginRequest $request, string $door): RedirectResponse|JsonResponse
    {
        $request->authenticate($door);

        // Issue a fresh session id so a pre-login session cannot be replayed.
        $request->session()->regenerate();

        $user = $request->user();

        // Opened after regenerate(), so the row carries the id the browser
        // will actually present - which is what makes a remote logout work.
        UserSession::start($user, $request);

        LoginHistory::record(LoginHistory::SUCCESS, $user->email, $user, null, $request);

        $user->forceFill([
            'last_login_at' => now(),
            'last_seen_at' => now(),
            'login_count' => $user->login_count + 1,
        ])->saveQuietly();

        ActivityLog::record('auth.login', 'Signed in', $user);

        // The shop context was resolved (to nothing) while this request was
        // still a guest. Drop it so the first authenticated page reads the
        // account's own shops rather than the memoised empty answer.
        CurrentShop::forget();

        /*
         | The URL the guest was originally headed to - if it is in the admin
         | app. The intended URL is shared with the storefront's customer
         | guard, and a shop page left over from a customer's visit is not
         | where staff should land.
         */
        $intended = (string) $request->session()->pull('url.intended', '');
        $target = $intended !== '' && ($intended === url('/admin') || str_starts_with($intended, url('/admin').'/'))
            ? $intended
            : route($user->isSuperAdmin() ? 'admin.platform.dashboard' : 'admin.dashboard');

        if ($request->expectsJson()) {
            return json_success('Signed in. Taking you to your dashboard…', [], $target);
        }

        return redirect()->to($target);
    }

    /**
     * Log the administrator out of the application.
     */
    public function destroy(Request $request): RedirectResponse
    {
        // Recorded before logout so the entry still carries the actor.
        ActivityLog::record('auth.logout', 'Signed out', $request->user());

        // Back to the door they came in by.
        $signIn = $request->user()?->signInRoute() ?? route('login');

        // Close this device's row; the others stay open on purpose.
        UserSession::query()
            ->where('session_id', $request->session()->getId())
            ->whereNull('logout_at')
            ->each(fn (UserSession $session) => $session->forceFill([
                'logout_at' => now(),
                'ended_by' => 'user',
            ])->save());

        Auth::guard('web')->logout();

        // A customer signed in on the same browser stays signed in.
        GuardSession::end($request, otherGuard: 'customer');

        return redirect()->to($signIn)->with('status', 'You have been signed out.');
    }
}
