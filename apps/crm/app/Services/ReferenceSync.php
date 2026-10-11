<?php

namespace App\Services;

use App\Services\Accounts\AccountsClient;
use App\Services\Accounts\AccountsUnavailable;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Khana\Kit\Events\Envelope;
use Khana\Kit\Support\Ulid;
use Khana\Kit\Tenancy\ReferenceHandlers;

/**
 * CRM's copy of what Accounts owns - the business, its outlets, and what each
 * outlet has bought - brought in line with Accounts itself.
 *
 * Events keep that copy current, one change at a time. This is for when they
 * cannot be relied on: the nightly check, which is how a lost accounts.*
 * event is found. Accounts hands over the whole of one business (its
 * snapshot), and every part CRM keeps is applied by the very code that
 * applies the matching event - the kit's ReferenceHandlers - so the two ways
 * of hearing a thing cannot come to differ.
 *
 * People are left out on purpose: CRM mirrors a person from Accounts when
 * they sign in, not from this snapshot. What is kept here is tenants, outlets
 * and entitlements - and it is entitlements that decide whether an outlet may
 * use CRM at all, so a lost one is the gap that matters most.
 *
 * Nothing newer is written over (the kit's handlers refuse a row changed
 * after the snapshot was taken) and nothing is deleted: an outlet or a plan
 * CRM holds that Accounts does not list is handed back by name, for a person.
 */
class ReferenceSync
{
    private const PRODUCTS = ['crm' => 'Khana CRM', 'erp' => 'Khana ERP'];

    public function __construct(
        private readonly AccountsClient $accounts,
        private readonly ReferenceHandlers $reference,
    ) {}

    /**
     * Ask Accounts for the business and bring CRM's copy in line with it.
     *
     * The asking is done first and on its own; the transaction opens only
     * once the answer is in hand.
     *
     * @return array{changed: int, strays: list<string>} how many rows differed, and what CRM holds that Accounts does not list
     *
     * @throws AccountsUnavailable
     */
    public function pull(string $tenantId): array
    {
        return $this->apply($tenantId, $this->accounts->snapshot($tenantId));
    }

    /**
     * @param  array<string, mixed>  $snapshot  the body of GET /api/v1/tenants/{id}/snapshot
     * @return array{changed: int, strays: list<string>}
     */
    public function apply(string $tenantId, array $snapshot): array
    {
        $at = (string) $snapshot['taken_at'];
        $takenAt = CarbonImmutable::parse($at)->utc();

        return DB::transaction(function () use ($tenantId, $snapshot, $at, $takenAt) {
            $changed = $this->differed('khana_tenants', ['id' => $tenantId], fn () => $this->reference->tenant(
                self::envelope('accounts.tenant.upserted', $tenantId, null, $snapshot['tenant'], $at),
            ));

            foreach ($snapshot['outlets'] as $outlet) {
                $changed += $this->differed('khana_outlets', ['id' => $outlet['outlet_id']], fn () => $this->reference->outlet(
                    self::envelope('accounts.outlet.upserted', $tenantId, $outlet['outlet_id'], Arr::except($outlet, 'outlet_id'), $at),
                ));
            }

            foreach ($snapshot['entitlements'] as $entitlement) {
                $key = ['outlet_id' => $entitlement['outlet_id'], 'product' => $entitlement['product']];

                $changed += $this->differed('khana_entitlements', $key, fn () => $this->reference->entitlement(
                    self::envelope('accounts.entitlement.changed', $tenantId, $entitlement['outlet_id'], Arr::except($entitlement, 'outlet_id'), $at),
                ));
            }

            return ['changed' => $changed, 'strays' => $this->strays($tenantId, $snapshot, $takenAt)];
        });
    }

    /* ---------------------------------------------------------- private */

    /**
     * What CRM holds for this business that Accounts does not list. Named,
     * never deleted: the records an outlet wrote are still its records.
     *
     * @param  array<string, mixed>  $snapshot
     * @return list<string>
     */
    private function strays(string $tenantId, array $snapshot, CarbonImmutable $takenAt): array
    {
        // A row changed after the snapshot was taken is news the snapshot has
        // not heard, not a stray.
        $asOf = $takenAt->format('Y-m-d H:i:s');
        $names = DB::table('khana_outlets')->where('tenant_id', $tenantId)->pluck('name', 'id');
        $strays = [];

        $outlets = DB::table('khana_outlets')
            ->where('tenant_id', $tenantId)
            ->whereNotIn('id', array_column($snapshot['outlets'], 'outlet_id'))
            ->where('changed_at', '<=', $asOf)
            ->orderBy('name')
            ->pluck('name');

        foreach ($outlets as $name) {
            $strays[] = 'CRM holds an outlet called "'.$name.'" that Accounts does not list for this business.';
        }

        $listed = array_map(fn (array $row) => $row['outlet_id'].':'.$row['product'], $snapshot['entitlements']);

        $plans = DB::table('khana_entitlements')->where('tenant_id', $tenantId)->where('changed_at', '<=', $asOf)->orderBy('id')->get();

        foreach ($plans as $plan) {
            if (! in_array($plan->outlet_id.':'.$plan->product, $listed, true)) {
                $strays[] = 'CRM holds a '.(self::PRODUCTS[$plan->product] ?? $plan->product).' plan for '
                    .(isset($names[$plan->outlet_id]) ? '"'.$names[$plan->outlet_id].'"' : 'an outlet it does not know')
                    .' that Accounts does not list.';
            }
        }

        return $strays;
    }

    /**
     * Apply one part and say whether the row it is about came out different:
     * 1 if it did, 0 if CRM already had it right.
     *
     * @param  array<string, string>  $key
     */
    private function differed(string $table, array $key, Closure $apply): int
    {
        $before = self::row($table, $key);

        $apply();

        return self::row($table, $key) === $before ? 0 : 1;
    }

    /**
     * A reference row as it is compared: without the time it was last written,
     * which every application moves, and with its JSON read rather than spelt,
     * so the order of keys is not a difference.
     *
     * @param  array<string, string>  $key
     * @return array<string, mixed>
     */
    private static function row(string $table, array $key): array
    {
        $row = Arr::except((array) DB::table($table)->where($key)->first(), ['changed_at']);

        foreach (['address', 'limits'] as $json) {
            if (isset($row[$json]) && is_string($row[$json])) {
                $row[$json] = Arr::sortRecursive((array) json_decode($row[$json], true));
            }
        }

        return $row;
    }

    /**
     * One part of a snapshot, dressed as the event it has the shape of. The
     * time the snapshot was taken stands for when it happened, which is what
     * lets the handlers tell it from something newer.
     *
     * @param  array<string, mixed>  $data
     */
    private static function envelope(string $type, string $tenantId, ?string $outletId, array $data, string $at): Envelope
    {
        return new Envelope(
            id: Ulid::generate(), type: $type, version: 1, source: 'accounts', seq: 1,
            occurredAt: $at, tenantId: $tenantId, outletId: $outletId, data: $data,
        );
    }
}
