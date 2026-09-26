<?php

namespace App\Services;

use App\Models\Entitlement;
use App\Models\Outlet;
use App\Models\ProductAccess;
use App\Models\Tenant;
use App\Models\User;

/**
 * Everything CRM and ERP copy about one business, in one reply.
 *
 * A product calls this once, when it first hears of a business (and again on
 * the nightly check). Every part has exactly the shape of the matching
 * event's data, so a product rebuilds its copy with the same code that
 * applies a live event - the snapshot is the whole history folded into now.
 */
final class Snapshot
{
    /**
     * @return array<string, mixed>
     */
    public function of(Tenant $tenant): array
    {
        $tenant->loadMissing([
            'outlets',
            'users.access.outlets',
            'outlets.entitlements',
        ]);

        return [
            'tenant_id' => $tenant->ulid,
            'tenant' => $tenant->toContract(),
            'outlets' => $tenant->outlets
                ->map(fn (Outlet $outlet) => ['outlet_id' => $outlet->ulid, ...$outlet->toContract()])
                ->values()
                ->all(),
            'users' => $tenant->users
                ->map(fn (User $user) => $user->toContract())
                ->values()
                ->all(),
            'access' => $this->access($tenant),
            'entitlements' => $tenant->outlets
                ->flatMap(fn (Outlet $outlet) => $outlet->entitlements)
                ->map(fn (Entitlement $entitlement) => [
                    'outlet_id' => $entitlement->outlet->ulid,
                    ...$entitlement->toContract(),
                ])
                ->values()
                ->all(),
            'taken_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * One row per person per product they can use, shaped like
     * accounts.access.changed - the same rows the live event sends.
     *
     * @return list<array<string, mixed>>
     */
    private function access(Tenant $tenant): array
    {
        $rows = [];

        foreach ($tenant->users as $user) {
            foreach ($user->access as $access) {
                /** @var ProductAccess $access */
                $rows[] = [
                    'user_id' => $user->ulid,
                    'product' => $access->product,
                    'role_key' => $access->role_key,
                    'outlet_ids' => $access->reachableOutlets()->pluck('ulid')->values()->all(),
                ];
            }
        }

        return $rows;
    }
}
