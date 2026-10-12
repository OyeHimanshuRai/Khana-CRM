<?php

namespace App\Http\Controllers\Admin;

use App\Models\Outlet;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\View\View;

/**
 * The platform owner's first screen: how many businesses have signed up, how
 * many are live, who is about to lapse, and what the platform earns each
 * month. Reads only; the back-office screens linked from it do the changing.
 */
final class DashboardController
{
    public function __invoke(): View
    {
        $now = now();

        $businesses = [
            'total' => Tenant::query()->count(),
            'active' => Tenant::query()->where('status', Tenant::ACTIVE)->count(),
            'suspended' => Tenant::query()->where('status', Tenant::SUSPENDED)->count(),
            'closed' => Tenant::query()->where('status', Tenant::CLOSED)->count(),
            'new_7' => Tenant::query()->where('created_at', '>=', $now->copy()->subDays(7))->count(),
            'new_30' => Tenant::query()->where('created_at', '>=', $now->copy()->subDays(30))->count(),
            // Signed in lately: at least one of its people logged in this week.
            'in_use_7' => User::query()->whereNotNull('tenant_id')
                ->where('last_login_at', '>=', $now->copy()->subDays(7))
                ->distinct()->count('tenant_id'),
        ];

        // A subscription that a later one has not replaced is the outlet's current one.
        $subscriptions = Subscription::query()
            ->with(['plan', 'tenant', 'outlet'])
            ->where(fn ($q) => $q->whereNull('replaced_at')->orWhere('replaced_at', '>', $now))
            ->get();

        $states = ['trial' => 0, 'active' => 0, 'grace' => 0, 'suspended' => 0, 'ended' => 0];
        $products = ['crm' => 0, 'erp' => 0];
        $mrr = 0;
        $dueSoon = collect();
        $trialsEnding = collect();

        foreach ($subscriptions as $subscription) {
            [$state, $until] = $subscription->status($now);
            $states[$state] = ($states[$state] ?? 0) + 1;

            if (! $subscription->isUsable($now)) {
                continue;
            }

            foreach ((array) $subscription->plan?->products as $product) {
                $products[$product] = ($products[$product] ?? 0) + 1;
            }

            // Only paid terms count towards monthly revenue, not trials.
            if ($state !== Subscription::TRIAL) {
                $mrr += $subscription->billing_period === 'yearly'
                    ? intdiv($subscription->price_paise, 12)
                    : $subscription->price_paise;
            }

            if ($state === Subscription::GRACE) {
                $dueSoon->push([$subscription, $until]);
            }

            if ($state === Subscription::TRIAL && $until !== null && $until->lessThanOrEqualTo($now->copy()->addDays(7))) {
                $trialsEnding->push([$subscription, $until]);
            }
        }

        $months = collect(range(5, 0))->map(function (int $ago) use ($now) {
            $start = $now->copy()->startOfMonth()->subMonthsNoOverflow($ago);

            return [
                'label' => $start->format('M'),
                'count' => Tenant::query()->whereBetween('created_at', [$start, $start->copy()->endOfMonth()])->count(),
            ];
        });

        return view('admin.dashboard', [
            'businesses' => $businesses,
            'outlets' => [
                'total' => Outlet::query()->count(),
                'active' => Outlet::query()->where('status', Outlet::ACTIVE)->count(),
            ],
            'people' => User::query()->whereNotNull('tenant_id')->count(),
            'states' => $states,
            'products' => $products,
            'mrr' => $mrr,
            'months' => $months,
            'maxMonth' => max(1, (int) $months->max('count')),
            'recent' => Tenant::query()->withCount(['outlets', 'users'])->latest()->limit(8)->get(),
            'dueSoon' => $dueSoon->sortBy(fn ($row) => $row[1])->take(8),
            'trialsEnding' => $trialsEnding->sortBy(fn ($row) => $row[1])->take(8),
        ]);
    }
}
