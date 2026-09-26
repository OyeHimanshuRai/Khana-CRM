<?php

namespace App\Services;

use App\Models\Entitlement;
use App\Models\Outlet;
use App\Models\ProductAccess;
use App\Models\Tenant;
use App\Models\User;
use Khana\Kit\Events\Outbox;

/**
 * Tells CRM and ERP what changed, through the outbox.
 *
 * Call these inside the transaction that made the change: the event is
 * written in the same commit, so it exists exactly when the change does, and
 * leaves after the commit in the order it was written.
 *
 * Every event carries the full current state, never a difference - a
 * receiver replaces what it holds - so sending one twice, or late, is safe.
 */
final class ReferenceEvents
{
    public const PRODUCTS = ['crm', 'erp'];

    public function __construct(private readonly Outbox $outbox) {}

    public function tenant(Tenant $tenant): void
    {
        $this->outbox->record('accounts.tenant.upserted', $tenant->toContract(), $tenant->ulid);
    }

    public function outlet(Outlet $outlet): void
    {
        $this->outbox->record('accounts.outlet.upserted', $outlet->toContract(), $outlet->tenant->ulid, $outlet->ulid);
    }

    public function user(User $user): void
    {
        $this->outbox->record('accounts.user.upserted', $user->toContract(), $user->tenant?->ulid);
    }

    /**
     * A person's role and outlets in one product, or in both when no product
     * is named. Platform staff belong to no business and have no access rows.
     */
    public function access(User $user, ?string $product = null): void
    {
        if ($user->tenant_id === null) {
            return;
        }

        $user->loadMissing('tenant', 'access.outlets');

        foreach ($product !== null ? [$product] : self::PRODUCTS as $name) {
            /** @var ProductAccess|null $access */
            $access = $user->accessTo($name);

            $this->outbox->record('accounts.access.changed', [
                'user_id' => $user->ulid,
                'product' => $name,
                'role_key' => $access?->role_key,
                'outlet_ids' => $access ? $access->reachableOutlets()->pluck('ulid')->values()->all() : [],
            ], $user->tenant->ulid);
        }
    }

    public function entitlement(Entitlement $entitlement): void
    {
        $entitlement->loadMissing('outlet.tenant');

        $this->outbox->record(
            'accounts.entitlement.changed',
            $entitlement->toContract(),
            $entitlement->outlet->tenant->ulid,
            $entitlement->outlet->ulid,
        );
    }

    /**
     * End the person's sessions in both products and revoke their devices.
     *
     * @param  'deactivated'|'signed_out_everywhere'|'password_changed'|'security'  $reason
     */
    public function sessionsRevoked(User $user, string $reason): void
    {
        $this->outbox->record('accounts.sessions.revoked', [
            'user_id' => $user->ulid,
            'reason' => $reason,
        ], $user->tenant?->ulid);
    }
}
