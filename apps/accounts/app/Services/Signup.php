<?php

namespace App\Services;

use App\Models\Outlet;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * A restaurant's first minute on Khana: the business, its first outlet, the
 * owner, and a trial - created together or not at all.
 *
 * One transaction wraps the lot, so the events that tell CRM and ERP about
 * the new business (its outlet, its owner, their access, the entitlement)
 * leave in one commit, in that order. A product that applies them in order
 * has a whole, consistent business the moment it hears of it.
 */
final class Signup
{
    public function __construct(
        private readonly Subscriptions $subscriptions,
        private readonly ReferenceEvents $events,
    ) {}

    /**
     * @param  array{name: string, legal_name?: ?string, gstin?: ?string, pan?: ?string, email?: ?string, phone?: ?string}  $business
     * @param  array{name: string, code: string, city: string, state_code: string, address_line1?: string, phone?: ?string}  $outlet
     * @param  array{name: string, email?: ?string, mobile?: ?string, password: string}  $owner
     */
    public function register(array $business, array $outlet, array $owner, Plan $plan, string $period = Plan::MONTHLY): User
    {
        return DB::transaction(function () use ($business, $outlet, $owner, $plan, $period) {
            $tenant = Tenant::create($business);
            $this->events->tenant($tenant);

            /** @var Outlet $branch */
            $branch = $tenant->outlets()->create($outlet + ['address_line1' => $outlet['address_line1'] ?? $outlet['name']]);
            $this->events->outlet($branch);

            /** @var User $user */
            $user = $tenant->users()->create([
                'name' => $owner['name'],
                'email' => $owner['email'] ?? null,
                'mobile' => $owner['mobile'] ?? null,
                'password' => Hash::make($owner['password']),
                'status' => User::ACTIVE,
                'is_owner' => true,
                'email_verified_at' => now(),
            ]);
            $this->events->user($user);

            // The owner is the owner in every product the plan covers, across
            // all outlets - present and future.
            foreach ($plan->products as $product) {
                $user->access()->create([
                    'product' => $product,
                    'role_key' => 'owner',
                    'all_outlets' => true,
                ]);
            }

            $user->load('access.outlets');
            $this->events->access($user);

            // Starts the trial and publishes the entitlement for each product.
            $this->subscriptions->startTrial($branch, $plan, $period, $user);

            return $user;
        });
    }
}
