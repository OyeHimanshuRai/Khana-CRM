<?php

namespace App\Support;

use App\Mail\NewSignupMail;
use App\Mail\TrialWelcomeMail;
use App\Models\ActivityLog;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * The two emails a self-service sign-up sends: welcome to the owner, and
 * "somebody signed up" to the platform's own people.
 *
 * Never throws, for the reason WelcomeMailer gives: creating the account and
 * telling people about it are two jobs, and an SMTP outage must not undo the
 * first or show the new owner an error for an account that exists.
 */
final class SignupMailer
{
    public static function send(User $owner): void
    {
        $subscription = Subscription::query()
            ->where('tenant_id', $owner->tenant_id)
            ->latest('id')
            ->with('plan')
            ->first();

        if ($subscription === null) {
            return;
        }

        self::attempt('signup.welcome', $owner, function () use ($owner, $subscription) {
            Mail::to($owner->email, $owner->name)->send(new TrialWelcomeMail($owner, $subscription));
        });

        $staff = self::platformAddresses();

        if ($staff !== []) {
            self::attempt('signup.notify_staff', $owner, function () use ($owner, $subscription, $staff) {
                Mail::to($staff)->send(new NewSignupMail($owner, $subscription));
            });
        }
    }

    /**
     * Where "new sign-up" goes: every active Super Admin.
     *
     * Unscoped, because this runs a moment after the new owner was signed in
     * - and a company's staff are not shown the platform's accounts. Falls
     * back to the company address in Settings when there is nobody else.
     *
     * @return array<int, string>
     */
    public static function platformAddresses(): array
    {
        $admins = User::query()
            ->withoutGlobalScopes()
            ->where('is_active', true)
            ->role(User::SUPER_ADMIN)
            ->pluck('email')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($admins === [] && filled($fallback = Setting::get('company_email'))) {
            return [$fallback];
        }

        return $admins;
    }

    private static function attempt(string $event, User $owner, callable $send): void
    {
        try {
            $send();
        } catch (\Throwable $e) {
            EmailLogger::fail($e);

            Log::warning('Sign-up email failed', [
                'event' => $event,
                'user_id' => $owner->id,
                'error' => $e->getMessage(),
            ]);

            ActivityLog::record($event.'_failed', 'A sign-up email failed to send', $owner, ['error' => $e->getMessage()]);
        }
    }
}
