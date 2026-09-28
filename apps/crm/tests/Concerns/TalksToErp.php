<?php

namespace Tests\Concerns;

use App\Models\Shop;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Khana\Kit\Contracts\ContractValidator;
use Khana\Kit\Events\Envelope;
use Khana\Kit\Events\OutboxEvent;
use Khana\Kit\Support\Ulid;
use Khana\Kit\Tenancy\Entitlements;

/**
 * An outlet linked to Accounts, and what CRM says to ERP about it.
 *
 * The seeded shop and business have no Accounts ids - DatabaseSeeder runs
 * WithoutModelEvents, so HasPublicId never fired - and CRM sends nothing for
 * an outlet Accounts has not told it about. linkToAccounts() does what
 * App\Auth\AccountsSignIn and the accounts.* events would: gives the shop
 * and its business their ids, and puts the outlet, and what it bought, into
 * the kit's copy of Accounts.
 */
trait TalksToErp
{
    /**
     * @param  array<string, string>  $products  product => entitlement state
     * @return array{tenant: string, outlet: string}
     */
    protected function linkToAccounts(Shop $shop, array $products = ['crm' => 'active', 'erp' => 'active']): array
    {
        $tenant = DB::table('tenants')->where('id', $shop->tenant_id)->value('ulid');

        if (! Ulid::isValid($tenant)) {
            $tenant = Ulid::generate();
            DB::table('tenants')->where('id', $shop->tenant_id)->update(['ulid' => $tenant]);
        }

        if (! Ulid::isValid($shop->ulid)) {
            $shop->forceFill(['ulid' => Ulid::generate()])->saveQuietly();
        }

        DB::table('khana_tenants')->updateOrInsert(['id' => $tenant], [
            'name' => 'Business',
            'status' => 'active',
            'changed_at' => '2026-09-26 00:00:00',
        ]);

        DB::table('khana_outlets')->updateOrInsert(['id' => $shop->ulid], [
            'tenant_id' => $tenant,
            'name' => $shop->name,
            'code' => 'O'.substr($shop->ulid, -3),
            'address' => json_encode(['line1' => '1 Road', 'line2' => null, 'city' => 'Delhi', 'pincode' => null]),
            'state_code' => '07',
            'timezone' => 'Asia/Kolkata',
            'day_cutoff' => '04:00',
            'status' => 'active',
            'changed_at' => '2026-09-26 00:00:00',
        ]);

        foreach ($products as $product => $state) {
            DB::table('khana_entitlements')->updateOrInsert(['outlet_id' => $shop->ulid, 'product' => $product], [
                'tenant_id' => $tenant,
                'state' => $state,
                'plan' => $product,
                'limits' => '{}',
                'changed_at' => '2026-09-26 00:00:00',
            ]);
        }

        app(Entitlements::class)->forget();

        return ['tenant' => $tenant, 'outlet' => $shop->ulid];
    }

    /** Where the outbox stands now, so a test reads only what its action wrote. */
    protected function outboxMark(): int
    {
        return (int) OutboxEvent::query()->max('id');
    }

    /**
     * @return Collection<int, OutboxEvent>
     */
    protected function outboxSince(int $mark, ?string $type = null): Collection
    {
        return OutboxEvent::query()
            ->where('id', '>', $mark)
            ->when($type !== null, fn ($q) => $q->where('type', $type))
            ->orderBy('id')
            ->get();
    }

    /**
     * The event exactly as it would travel, checked against its contract.
     *
     * The outbox already refuses an invalid event in the testing
     * environment; this says so in the test, where it is read.
     */
    protected function assertHonoursContract(OutboxEvent $event): void
    {
        $envelope = new Envelope(
            id: $event->event_id,
            type: $event->type,
            version: $event->version,
            source: 'crm',
            // Numbered after commit; a placeholder is enough to check shape.
            seq: max(1, (int) $event->seq),
            occurredAt: $event->occurred_at->utc()->format('Y-m-d\TH:i:s\Z'),
            tenantId: $event->tenant_id,
            outletId: $event->outlet_id,
            data: $event->data ?? [],
        );

        $this->assertSame([], app(ContractValidator::class)->problems($envelope->toArray()), $event->type.' breaks its contract');
    }

    /**
     * Checks a bare `data` object against an event type's contract, by
     * putting it in a well-formed envelope - how the API tests prove a
     * response is "in the same shape as" an event.
     *
     * @param  array<string, mixed>  $data
     */
    protected function assertDataHonours(string $type, array $data, string $tenantId, string $outletId, string $source = 'crm'): void
    {
        $envelope = [
            'id' => Ulid::generate(),
            'type' => $type,
            'version' => 1,
            'source' => $source,
            'seq' => 1,
            'occurred_at' => '2026-09-28T10:00:00Z',
            'tenant_id' => $tenantId,
            'outlet_id' => $outletId,
            'data' => $data,
        ];

        $this->assertSame([], app(ContractValidator::class)->problems($envelope), "not the shape of {$type}");
    }
}
