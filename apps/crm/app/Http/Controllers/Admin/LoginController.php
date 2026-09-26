<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LoginRequest;
use App\Models\ActivityLog;
use App\Models\LoginHistory;
use App\Models\UserSession;
use App\Support\CurrentShop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class LoginController extends Controller
{
    /**
     * Display the admin login form.
     */
    public function create(): View
    {
        return view('admin.auth.login');
    }

    /**
     * Handle an incoming admin authentication request.
     *
     * Answers JSON for the AJAX form and a plain redirect when JavaScript is
     * unavailable, so the login screen keeps working either way.
     */
    public function store(LoginRequest $request): RedirectResponse|JsonResponse
    {
        $request->authenticate();

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

        // Resolves (and consumes) any URL the guest was originally headed to.
        $target = redirect()->intended(route('admin.dashboard'))->getTargetUrl();

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

        // Close this device's row; the others stay open on purpose.
        UserSession::query()
            ->where('session_id', $request->session()->getId())
            ->whereNull('logout_at')
            ->each(fn (UserSession $session) => $session->forceFill([
                'logout_at' => now(),
                'ended_by' => 'user',
            ])->save());

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login')->with('status', 'You have been signed out.');
    }
}
