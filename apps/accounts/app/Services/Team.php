<?php

namespace App\Services;

use App\Models\Invitation;
use App\Models\Outlet;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The people of a business: inviting them, setting what they may do, and
 * letting them go. Set once here, copied to CRM and ERP by events - nobody is
 * set up twice.
 *
 * An invited person exists as a real, access-carrying user from the moment
 * they are invited (status "invited"), so their role reaches the products
 * before they ever sign in. Accepting only sets their password.
 */
final class Team
{
    public function __construct(private readonly ReferenceEvents $events) {}

    /**
     * @param  array{name: string, email?: ?string, mobile?: ?string}  $person
     * @param  array<string, array{role_key: string, outlet_ids?: list<int>, all_outlets?: bool}>  $access  keyed by product
     * @return array{0: User, 1: string}  the user and the one-time token for the link
     */
    public function invite(Tenant $tenant, array $person, array $access, ?User $by = null): array
    {
        return DB::transaction(function () use ($tenant, $person, $access, $by) {
            /** @var User $user */
            $user = $tenant->users()->make([
                'name' => $person['name'],
                'email' => $person['email'] ?? null,
                'mobile' => $person['mobile'] ?? null,
            ]);
            // status is guarded; an invited person has no password yet.
            $user->forceFill(['status' => User::INVITED])->save();

            $this->setAccess($user, $access);

            $token = Str::random(48);
            Invitation::create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'token_hash' => Invitation::hash($token),
                'invited_by' => $by?->id,
                'expires_at' => now()->addDays(Invitation::DAYS),
            ]);

            $this->events->user($user);
            $this->events->access($user->load('access.outlets'));

            return [$user, $token];
        });
    }

    /**
     * Set a person's password from their invitation, and turn them on.
     */
    public function accept(Invitation $invitation, string $password): User
    {
        return DB::transaction(function () use ($invitation, $password) {
            $user = $invitation->user;
            $user->forceFill([
                'password' => Hash::make($password),
                'status' => User::ACTIVE,
                'email_verified_at' => $user->email ? now() : null,
            ])->save();

            $invitation->forceFill(['accepted_at' => now()])->save();

            $this->events->user($user);

            return $user;
        });
    }

    /**
     * Replace a person's access. Products with no entry are removed.
     *
     * @param  array<string, array{role_key: string, outlet_ids?: list<int>, all_outlets?: bool}>  $access
     */
    public function updateAccess(User $user, array $access): void
    {
        DB::transaction(function () use ($user, $access) {
            $user->access()->delete();
            $this->setAccess($user, $access);
            $this->events->access($user->load('access.outlets'));
        });
    }

    /**
     * Turn a person off. Their sessions in both products end at once.
     */
    public function deactivate(User $user): void
    {
        DB::transaction(function () use ($user) {
            $user->forceFill(['status' => User::DEACTIVATED])->save();

            $this->events->user($user);
            $this->events->sessionsRevoked($user, 'deactivated');
        });
    }

    /**
     * @param  array<string, array{role_key: string, outlet_ids?: list<int>, all_outlets?: bool}>  $access
     */
    private function setAccess(User $user, array $access): void
    {
        foreach ($access as $product => $grant) {
            if (! in_array($product, ReferenceEvents::PRODUCTS, true) || empty($grant['role_key'])) {
                continue;
            }

            $allOutlets = (bool) ($grant['all_outlets'] ?? false);

            $row = $user->access()->create([
                'product' => $product,
                'role_key' => $grant['role_key'],
                'all_outlets' => $allOutlets,
            ]);

            if (! $allOutlets && ! empty($grant['outlet_ids'])) {
                // Only outlets that belong to this person's business.
                $ids = Outlet::query()
                    ->where('tenant_id', $user->tenant_id)
                    ->whereIn('id', $grant['outlet_ids'])
                    ->pluck('id');
                $row->outlets()->sync($ids);
            }
        }
    }
}
