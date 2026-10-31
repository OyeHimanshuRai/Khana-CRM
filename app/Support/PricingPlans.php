<?php

namespace App\Support;

use App\Models\Plan;
use Illuminate\Support\Collection;

/**
 * The plans on sale, shaped for the public pricing page and the landing teaser.
 *
 * Straight from the `plans` table, which is what Super Admin edits under
 * Settings > Plans, so a price change on that screen is a price change on the
 * site. Only active plans - an archived plan is one that is no longer sold but
 * that existing subscribers are still on.
 *
 * The "most popular" flag is the middle plan by sort order rather than a
 * column, because there is no column for it and inventing one to decorate a
 * page would be a schema change for a border colour. With two plans it marks
 * the second; with one it marks nothing.
 */
final class PricingPlans
{
    /** @return Collection<int, array<string, mixed>> */
    public static function get(): Collection
    {
        $plans = Plan::query()->onSale()->orderBy('sort_order')->orderBy('monthly_price')->get();

        $highlight = $plans->count() >= 2 ? (int) floor(($plans->count() - 1) / 2) : -1;

        return $plans->values()->map(fn (Plan $plan, int $i) => [
            'plan' => $plan,
            'featured' => $i === $highlight,
            'monthly' => $plan->priceFor(Plan::MONTHLY),
            'yearly' => $plan->priceFor(Plan::YEARLY),
            /*
             | What a year actually saves, in months rather than a percentage -
             | "two months free" is a thing somebody can picture, and 16.7% is
             | not. Null when the yearly price is simply twelve monthlies.
             */
            'months_free' => self::monthsFree($plan),
            'limits' => $plan->limitLines(),
            'modules' => collect($plan->moduleKeys())
                ->map(fn (string $k) => Modules::catalogue()[$k]['label'] ?? $k)
                ->all(),
        ]);
    }

    private static function monthsFree(Plan $plan): ?int
    {
        $monthly = (float) $plan->monthly_price;

        if ($monthly <= 0) {
            return null;
        }

        $saved = ($monthly * 12) - $plan->priceFor(Plan::YEARLY);
        $months = (int) round($saved / $monthly);

        return $months > 0 ? $months : null;
    }
}
