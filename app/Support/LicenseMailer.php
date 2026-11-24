<?php

namespace App\Support;

use App\Mail\LicenseRequestMail;
use App\Models\ActivityLog;
use App\Models\License;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Tells the platform by email when a license needs them - the bell alert may
 * not be open, an inbox usually is.
 *
 * Goes to every Super Admin and the support address in Settings, once each.
 * Sent at once rather than queued, and never throws: the thing being reported
 * has already happened, and a mail outage must not undo it.
 */
final class LicenseMailer
{
    public static function notifyPlatform(string $kind, Tenant $tenant, ?License $license = null, ?User $owner = null): void
    {
        try {
            $admins = SignupMailer::platformAddresses();
        } catch (\Throwable $e) {
            $admins = [];
        }

        $staff = collect($admins)
            ->push((string) Setting::get('company_email'))
            ->map(fn ($a) => strtolower(trim((string) $a)))
            ->filter(fn ($a) => filter_var($a, FILTER_VALIDATE_EMAIL))
            ->unique()
            ->values()
            ->all();

        if ($staff === []) {
            return;
        }

        try {
            Mail::to($staff)->sendNow(new LicenseRequestMail(
                $kind,
                $tenant,
                $license?->loadMissing('plan'),
                $owner?->name,
                $owner?->email ?? $tenant->email,
                $owner?->mobile ?? $tenant->phone,
            ));
        } catch (\Throwable $e) {
            EmailLogger::fail($e);
            Log::warning('License email failed', ['kind' => $kind, 'tenant_id' => $tenant->id, 'error' => $e->getMessage()]);
            ActivityLog::record('license.email_failed', 'A license email to the platform failed to send', $tenant, ['error' => $e->getMessage()]);
        }
    }
}
