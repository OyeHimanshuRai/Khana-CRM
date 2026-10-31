<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\Tenant;
use App\Support\PlanAccess;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Selling, renewing and stopping a restaurant's account (SRS 2, 21).
 *
 * ---------------------------------------------------------------------------
 * Nothing here decides whether a business may trade
 * ---------------------------------------------------------------------------
 *
 * That question is answered by Subscription::state(), from the clock, on
 * every request. This service only writes the dates that answer feeds on.
 *
 * The distinction matters most for the sweep. A nightly command that had to
 * run for expiry to take effect would mean a missed cron is a month of free
 * service; because the state is computed, the sweep does nothing but make
 * the situation *visible* - flipping the tenant flag the list screen reads,
 * so somebody can see the account has lapsed without opening it.
 */
class SubscriptionService
{
    /**
     * Put a tenant on a plan.
     *
     * Changing plan closes the current subscription and opens a new one, so
     * the tenant's history is the list of its rows. Mid-term changes keep
     * the days already paid for: `ends_at` carries over rather than
     * restarting, because a business that upgrades in week two has not
     * forfeited weeks three and four.
     *
     * With no trial and no time to carry, the term ends now: the business is
     * on the plan but has not paid for it, and the first payment starts the
     * clock (recordPayment). An empty `ends_at` - no end at all - is only
     * ever written when `$perpetual` asks for it, for an in-house or partner
     * account, or when the price is nothing: there is no payment to wait for,
     * so a free plan cannot be left to lapse. Leaving it empty by default
     * turned every plan change on a lapsed account into the software for free.
     */
    public function subscribe(
        Tenant $tenant,
        Plan $plan,
        string $period = Plan::MONTHLY,
        ?int $trialDays = null,
        ?float $price = null,
        ?string $note = null,
        bool $perpetual = false,
    ): Subscription {
        if (! in_array($period, Plan::PERIODS, true)) {
            throw new RuntimeException('"'.$period.'" is not a billing period this system knows.');
        }

        return DB::transaction(function () use ($tenant, $plan, $period, $trialDays, $price, $note, $perpetual) {
            $current = $tenant->subscriptions()->latest('id')->lockForUpdate()->first();

            $trialDays ??= $current === null ? $plan->trial_days : 0;

            /*
             | Carried forward from the old subscription, if it still had
             | time on it. The alternative - starting the clock again on
             | every plan change - quietly charges people twice for the same
             | fortnight, and is the sort of thing nobody notices until a
             | customer does the arithmetic.
             */
            $carried = $current?->ends_at !== null && $current->ends_at->isFuture()
                ? $current->ends_at->copy()
                : null;

            $now = Carbon::now();

            $trialEnds = $trialDays > 0 ? $now->copy()->addDays($trialDays) : null;

            /*
             | The term ends at whichever is furthest out: the days carried
             | over, or the trial just granted. A trial on top of paid time
             | does not shorten the paid time.
             */
            $free = (float) ($price ?? $plan->priceFor($period)) <= 0;
            $endsAt = $perpetual || $free ? null : (collect([$carried, $trialEnds])->filter()->max() ?? $now->copy());

            $subscription = $tenant->subscriptions()->create([
                'plan_id' => $plan->id,
                'billing_period' => $period,
                'price' => $price ?? $plan->priceFor($period),
                'currency' => $plan->currency,
                'starts_at' => $now,
                'trial_ends_at' => $trialEnds,
                'ends_at' => $endsAt,
                'grace_days' => $current->grace_days ?? 3,
                'note' => $note,
                'created_by' => Auth::id(),
            ]);

            PlanAccess::forget();

            // A working plan undoes a pause the billing sweep put on the account;
            // one still waiting for payment leaves things as they are.
            if ($subscription->isUsable()) {
                $this->reflectOnTenant($subscription);
            }

            return $subscription->load('plan');
        });
    }

