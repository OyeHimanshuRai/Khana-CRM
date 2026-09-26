<?php

namespace App\Services;

use App\Models\Outlet;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Opening and editing a business's outlets. Each change is announced to CRM
 * and ERP; a new outlet also starts its own trial, since a plan is bought per
 * outlet, not per business.
 */
final class Outlets
{
    public function __construct(
        private readonly Subscriptions $subscriptions,
        private readonly ReferenceEvents $events,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function open(Tenant $tenant, array $data, Plan $plan, string $period = Plan::MONTHLY, ?User $by = null): Outlet
    {
        return DB::transaction(function () use ($tenant, $data, $plan, $period, $by) {
            /** @var Outlet $outlet */
            $outlet = $tenant->outlets()->create($data);
            $this->events->outlet($outlet);

            // An owner already reaches every outlet (all_outlets), so no
            // access row is needed here; the entitlement is what the new
            // branch is missing until its trial starts.
            $this->subscriptions->startTrial($outlet, $plan, $period, $by);

            return $outlet;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Outlet $outlet, array $data): Outlet
    {
        return DB::transaction(function () use ($outlet, $data) {
            $outlet->update($data);
            $this->events->outlet($outlet->refresh());

            return $outlet;
        });
    }
}
