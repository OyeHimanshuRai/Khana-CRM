<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\PasswordResetMail;
use App\Models\ActivityLog;
use App\Models\User;
use App\Support\EmailLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

/**
 * "I have forgotten my password."
 *
 * ---------------------------------------------------------------------------
 * Why this had to exist
 * ---------------------------------------------------------------------------
 *
 * Until restaurants opened their own accounts, every password in this system
 * belonged to somebody an administrator could reach: a colleague who had been
 * created on the users screen and could be sent a fresh welcome link from it.
 *
 * Self-serve signup broke that. The owner who signed up at midnight chose
 * their own password, has no administrator, and locking them out of the
 * software they just paid for while they wait for support to open is the
 * worst hour this product can give anybody.
 *
 * ---------------------------------------------------------------------------
 * The token is the framework's
 * ---------------------------------------------------------------------------
 *
 * Same broker Laravel ships, and the link points at the screen the welcome
 * email already uses - PasswordSetupController. One page that sets a password
 * from a token, two ways of getting a token. A second screen would be a second
 * place to get the one-time semantics wrong.
 *
 * It also uses the same broker, and that is not a detail. Both brokers are
 * configured on one table, so whichever one performs the reset is the one
 * whose expiry actually applies - a token minted here under a one-hour broker
 * would still be accepted three days later by the screen that consumes it.
 * Rather than ship an email that states a window the code does not enforce,
 * this issues `welcome` tokens and tells people the window that is real. The
 * link is still one-time: using it deletes the row.
 *
 * ---------------------------------------------------------------------------
 * It says the same thing whatever happens
 * ---------------------------------------------------------------------------
 *
 * A form that answered "no account with that email" would be a way to find out
 * which restaurants are customers - typed one address at a time, or ten
 * thousand by a script. So the answer never varies, and what actually
 * happened goes to the activity log, where the people entitled to know can
 * read it.
 */
class PasswordResetController extends Controller
{
    /**
     * The broker PasswordSetupController resets with - see config/auth.php.
     *
     * Not a choice about how long a reset link *should* live, but about which
     * window is enforceable: see the note above the class.
     */
    private const BROKER = 'welcome';

    public function create(): View
    {
        return view('admin.auth.forgot-password');
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $user = User::query()->where('email', $data['email'])->first();

        /*
         | Everything below is conditional; the answer is not.
         |
         | A deactivated account is deliberately not sent a link either: it
         | cannot be signed into, so a working link would only teach whoever
         | asked that the address exists.
         */
        if ($user instanceof User && $user->is_active) {
            $this->sendLink($user);
        } else {
            // Recorded so a locked-out owner ringing support can be told what
            // the system actually saw - which is usually a typo in the
            // address, and is invisible without this line.
            ActivityLog::record(
                'auth.reset_unknown',
                'A password reset was asked for an address with no live account',
                null,
                ['email' => $data['email']],
            );
        }

        $message = 'If that address has an account, a reset link is on its way. '
            .'Check your spam folder if it has not arrived in a few minutes.';

        if ($request->expectsJson()) {
            return json_success($message);
        }

        return redirect()->route('admin.password.request')->with('status', $message);
    }

    /* ------------------------------------------------------------ sending */

    /**
     * Issue a token and email the link.
     *
     * Never throws. An unreachable SMTP server is a problem for the person
     * who deployed it, and turning it into a five-hundred here would tell an
     * anonymous visitor both that the address exists and that the mail server
     * is down.
     */
    private function sendLink(User $user): void
    {
        $expires = (int) config('auth.passwords.'.self::BROKER.'.expire', 60);

        try {
            $token = Password::broker(self::BROKER)->createToken($user);

            $url = route('admin.password.set', ['token' => $token]).'?email='.urlencode($user->email);

            Mail::to($user->email)->send(new PasswordResetMail($user, $url, $expires));
        } catch (\Throwable $e) {
            // Laravel fires no event for a failed send, so the log row this
            // send opened has to be closed by hand or it sits pending -
            // the same rule WelcomeMailer follows.
            EmailLogger::fail($e);

            Log::warning('Password reset email failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            ActivityLog::record(
                'auth.reset_failed',
                "A password reset email to \"{$user->name}\" could not be sent",
                $user,
                ['error' => $e->getMessage()],
            );

            return;
        }

        ActivityLog::record('auth.reset_requested', 'Asked for a password reset link', $user);

        /*
         | Deliberately NOT written to login_histories.
         |
         | That table has three states - success, failed, blocked - and a
         | reset request is none of them. Filing it as `failed` would put it
         | in the failed-sign-in count that the blocked-IP screen and the
         | security reports read, so a person who forgot their password would
         | look like somebody guessing at one. The activity log above is where
         | this belongs.
         */
    }
}