    /**
     * Record money received, and move the term forward by what it bought.
     *
     * The payment and the extension are one act. A receipt that did not
     * extend the term, or a term extended with no receipt behind it, are
     * both worse than either failing - so they share a transaction.
     *
     * A zero or negative amount is allowed and does NOT extend anything:
     * that is how a refund or a correction is recorded, and a refund that
     * silently bought another month would be a strange apology.
     */
    public function recordPayment(
        Subscription $subscription,
        float $amount,
        string $method = SubscriptionPayment::MANUAL,
        ?string $providerReference = null,
        ?CarbonInterface $paidAt = null,
        ?string $period = null,
        ?string $note = null,
    ): SubscriptionPayment {
        if (! in_array($method, SubscriptionPayment::METHODS, true)) {
            throw new RuntimeException('"'.$method.'" is not a payment method this system knows.');
        }

        return DB::transaction(function () use (
            $subscription, $amount, $method, $providerReference, $paidAt, $period, $note
        ) {
            $subscription = Subscription::lockForUpdate()->findOrFail($subscription->id);

            $paidAt = $paidAt ? Carbon::parse($paidAt) : Carbon::now();
            $period = $period ?: $subscription->billing_period;

            $extends = $amount > 0;

            $periodStart = $extends
                ? ($subscription->ends_at !== null && $subscription->ends_at->isFuture()
                    ? $subscription->ends_at->copy()
                    : Carbon::now())
                : $paidAt->copy();

            $periodEnd = $extends
                ? $subscription->extend($period)
                : $paidAt->copy();

            $payment = $subscription->payments()->create([
                'tenant_id' => $subscription->tenant_id,
                // Stamped after insert, below - the id is the reference.
                'reference' => 'pending',
                'amount' => $amount,
                'currency' => $subscription->currency,
                'method' => $method,
                'provider_reference' => $providerReference,
                'paid_at' => $paidAt,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'note' => $note,
                'recorded_by' => Auth::id(),
            ]);

            $payment->forceFill([
                'reference' => 'SUB-'.str_pad((string) $payment->id, 6, '0', STR_PAD_LEFT),
            ])->save();

            if ($extends) {
                /*
                 | Paying ends the trial. Somebody who has handed over money
                 | is a customer, and leaving the row saying "trial" would
                 | make every screen describe them wrongly.
                 |
                 | It also un-cancels: taking payment for an account somebody
                 | cancelled and leaving it cancelled is the worst of both.
                 */
                $subscription->forceFill([
                    'ends_at' => $periodEnd,
                    'trial_ends_at' => null,
                    'cancelled_at' => null,
                ])->save();
            }

            $this->reflectOnTenant($subscription->fresh());

            // The receipt to the owners, and the alert to us - once the money
            // is really on the books, not inside a transaction that might
            // still roll back.
            DB::afterCommit(fn () => app(EventNotifier::class)->subscriptionPaid($payment));

            return $payment;
        });
    }

    /**
     * Stop billing a tenant, without stopping it working today.
     *
     * Cancellation takes effect when the paid term runs out, not at the
     * moment somebody clicks it. A business that has paid until the 30th has
     * bought the 30th, and taking it away because they gave notice on the
     * 3rd would be keeping their money for a service withdrawn.
     *
     * Cancelling immediately is a different act, and is what suspension is
     * for.
     */
    public function cancel(Subscription $subscription, ?string $note = null, bool $immediately = false): Subscription
    {
        $at = $immediately || $subscription->ends_at === null
            ? Carbon::now()
            : $subscription->ends_at->copy();

        $subscription->forceFill([
            'cancelled_at' => $at,
            'note' => $note ?: $subscription->note,
        ])->save();

        $this->reflectOnTenant($subscription);

        return $subscription;
    }

    /**
     * Give a trial more days.
     *
     * Extends from whichever is later, the current end or now, so extending
     * an already-lapsed trial counts from today rather than handing back days
     * that were never going to be used. The row is locked for the read, so two
     * admins extending at once add both extensions instead of one overwriting
     * the other. A cancelled subscription is refused: resume it first.
     */
    public function extendTrial(Subscription $subscription, int $days): Subscription
    {
        $max = (int) config('app.max_trial_extension_days', 90);

        if ($days < 1 || $days > $max) {
            throw new RuntimeException("A trial can be extended by 1 to {$max} days at a time.");
        }

        return DB::transaction(function () use ($subscription, $days) {
            $locked = Subscription::query()->lockForUpdate()->findOrFail($subscription->id);

            if ($locked->cancelled_at !== null) {
                throw new RuntimeException('This subscription is cancelled. Undo the cancellation before extending the trial.');
            }

            $now = Carbon::now();
            $base = collect([$locked->trial_ends_at, $locked->ends_at])
                ->filter(fn ($d) => $d !== null && $d->greaterThan($now))
                ->max() ?? $now;

            $end = $base->copy()->addDays($days);

            $locked->forceFill(['trial_ends_at' => $end, 'ends_at' => $end])->save();

            $this->reflectOnTenant($locked);

            return $locked;
        });
    }

