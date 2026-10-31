<?php

namespace App\Support;

use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;

/**
 * May this person work inside their company right now - and if not, what next?
 *
 * One answer, asked by two doors: the sign-in form (so somebody who cannot
 * get in is told why at the form, instead of being let in and thrown out on
 * the next page) and EnsureTenantIsActive (so a session that was fine this
 * morning stops when the term runs out at noon).
 *
 *   OPEN          carry on.
 *   BILLING_ONLY  the term has run out and this person can settle it: keep
 *                 them in, on the billing screen and nothing else. Whether
 *                 the platform takes payment online does not matter - the
 *                 screen shows the plans either way and, without online
 *                 payment, lets them ask for a renewal from right there.
 *   REFUSED       nobody here can fix it from inside the app: a company a
 *                 person suspended, staff without the right to pay, or an
 *                 account with no company at all.
 *
 * Super Admin is always OPEN: the person whose job is to fix an account has
 * to be able to open it.
 */
final class AccountAccess
{
    public const OPEN = 'open';

    public const BILLING_ONLY = 'billing_only';

    public const REFUSED = 'refused';

    /**
     * @return array{0: string, 1: ?string} the state, and what to tell the person
     */
    public static function decide(User $user): array
    {
        if ($user->isSuperAdmin()) {
            return [self::OPEN, null];
        }

        /*
         | Resolved through CurrentTenant rather than read off the account, so
         | this agrees with the scope about who somebody is - see CurrentTenant
         | for the single-company install where "no company" and "the only
         | company" are the same answer.
         */
        $tenant = CurrentTenant::get();

        if ($tenant === null) {
            return [self::REFUSED, __('Your account is not linked to a company. Ask an administrator to assign one.')];
        }

        if ($tenant->isSuspended()) {
            // Suspended by the nightly sweep for an unpaid term: payable.
            if ($tenant->isSuspendedForBilling()) {
                return self::lapsed($user, $tenant, __(':company is paused until the subscription is paid. Choose a plan below to continue.', ['company' => $tenant->name]));
            }

            // A person suspended it - a dispute, an investigation. Paying is not a way back in.
            return [self::REFUSED, __('access to :company is currently suspended. Please contact support.', ['company' => $tenant->name])];
        }

        /*
         | The subscription, read from the clock (§21). The sweep only flips
         | the tenant flag so a lapsed account *looks* lapsed on the list; a
         | term that ended at midnight reads as ended at one minute past here,
         | cron or no cron. Past due is not refused: inside the grace window
         | the business keeps trading and gets a banner instead.
         */
        $subscription = PlanAccess::subscriptionFor($tenant->id);

        if ($subscription === null || $subscription->isUsable()) {
            return [self::OPEN, null];
        }

        return self::lapsed($user, $tenant, $subscription->state() === Subscription::CANCELLED
            ? __('The subscription for :company has been cancelled. Choose a plan below to start again.', ['company' => $tenant->name])
            : __('Your subscription for :company has ended. Choose a plan below to continue.', ['company' => $tenant->name]));
    }

    /** A lapse: billing-only for whoever may pay, a clear refusal for everybody else. */
    private static function lapsed(User $user, Tenant $tenant, string $ownerMessage): array
    {
        if ($user->can('settings.subscriptions.pay')) {
            return [self::BILLING_ONLY, $ownerMessage];
        }

        return [self::REFUSED, __('The subscription for :company has ended. Please ask your account owner to renew it.', ['company' => $tenant->name])];
    }
}
