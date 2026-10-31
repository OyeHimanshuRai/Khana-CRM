<?php

namespace App\Http\Controllers;

use App\Mail\PasswordResetMail;
use App\Models\ActivityLog;
use App\Models\User;
use App\Models\UserSession;
use App\Support\ApiResponse;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;

/**
 * "Forgot your password?" for owners, staff and the platform administrator.
 *
 * The answer to "send me a link" is the same whether or not the address has
 * an account, so the form cannot be used to find out who is a customer.
 * Only an active administrator account is sent anything: there is no point
 * resetting the password of an account that cannot sign in.
 *
 * Setting the new password signs the account out everywhere else - a
 * forgotten password is often a stolen one - and confirms a sign-up's email,
 * because opening the link proves the address is theirs (see
 * User::needsEmailVerification). That is also how the real owner of an
 * address takes back an account somebody else opened in their name.
 */
class PasswordResetController extends Controller
{
    private const BROKER = 'users';

    private const SENT = 'If that address has an account, a link to reset the password is on its way.';

    public function request(): View
    {
        return view('auth.forgot-password');
    }

    public function email(Request $request): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email', 'max:255']]);
        $email = Str::lower(trim($data['email']));

        $user = User::query()->withoutGlobalScopes()->where('email', $email)->first();

        if ($user !== null && $user->is_active && $user->is_admin) {
            // The broker's own throttle answers RESET_THROTTLED; that is not something to tell a stranger either.
            Password::broker(self::BROKER)->sendResetLink(['email' => $email], function (User $user, string $token) {
                Mail::to($user->email, $user->name)->send(new PasswordResetMail($user, $token));
                ActivityLog::record('auth.password_reset_requested', 'Asked for a password reset link', $user);
            });
        }

        return $request->expectsJson()
            ? ApiResponse::success(self::SENT)
            : back()->with('status', self::SENT);
    }

    public function edit(Request $request, string $token): View
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => $request->string('email')->toString(),
        ]);
    }

    public function update(Request $request): JsonResponse|RedirectResponse
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);

        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)->letters()->numbers()],
        ]);

        $status = Password::broker(self::BROKER)->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                    // Opening the link proved the address is theirs.
                    'email_verified_at' => $user->email_verified_at ?? now(),
                ])->save();

                UserSession::query()->where('user_id', $user->id)->whereNull('logout_at')->get()
                    ->each(fn (UserSession $session) => $session->terminate('password_reset'));

                ActivityLog::record('auth.password_reset', 'Reset their password from an emailed link', $user);

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            $message = __($status);

            return $request->expectsJson()
                ? ApiResponse::error($message, ['email' => [$message]])
                : back()->withInput($request->only('email'))->withErrors(['email' => $message]);
        }

        $target = User::query()->withoutGlobalScopes()->where('email', $request->input('email'))->first()?->signInRoute() ?? route('login');
        $note = 'Password changed. Sign in with the new one.';

        return $request->expectsJson()
            ? json_success($note, [], $target)
            : redirect()->to($target)->with('status', $note);
    }
}
