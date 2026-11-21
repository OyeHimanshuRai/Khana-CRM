<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\VerifyEmailMail;
use App\Models\ActivityLog;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Confirming the email of an account a business made for itself.
 *
 * Nobody is signed in by signing up: the link in the sign-up email confirms the
 * address, and only then can the account sign in (see User::needsEmailVerification
 * and LoginRequest). Whoever did not get the email, or lost it, asks for it again
 * from the sign-in page - with the password, so an address cannot be mailed at
 * by a stranger.
 */
class EmailVerificationController extends Controller
{
    /** Send the link again, from the sign-in page. Throttled on the route. */
    public function resend(Request $request): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->withoutGlobalScopes()
            ->whereRaw('LOWER(email) = ?', [Str::lower($data['email'])])
            ->first();

        // One answer for "no such address", "wrong password" and "nothing to confirm".
        if (! $user || ! Hash::check($data['password'], (string) $user->password) || ! $user->needsEmailVerification()) {
            return $request->expectsJson()
                ? ApiResponse::error('Enter the email and password you registered with.', ['email' => ['Enter the email and password you registered with.']], 422)
                : back()->withErrors(['email' => 'Enter the email and password you registered with.']);
        }

        Mail::to($user->email, $user->name)->send(new VerifyEmailMail($user));
        ActivityLog::record('auth.verification_sent', 'Sent the email confirmation link again', $user);

        $message = "We sent a new verification link to {$user->email}. Open it to activate your account.";

        return $request->expectsJson()
            ? ApiResponse::success($message)
            : back()->with('status', $message);
    }

    /**
     * The link from the email. The route's `signed` middleware has already
     * refused a forged or expired URL; the hash ties it to the address the
     * account has now, so a link sent before the email was changed is dead.
     */
    public function verify(Request $request, int $id, string $hash): RedirectResponse
    {
        $user = User::query()->withoutGlobalScopes()->find($id);

        abort_if($user === null || ! hash_equals(sha1((string) $user->email), $hash), 403, 'This link is not valid.');

        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->saveQuietly();
            ActivityLog::record('auth.email_verified', "Confirmed {$user->email}", $user);
        }

        $message = 'Your email is verified and your account is active. Please sign in.';

        return Auth::id() === $user->id
            ? redirect()->route('admin.dashboard')->with('status', $message)
            : redirect()->route('login')->with('status', $message);
    }
}
