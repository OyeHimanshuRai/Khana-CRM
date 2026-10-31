<?php

namespace App\Support;

use App\Mail\WelcomeUserMail;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;

/**
 * Issues the set-password token and sends the welcome email.
 *
 * One place, because two callers need it: creating a user, and resending
 * from the user's edit screen.
 */
final class WelcomeMailer
{
    /** Long-window broker; see config/auth.php. */
    private const BROKER = 'welcome';

    /**
     * Send the welcome email.
     *
     * Never throws. Creating an account and telling someone about it are
     * two different jobs, and an unreachable SMTP server must not undo the
     * first - the caller reports what happened instead.
     *
     * @return bool  false when the send failed
     */
    public static function send(User $user): bool
    {
        [$url, $expires] = self::setPasswordLink($user);

        try {
            Mail::to($user->email)->send(new WelcomeUserMail($user, $url, $expires));
        } catch (\Throwable $e) {
            // Laravel fires no event for a failed send, so the log row this
            // send opened has to be closed by hand or it sits pending.
            EmailLogger::fail($e);

            Log::warning('Welcome email failed', [
                'user_id' => $user->id,
                'email' => $user->email,
                'error' => $e->getMessage(),
            ]);

            ActivityLog::record(
                'user.welcome_failed',
                "Welcome email to \"{$user->name}\" failed to send",
                $user,
                ['error' => $e->getMessage()],
            );

            return false;
        }

        ActivityLog::record('user.welcome_sent', "Sent a welcome email to \"{$user->name}\"", $user);

        return true;
    }

    /**
     * A one-time link for choosing a password, and how long it lasts.
     *
     * Returns [null, 0] rather than failing if the token cannot be issued -
     * the email is still worth sending, it just says the admin will pass
     * the password on separately.
     *
     * @return array{0: string|null, 1: int}
     */
    private static function setPasswordLink(User $user): array
    {
        $expires = (int) config('auth.passwords.'.self::BROKER.'.expire', 60);

        try {
            $token = Password::broker(self::BROKER)->createToken($user);
        } catch (\Throwable $e) {
            Log::warning('Could not issue a set-password token', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return [null, $expires];
        }

        return [
            route('admin.password.set', ['token' => $token, 'email' => $user->email]),
            $expires,
        ];
    }
}
