<?php

namespace App\Support;

use App\Models\License;
use App\Models\Subscription;
use App\Models\Tenant;

/**
 * May this company work, on the strength of its license?
 *
 * Asked from AccountAccess - the one answer the sign-in form and the
 * tenant.active middleware both give - so the browser, the API and the login
 * screen can never disagree. Everything here is read from the database on
 * the backend; nothing a browser sends is consulted.
 *
 * Exempt companies (grandfathered customers, in-house accounts) and installs
 * with `license.enforce` off always pass.
 */
final class LicenseAccess
{
    public const OK = 'ok';

    /** Nothing registered yet - or what was registered was revoked or reset. */
    public const REQUIRED = 'required';

    public const PENDING = 'pending';

    public const INACTIVE = 'inactive';

    public const BLOCKED = 'blocked';

    public const REVOKED = 'revoked';

    public const EXPIRED = 'expired';

    /** A working license but no plan to run on - never left that way by the platform, but never trusted either. */
    public const NO_PLAN = 'no_plan';

    /**
     * @return array{0: string, 1: ?string, 2: ?License} state, message for the person, the license
     */
    public static function check(Tenant $tenant): array
    {
        if (! $tenant->needsLicense()) {
            return [self::OK, null, null];
        }

        $license = License::query()->where('holder_tenant_id', $tenant->id)->first();

        if ($license === null) {
            $last = License::query()->where('tenant_id', $tenant->id)->latest('id')->first();

            if ($last?->status === License::REVOKED) {
                return [self::REVOKED, __('Your license was withdrawn by the platform. Enter a new license key to continue.'), null];
            }

            return [self::REQUIRED, __('Enter the license key you received to start using :company.', ['company' => $tenant->name]), null];
        }

        return match (true) {
            $license->status === License::PENDING => [self::PENDING, __('Your license is waiting for approval. You will get access as soon as it is approved.'), $license],
            $license->status === License::INACTIVE => [self::INACTIVE, __('Your license has been switched off by the platform. Please contact support.'), $license],
            $license->status === License::BLOCKED => [self::BLOCKED, __('Your license has been blocked. Please contact support.'), $license],
            $license->isExpired() => [self::EXPIRED, __('Your license expired on :date. Please contact support to renew it.', ['date' => $license->expires_at?->format('j M Y')]), $license],
            $license->status === License::ACTIVE && ! $tenant->subscription()->exists() => [self::NO_PLAN, __('No plan is assigned to your account yet. Please contact support.'), $license],
            $license->status === License::ACTIVE => [self::OK, null, $license],
            default => [self::REQUIRED, __('Enter the license key you received to start using :company.', ['company' => $tenant->name]), $license],
        };
    }

    /**
     * Everything the customer's dashboard card and license page show.
     *
     * @return array<string, mixed>
     */
    public static function summary(Tenant $tenant): array
    {
        [$state, $message, $license] = self::check($tenant);

        // Before the license is approved the sign-up trial grants nothing, so it is not shown as if it did.
        $granted = ! in_array($state, [self::REQUIRED, self::REVOKED, self::PENDING], true);
        $subscription = $granted ? $tenant->subscription()->with('plan')->first() : null;
        $plan = $subscription?->plan ?? $license?->plan;

        $warn = (int) config('license.warn_days', 7);
        $ends = $granted ? ($subscription?->ends_at ?? $license?->expires_at) : null;
        $left = $ends === null ? null : (int) now()->startOfDay()->diffInDays($ends->copy()->startOfDay(), false);

        // One badge, most serious first.
        $badge = match (true) {
            $tenant->isSuspended() => ['key' => 'suspended', 'label' => 'Blocked', 'tone' => 'danger'],
            $state === self::BLOCKED => ['key' => 'blocked', 'label' => 'Blocked', 'tone' => 'danger'],
            $state === self::INACTIVE => ['key' => 'suspended', 'label' => 'Suspended', 'tone' => 'warning'],
            $state === self::PENDING => ['key' => 'pending', 'label' => 'Pending Approval', 'tone' => 'warning'],
            $state === self::EXPIRED, $subscription?->state() === Subscription::EXPIRED => ['key' => 'expired', 'label' => 'Expired', 'tone' => 'danger'],
            in_array($state, [self::REQUIRED, self::REVOKED], true) => ['key' => 'none', 'label' => 'Not Activated', 'tone' => 'muted'],
            $left !== null && $left >= 0 && $left <= $warn => ['key' => 'expiring', 'label' => 'Expiring Soon', 'tone' => 'warning'],
            default => ['key' => 'active', 'label' => 'Active', 'tone' => 'success'],
        };

        return [
            'state' => $state,
            'message' => $message,
            'license' => $license,
            'badge' => $badge,
            'plan' => $plan?->name,
            'license_status' => $license?->badge()['label'] ?? ($tenant->needsLicense() ? 'Not registered' : 'Not required'),
            'license_activated_at' => $license?->activated_at,
            'subscription_started_at' => $subscription?->starts_at,
            'expires_at' => $ends,
            'days_left' => $left,
            'subscription_status' => $subscription?->stateLabel(),
            'warning' => $left !== null && $left >= 0 && $left <= $warn && $state === self::OK
                ? __('Your subscription expires in :n day(s) on :date. Contact support to renew and avoid interruption.', ['n' => $left, 'date' => $ends->format('j M Y')])
                : null,
        ];
    }
}
