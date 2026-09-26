<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;

/**
 * The "set your password" link from the welcome email.
 *
 * Uses Laravel's password broker rather than a token of our own, so the
 * one-time semantics, the hashing and the expiry are the framework's
 * problem. The `welcome` broker is the same table with a much longer
 * window - see config/auth.php.
 */
class PasswordSetupController extends Controller
{
    private const BROKER = 'welcome';

    /**
     * Show the form the emailed link points at.
     *
     * The token is not verified here - only on submit. Telling a visitor up
     * front which tokens are valid would turn this page into an oracle.
     */
    public function edit(Request $request, string $token): View
    {
        return view('admin.auth.set-password', [
            'token' => $token,
            'email' => $request->string('email')->toString(),
        ]);
    }

    /**
     * Set the password and sign the user in.
     *
     * Answers JSON for the AJAX form and a redirect without JavaScript, the
     * same as the login screen.
     */
    public function update(Request $request): RedirectResponse|JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $status = Password::broker(self::BROKER)->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                ActivityLog::record('user.password_set', 'Set their password from a welcome link', $user);

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            $message = __($status);

            if ($request->expectsJson()) {
                return ApiResponse::error($message, ['email' => [$message]]);
            }

            return back()->withInput($request->only('email'))->withErrors(['email' => $message]);
        }

        $target = route('admin.login');
        $note = 'Password set. You can sign in now.';

        if ($request->expectsJson()) {
            return json_success($note, [], $target);
        }

        return redirect()->to($target)->with('status', $note);
    }
}
