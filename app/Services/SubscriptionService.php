<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Shop;
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
     * Put an outlet on a plan.
     *
     * The per-outlet entry point, and the one to reach for: the product is
     * priced per outlet, so this is what a sale actually is. It delegates to
     * subscribe() with the shop named, which is what keeps one set of rules
     * about carried-over days and trials.
     */
    public function subscribeShop(
        Shop $shop,
        Plan $plan,
        string $period = Plan::MONTHLY,
        ?int $trialDays = null,
        ?float $price = null,
        ?string $note = null,
    ): Subscription {
        $tenant = $shop->tenant;

        if ($tenant === null) {
            throw new RuntimeException(
                'That outlet is not linked to a business, so there is nobody to bill for it.'
            );
        }

        return $this->subscribe($tenant, $plan, $period, $trialDays, $price, $note, $shop);
    }

    /**
     * Put a tenant, or one of its outlets, on a plan.
     *
     * Changing plan closes the current subscription and opens a new one, so
     * the history is the list of its rows. Mid-term changes keep the days
     * already paid for: `ends_at` carries over rather than restarting,
     * because a business that upgrades in week two has not forfeited weeks
     * three and four.
     *
     * ------------------------------------------------------------------
     * Which term carries over
     * ------------------------------------------------------------------
     *
     * The days are carried from the subscription being REPLACED, and that is
     * the outlet's own row when a shop is named - never the tenant's blanket
     * one. Carrying a blanket term onto one outlet would hand that branch
     * time the whole business had paid for and leave its neighbours short;
     * the blanket row is left exactly as it was, still covering everything
     * that has not been moved off it.
     *
     * @param  Shop|null  $shop  The outlet being sold to. Null writes the
     *                           old blanket row that covers the whole tenant -
     *                           still supported so existing accounts can renew,
     *                           but not what a new sale should use.
     */
    public function subscribe(
        Tenant $tenant,
        Plan $plan,
        string $period = Plan::MONTHLY,
        ?int $trialDays = null,
        ?float $price = null,
        ?string $note = null,
        ?Shop $shop = null,
    ): Subscription {
        if (! in_array($period, Plan::PERIODS, true)) {
            throw new RuntimeException('"'.$period.'" is not a billing period this system knows.');
        }

        if ($shop !== null && (int) $shop->tenant_id !== (int) $tenant->id) {
            throw new RuntimeException(
                'That outlet belongs to a different business. A subscription cannot be billed across two.'
            );
        }

        return DB::transaction(function () use ($tenant, $plan, $period, $trialDays, $price, $note, $shop) {
            $current = $tenant->subscriptions()
                ->when($shop !== null,
                    fn ($q) => $q->where('shop_id', $shop->id),
                    fn ($q) => $q->whereNull('shop_id'))
                ->latest('id')
                ->lockForUpdate()
                ->first();

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
            $endsAt = collect([$carried, $trialEnds])->filter()->max();

            $subscription = $tenant->subscriptions()->create([
                'shop_id' => $shop?->id,
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
                // Denormalised so per-outlet revenue needs no join - see the
                // migration that added the column.
                'shop_id' => $subscription->shop_id,
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
     * ------------------------------------------------------------------
     * A per-outlet row never touches the business
     * ------------------------------------------------------------------
     *
     * This is the sharpest edge in the move to per-outlet billing. The flag
     * it writes suspends the whole account - every branch, every till, every
     * user - and a subscription sold for one outlet has no business doing
     * that. A group of five restaurants whose Kota branch fell a week behind
     * would have had all five locked out mid-service.
     *
     * So only a blanket row, the one that genuinely covers the business,
     * reaches the flag. An outlet's own lapse is enforced where it belongs:
     * on that outlet, by EnsureShopIsSubscribed, which blocks the branch and
     * leaves the rest of the account working.
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
             | Blanket rows only.
             |
             | This query feeds sweep(), which suspends a whole business.
             | A subscription sold for one outlet must never reach it: a
             | group whose Kota branch fell behind would have every branch
             | suspended by the nightly cron. An outlet's lapse is that
             | outlet's problem - see EnsureShopIsSubscribed.
             */
            ->whereNull('shop_id')
            /*
             | Only the newest subscription speaks for a tenant. An old row
             | left behind by a plan change has an `ends_at` in the past by
             | design, and suspending on it would shut down the very accounts
             | that just upgraded.
             */
            ->whereIn('id', $this->currentIds())
            ->with(['tenant', 'plan'])
            ->get()
            ->filter(fn (Subscription $s) => ! $s->isUsable()
                && $s->tenant !== null
                && ! $s->tenant->isSuspended())
            ->values();
    }

    /**
     * The id of the newest BLANKET subscription for every tenant.
     *
     * Used instead of asking per row. On an install with a thousand
     * businesses this is one cheap grouped read rather than a thousand.
     *
     * Per-outlet rows are excluded here as well as in the caller, and the
     * repetition is deliberate: without it, a tenant whose newest row
     * happens to be one outlet's subscription would have no blanket id in
     * this set at all, and its genuinely lapsed business-wide row would
     * quietly stop being swept.
     *
     * @return array<int, int>
     */
    private function currentIds(): array
    {
        return Subscription::query()
            ->whereNull('shop_id')
            ->selectRaw('MAX(id) as id')
            ->groupBy('tenant_id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
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
            ->whereIn('id', $this->currentIds())
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
        return str_starts_with((string) $tenant->suspend_reason, 'Subscription ');
    }
}
