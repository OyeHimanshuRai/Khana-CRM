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
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

/**
 * Confirming the email of an account a business made for itself.
 *
 * See User::needsEmailVerification for who is asked and
 * EnsureEmailIsConfirmed for what an unconfirmed account can reach.
 */
class EmailVerificationController extends Controller
{
    /** "Check your inbox", with a way to send the link again. */
    public function notice(Request $request): View|RedirectResponse
    {
        if (! $request->user()->needsEmailVerification()) {
            return redirect()->route('admin.dashboard');
        }

        return view('admin.auth.verify-email', ['email' => $request->user()->email]);
    }

    /** Send the link again. Throttled on the route. */
    public function send(Request $request): JsonResponse|RedirectResponse
    {
        $user = $request->user();

        if ($user->needsEmailVerification()) {
            Mail::to($user->email, $user->name)->send(new VerifyEmailMail($user));
            ActivityLog::record('auth.verification_sent', 'Sent the email confirmation link again', $user);
        }

        $message = "We sent a new link to {$user->email}.";

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

        $message = 'Email confirmed. Thank you.';

        return Auth::id() === $user->id
            ? redirect()->route('admin.dashboard')->with('status', $message)
            : redirect()->route('login')->with('status', $message.' Sign in to continue.');
    }
}
