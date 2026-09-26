<?php

namespace App\Services;

use App\Models\Entitlement;
use App\Models\Outlet;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;

/**
 * Turns an outlet's subscriptions into what CRM and ERP are told it may use.
 *
 * A subscription is what was bought; an entitlement is the answer to "can
 * this outlet use CRM right now, and until when". This is the only writer of
 * the entitlements table, and it sends the change in the same commit it
 * writes - so a product hears within seconds, and the row it holds and the
 * event it got never disagree.
 *
 * An outlet may hold a product through more than one subscription (a trial
 * that overlaps a paid plan, say). The liveliest of them wins: a usable
 * state is never hidden behind a lapsed one.
 */
final class Entitlements
{
    /** Which state to believe when an outlet has the product more than once. */
    private const RANK = [
        Subscription::TRIAL => 5,
        Subscription::ACTIVE => 4,
        Subscription::GRACE => 3,
        Subscription::SUSPENDED => 2,
        Subscription::ENDED => 1,
    ];

    public function __construct(private readonly ReferenceEvents $events) {}

    /**
     * Recompute every product for one outlet, publishing what changed.
     */
    public function refresh(Outlet $outlet): void
    {
        DB::transaction(function () use ($outlet) {
            $outlet->loadMissing('subscriptions.plan');

            foreach (ReferenceEvents::PRODUCTS as $product) {
                $this->refreshProduct($outlet, $product);
            }
        });
    }

    private function refreshProduct(Outlet $outlet, string $product): void
    {
        $winner = $this->winningState($outlet, $product);

        $existing = Entitlement::query()
            ->where('outlet_id', $outlet->id)
            ->where('product', $product)
            ->first();

        // An outlet that never bought this product has nothing to tell.
        if ($winner === null) {
            return;
        }

        [$subscription, $state, $validUntil] = $winner;
        $plan = $subscription->plan;
        $limits = $plan->limits ?? [];
        $until = $validUntil?->utc()->format('Y-m-d\TH:i:s\Z');

        // Same as last time: no row to write, no event to send.
        if ($existing
            && $existing->state === $state
            && $existing->plan === $plan->code
            && ($existing->valid_until?->utc()->format('Y-m-d\TH:i:s\Z')) === $until
            && $existing->limits == $limits) {
            return;
        }

        $entitlement = Entitlement::updateOrCreate(
            ['outlet_id' => $outlet->id, 'product' => $product],
            [
                'tenant_id' => $outlet->tenant_id,
                'state' => $state,
                'plan' => $plan->code,
                'limits' => $limits,
                'valid_until' => $validUntil,
                'published_at' => now(),
            ],
        );

        $this->events->entitlement($entitlement);
    }

    /**
     * The liveliest subscription covering this product, with its state.
     *
     * @return array{0: Subscription, 1: string, 2: \Carbon\CarbonImmutable|null}|null
     */
    private function winningState(Outlet $outlet, string $product): ?array
    {
        $winner = null;

        foreach ($outlet->subscriptions as $subscription) {
            /** @var Subscription $subscription */
            if (! $subscription->plan?->covers($product)) {
                continue;
            }

            [$state, $validUntil] = $subscription->status();

            if ($winner === null || self::RANK[$state] > self::RANK[$winner[1]]) {
                $winner = [$subscription, $state, $validUntil];
            }
        }

        return $winner;
    }
}
