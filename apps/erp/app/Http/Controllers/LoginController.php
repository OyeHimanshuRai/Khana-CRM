<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\SignInMode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * The way in, and out.
 *
 * Nobody has a password here: the page offers "Sign in with Khana Accounts",
 * and the kit's /sso/login does the rest (App\Auth\AccountsSignIn finishes
 * it). It is a page rather than a straight redirect so that a refused
 * sign-in has somewhere to say why.
 */
final class LoginController
{
    public function show(): View
    {
        return view('auth.login', [
            'throughAccounts' => SignInMode::throughAccounts(),
            'people' => SignInMode::localAllowed()
                ? User::query()->where('is_active', true)->orderBy('name')->limit(20)->get()
                : collect(),
        ]);
    }

    /** A developer's machine with no Accounts running - see SignInMode. */
    public function local(Request $request): RedirectResponse
    {
        abort_unless(SignInMode::localAllowed(), 404);

        $user = User::query()->where('is_active', true)->findOrFail((int) $request->input('user'));

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('home'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
