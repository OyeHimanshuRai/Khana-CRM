<?php

namespace App\Services;

use App\Models\Outlet;
use App\Models\Plan;
use App\Models\PlatformPayment;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * What an outlet has bought, and the moments it changes hands: a trial
 * begins, a payment lands, a plan is swapped, a plan is dropped.
 *
 * Every method leaves the outlet's entitlements correct and told: it runs the
 * change and the recompute in one transaction, so CRM and ERP never see a
 * subscription without its entitlement, or the other way round.
 */
final class Subscriptions
{
    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * Begin an outlet on a plan, on the plan's free trial. The sign-up path
     * and the "add a branch" path both come through here.
     */
    public function startTrial(Outlet $outlet, Plan $plan, string $period = Plan::MONTHLY, ?User $by = null): Subscription
    {
        return DB::transaction(function () use ($outlet, $plan, $period, $by) {
            $now = now();
            $trialEnds = $plan->trial_days > 0 ? $now->copy()->addDays($plan->trial_days) : null;

            $subscription = Subscription::create([
                'tenant_id' => $outlet->tenant_id,
                'outlet_id' => $outlet->id,
                'plan_id' => $plan->id,
                'billing_period' => $period,
                'price_paise' => $plan->pricePaise($period),
                'starts_at' => $now,
                'trial_ends_at' => $trialEnds,
                // With no trial and no payment yet, it works until now - a
                // payment is what moves that forward.
                'ends_at' => $trialEnds ?? $now,
                'grace_days' => 3,
                'created_by' => $by?->id,
            ]);

            $this->entitlements->refresh($outlet->refresh());

            return $subscription;
        });
    }

    /**
     * Record a payment and move the term forward by one billing period. Early
     * payment keeps the days already bought; late payment does not buy back
     * the days missed (see Subscription::nextTermEnd).
     */
    public function recordPayment(
        Subscription $subscription,
        string $method = 'manual',
        ?string $providerReference = null,
        ?string $note = null,
        ?User $by = null,
    ): PlatformPayment {
        return DB::transaction(function () use ($subscription, $method, $providerReference, $note, $by) {
            $periodStart = $subscription->ends_at !== null && $subscription->ends_at->isFuture()
                ? $subscription->ends_at->copy()
                : now();
            $periodEnd = $subscription->nextTermEnd();

            $subscription->forceFill(['ends_at' => $periodEnd])->save();

            $payment = PlatformPayment::create([
                'reference' => 'PAY-'.strtoupper(Str::ulid()),
                'subscription_id' => $subscription->id,
                'tenant_id' => $subscription->tenant_id,
                'outlet_id' => $subscription->outlet_id,
                'amount_paise' => $subscription->price_paise,
                'method' => $method,
                'provider_reference' => $providerReference,
                'paid_at' => now(),
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'note' => $note,
                'recorded_by' => $by?->id,
            ]);

            $this->entitlements->refresh($subscription->outlet);

            return $payment;
        });
    }

    /**
     * Stop paying for a subscription. It keeps working to the end of the paid
     * term, then falls to suspended (read and export only) for the retention
     * days, then ends.
     */
    public function cancel(Subscription $subscription, ?string $note = null): void
    {
        DB::transaction(function () use ($subscription, $note) {
            // Effective at the end of the paid term, not now: the outlet keeps
            // what it paid for. Subscription::status() ignores a cancelled_at
            // in the future, so it stays usable until the term is up, then
            // falls to suspended. A perpetual or already-lapsed term drops now.
            $endsAt = $subscription->ends_at;
            $dropAt = $endsAt !== null && $endsAt->isFuture() ? $endsAt : now();

            $subscription->forceFill([
                'cancelled_at' => $dropAt,
                'note' => $note ?? $subscription->note,
            ])->save();

            $this->entitlements->refresh($subscription->outlet);
        });
    }

    /**
     * Move an outlet to a different plan. The old subscription is replaced
     * from now; the new one starts on the same term end, so a mid-term
     * upgrade does not reset the clock or the trial.
     */
    public function changePlan(Subscription $current, Plan $plan, ?string $period = null, ?User $by = null): Subscription
    {
        return DB::transaction(function () use ($current, $plan, $period, $by) {
            $period ??= $current->billing_period;
            $now = now();

            $current->forceFill(['replaced_at' => $now])->save();

            $next = Subscription::create([
                'tenant_id' => $current->tenant_id,
                'outlet_id' => $current->outlet_id,
                'plan_id' => $plan->id,
                'billing_period' => $period,
                'price_paise' => $plan->pricePaise($period),
                'starts_at' => $now,
                // Carry the paid-through date so the switch costs no days.
                'trial_ends_at' => null,
                'ends_at' => $current->ends_at,
                'grace_days' => $current->grace_days,
                'created_by' => $by?->id,
            ]);

            $this->entitlements->refresh($current->outlet);

            return $next;
        });
    }

    // Platform admin gives extra days: a trial stays a trial, a lapsed term restarts from today.
    public function extend(Subscription $subscription, int $days, ?string $note = null): void
    {
        // A perpetual term has nothing to extend.
        if ($subscription->ends_at === null) {
            return;
        }

        DB::transaction(function () use ($subscription, $days, $note) {
            $onTrial = $subscription->trial_ends_at !== null && $subscription->trial_ends_at->isFuture();
            $from = $subscription->ends_at !== null && $subscription->ends_at->isFuture() ? $subscription->ends_at->copy() : now();

            $subscription->forceFill([
                'ends_at' => $from->addDays($days),
                'trial_ends_at' => $onTrial ? $subscription->trial_ends_at->copy()->addDays($days) : $subscription->trial_ends_at,
                'note' => $note ?? $subscription->note,
            ])->save();

            $this->entitlements->refresh($subscription->outlet);
        });
    }
}
