<?php

namespace Khana\Kit\Tenancy;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Khana\Kit\Events\Envelope;
use Khana\Kit\Events\Inbox\Handlers;

/**
 * Keeps the local copy of Accounts current, one event at a time.
 *
 * Every write replaces the whole row - the events carry the full current
 * state, never a difference - and none is applied over a newer one, so the
 * nightly snapshot and a late event cannot fight.
 */
final class ReferenceHandlers
{
    public static function register(Handlers $handlers): void
    {
        $handlers->on('accounts.tenant.upserted', 1, fn (Envelope $e) => app(self::class)->tenant($e));
        $handlers->on('accounts.outlet.upserted', 1, fn (Envelope $e) => app(self::class)->outlet($e));
        $handlers->on('accounts.entitlement.changed', 1, fn (Envelope $e) => app(self::class)->entitlement($e));
    }

    public function tenant(Envelope $event): void
    {
        $this->write('khana_tenants', ['id' => $event->tenantId], $event, [
            'name' => $event->data['name'],
            'legal_name' => $event->data['legal_name'] ?? null,
            'gstin' => $event->data['gstin'] ?? null,
            'pan' => $event->data['pan'] ?? null,
            'status' => $event->data['status'],
        ]);

        app(Entitlements::class)->forget();
    }

    public function outlet(Envelope $event): void
    {
        $existing = DB::table('khana_outlets')->where('id', $event->outletId)->value('tenant_id');

        // An outlet never changes hands. If Accounts ever said it did, the
        // books this outlet already wrote would suddenly belong to a stranger.
        if ($existing !== null && $existing !== $event->tenantId) {
            throw new TenantMismatch("Outlet {$event->outletId} belongs to business {$existing}, not {$event->tenantId}.");
        }

        $this->write('khana_outlets', ['id' => $event->outletId], $event, [
            'tenant_id' => $event->tenantId,
            'name' => $event->data['name'],
            'code' => $event->data['code'],
            'gstin' => $event->data['gstin'] ?? null,
            'fssai' => $event->data['fssai'] ?? null,
            'address' => json_encode($event->data['address'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'state_code' => $event->data['state_code'],
            'timezone' => $event->data['timezone'],
            'day_cutoff' => $event->data['day_cutoff'],
            'status' => $event->data['status'],
        ]);
    }

    public function entitlement(Envelope $event): void
    {
        $owner = DB::table('khana_outlets')->where('id', $event->outletId)->value('tenant_id');

        if ($owner !== null && $owner !== $event->tenantId) {
            throw new TenantMismatch("Outlet {$event->outletId} belongs to business {$owner}, not {$event->tenantId}.");
        }

        $this->write('khana_entitlements', ['outlet_id' => $event->outletId, 'product' => $event->data['product']], $event, [
            'tenant_id' => $event->tenantId,
            'state' => $event->data['state'],
            'plan' => $event->data['plan'],
            'limits' => json_encode((object) ($event->data['limits'] ?? []), JSON_THROW_ON_ERROR),
            'valid_until' => isset($event->data['valid_until'])
                ? CarbonImmutable::parse($event->data['valid_until'])->utc()->format('Y-m-d H:i:s')
                : null,
        ]);

        app(Entitlements::class)->forget();
    }

    /**
     * @param  array<string, mixed>  $key
     * @param  array<string, mixed>  $values
     */
    private function write(string $table, array $key, Envelope $event, array $values): void
    {
        $changedAt = CarbonImmutable::parse($event->occurredAt)->utc()->format('Y-m-d H:i:s');
        $current = DB::table($table)->where($key)->value('changed_at');

        if ($current !== null && $current > $changedAt) {
            return;
        }

        DB::table($table)->updateOrInsert($key, $values + ['changed_at' => $changedAt]);
    }
}
