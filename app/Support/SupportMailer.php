<?php

namespace App\Support;

use App\Mail\SupportRequestMail;
use App\Mail\SupportRequestReceivedMail;
use App\Models\ActivityLog;
use App\Models\DemoRequest;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * The two emails a "Contact support" request sends: the details to the platform's
 * own people, and a confirmation copy to the person who wrote.
 *
 * Sent at once rather than queued, and never throws: the request is already saved,
 * and an outage at the mail server must not turn a saved request into an error.
 */
final class SupportMailer
{
    public static function send(DemoRequest $lead): void
    {
        // The Super Admins, and the support address from Settings, once each.
        try {
            $admins = SignupMailer::platformAddresses();
        } catch (\Throwable $e) {
            // No Super Admin role yet (a bare install): the support address below still gets it.
            $admins = [];
        }

        $staff = collect($admins)
            ->push((string) Setting::get('company_email'))
            ->map(fn ($a) => strtolower(trim((string) $a)))
            ->filter(fn ($a) => filter_var($a, FILTER_VALIDATE_EMAIL))
            ->unique()
            ->values()
            ->all();

        if ($staff !== []) {
            self::attempt('support_request.notify_staff', $lead, fn () => Mail::to($staff)->sendNow(new SupportRequestMail($lead)));
        }

        if (filled($lead->email)) {
            self::attempt('support_request.confirm', $lead, fn () => Mail::to($lead->email, $lead->name)->sendNow(new SupportRequestReceivedMail($lead)));
        }
    }

    private static function attempt(string $event, DemoRequest $lead, callable $send): void
    {
        try {
            $send();
        } catch (\Throwable $e) {
            EmailLogger::fail($e);
            Log::warning('Support request email failed', ['event' => $event, 'lead_id' => $lead->id, 'error' => $e->getMessage()]);
            ActivityLog::record($event.'_failed', 'A support request email failed to send', $lead, ['error' => $e->getMessage()]);
        }
    }
}