    /** Undo a cancellation that has not yet taken effect. */
    public function resume(Subscription $subscription): Subscription
    {
        $subscription->forceFill(['cancelled_at' => null])->save();

        $this->reflectOnTenant($subscription);

        return $subscription;
    }

    /**
     * Make the ledger's verdict visible on the tenant row.
     *
     * The tenant's `is_active` flag is what the list screen, the switcher
     * and every existing query already read. Subscription state does not
     * replace it; it drives it, so a lapsed account looks lapsed everywhere
     * without forty screens learning about subscriptions.
     *
     * Only ever moves the flag for a reason this service understands. A
     * tenant suspended by hand, for a dispute or an investigation, carries a
     * reason this method did not write and is left alone - otherwise a
     * renewal would quietly reinstate an account somebody deliberately shut.
     *
     * A row sold for one outlet says nothing about the company: its lapse is
     * that outlet's problem (EnsureShopIsSubscribed stops it there), and paying
     * for it cannot lift a suspension the blanket row put on everybody.
     */
    public function reflectOnTenant(Subscription $subscription): void
    {
        if ($subscription->isPerShop()) {
            return;
        }

        $tenant = $subscription->tenant()->first();

        if ($tenant === null) {
            return;
        }

        if (! $subscription->isUsable()) {
            if (! $tenant->isSuspended()) {
                $tenant->suspend($this->suspensionReason($subscription));
            }

            return;
        }

        // Usable again. Restore only what this service suspended.
        if ($tenant->isSuspended() && $this->wasSuspendedForBilling($tenant)) {
            $tenant->restore_();
        }
    }

    /**
     * Suspend everyone whose grace window has closed.
     *
     * Returns the tenants it acted on, for the command's output.
     *
     * Narrow in SQL to the rows whose term has ended, then ask each one's
     * state in PHP - the grace rule lives in one place and does not have to
     * be expressible in two dialects of SQL.
     *
     * @return Collection<int, Tenant>
     */
    public function sweep(): Collection
    {
        $acted = collect();

        foreach ($this->pendingSuspension() as $subscription) {
            $subscription->tenant->suspend($this->suspensionReason($subscription));
            $acted->push($subscription->tenant);
        }

        return $acted;
    }

    /**
     * The subscriptions the sweep is about to act on.
     *
     * Public so the command's --dry-run can report exactly what the real run
     * would do, using the same query rather than one that looks like it. A
     * dry run built on a second query is reassuring about something other
     * than what is going to happen.
     *
     * The set is small by construction - it is only accounts nobody has
     * renewed and nobody has yet suspended - so it is loaded rather than
     * chunked.
     *
     * @return Collection<int, Subscription>
     */
    public function pendingSuspension(): Collection
    {
        return Subscription::query()
            ->termEnded()
            /*
             | Only the newest subscription speaks for a tenant. An old row
             | left behind by a plan change has an `ends_at` in the past by
             | design, and suspending on it would shut down the very accounts
             | that just upgraded. And only a blanket row can suspend a
             | company: see reflectOnTenant().
             */
            ->current()
            ->whereNull('shop_id')
            ->with(['tenant', 'plan'])
            ->get()
            ->filter(fn (Subscription $s) => ! $s->isUsable()
                && $s->tenant !== null
                && ! $s->tenant->isSuspended())
            ->values();
    }

    /**
     * Subscriptions running out inside the given window, for the warnings.
     *
     * @return Collection<int, Subscription>
     */
    public function expiringWithin(int $days): Collection
    {
        return Subscription::query()
            ->whereNull('cancelled_at')
            ->whereNotNull('ends_at')
            ->whereBetween('ends_at', [Carbon::now(), Carbon::now()->addDays($days)])
            ->current()
            ->with(['tenant', 'plan'])
            ->orderBy('ends_at')
            ->get();
    }

    private function suspensionReason(Subscription $subscription): string
    {
        return $subscription->state() === Subscription::CANCELLED
            ? 'Subscription cancelled'
            : 'Subscription expired on '.($subscription->ends_at?->format('j M Y') ?? 'an unrecorded date');
    }

    /**
     * Was this tenant shut by the billing engine rather than by a person?
     *
     * Matched on the wording this service writes. Crude, and deliberately
     * so: the alternative is a column on `tenants` that exists only to
     * remember which of two code paths last touched the row, and the wrong
     * answer here costs one manual click rather than an account reopening
     * itself.
     */
    private function wasSuspendedForBilling(Tenant $tenant): bool
    {
        return $tenant->isSuspendedForBilling();
    }
}
