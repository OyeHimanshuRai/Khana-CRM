<?php

namespace App\Services;

use App\Auth\PeopleHandlers;
use App\Jobs\PullMenuFromCrm;
use App\Models\User;
use App\Services\Accounts\AccountsClient;
use App\Services\Accounts\AccountsUnavailable;
use App\Support\Outlets;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Khana\Kit\Events\Envelope;
use Khana\Kit\Support\Ulid;
use Khana\Kit\Tenancy\Entitlements;
use Khana\Kit\Tenancy\ReferenceHandlers;

/**
 * ERP's copy of what Accounts owns - the business, its outlets, what each
 * outlet has bought, and the people ERP has seen - brought in line with
 * Accounts itself.
 *
 * Events keep that copy current, one change at a time. This is for when
 * they cannot be relied on: the nightly check, and somebody signing in to an
 * outlet ERP has not been told about yet. Accounts hands over the whole of
 * one business (its snapshot), and every part of it is applied by the very
 * code that applies the matching event - the kit's ReferenceHandlers and
 * ERP's PeopleHandlers - so the two ways of hearing a thing cannot come to
 * differ.
 *
 * Nothing newer is written over. The kit's handlers refuse a row changed
 * after the snapshot was taken, and the same is held here for people.
 * Nothing is deleted either: an outlet or a plan ERP holds that Accounts
 * does not list is handed back by name, for a person to look at.
 */
class ReferenceSync
{
    private const PRODUCTS = ['crm' => 'Khana CRM', 'erp' => 'Khana ERP'];

    public function __construct(
        private readonly AccountsClient $accounts,
        private readonly ReferenceHandlers $reference,
        private readonly PeopleHandlers $people,
        private readonly Entitlements $entitlements,
    ) {}

    /**
     * accounts.entitlement.changed, and each entitlement of a snapshot.
     *
     * The kit keeps the copy. What ERP adds: holding both products is what
     * switches the link with CRM on, so an outlet that has just come to
     * hold both - and whose menu has never been pulled - has it pulled once.
     * Queued, and only after the commit: asking CRM is not something to do
     * inside the transaction an event is applied in.
     */
    public function entitlement(Envelope $event): void
    {
        $this->reference->entitlement($event);

        $this->firstSyncIfDue((string) $event->outletId);
    }

    /**
     * Ask Accounts for the business and bring ERP's copy in line with it.
     *
     * The asking is done first and on its own; the transaction opens only
     * once the answer is in hand.
     *
     * @return array{changed: int, strays: list<string>} how many rows differed, and what ERP holds that Accounts does not list
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

        $result = DB::transaction(function () use ($tenantId, $snapshot, $at, $takenAt) {
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

                $differed = $this->differed('khana_entitlements', $key, fn () => $this->reference->entitlement(
                    self::envelope('accounts.entitlement.changed', $tenantId, $entitlement['outlet_id'], Arr::except($entitlement, 'outlet_id'), $at),
                ));

                // Only news is acted on, as the event would have been: a plan
                // ERP already had right was acted on when it arrived.
                if ($differed === 1) {
                    $this->firstSyncIfDue($entitlement['outlet_id']);
                }

                $changed += $differed;
            }

            return [
                'changed' => $changed + $this->people($tenantId, $snapshot, $at, $takenAt),
                'strays' => $this->strays($tenantId, $snapshot, $takenAt),
            ];
        });

        // An outlet's name, timezone and cutoff are remembered for the life
        // of the process; they may just have changed.
        Outlets::forget();

        return $result;
    }

    /* ---------------------------------------------------------- private */

    /** An outlet holding both products, whose menu has never been pulled, has it pulled. */
    private function firstSyncIfDue(string $outletId): void
    {
        if ($this->entitlements->isSuite($outletId) && Outlets::settings($outletId)->menu_synced_at === null) {
            PullMenuFromCrm::dispatch($outletId)->afterCommit();
        }
    }

    /**
     * The people ERP has seen, brought in line with what Accounts says of
     * them: who they are, and what they hold in ERP.
     *
     * Somebody the snapshot gives nothing in ERP has lost it, whether or not
     * the event that said so arrived - so that is applied too, as the event
     * would have been. A row changed here since the snapshot was taken (a
     * sign-in, a later event) is newer than the snapshot and is left alone.
     *
     * @param  array<string, mixed>  $snapshot
     * @return int how many people's rows differed
     */
    private function people(string $tenantId, array $snapshot, string $at, CarbonImmutable $takenAt): int
    {
        $persons = collect($snapshot['users'])->keyBy('user_id');
        $holds = collect($snapshot['access'])->where('product', 'erp')->keyBy('user_id');
        $changed = 0;

        foreach (User::query()->where('tenant_id', $tenantId)->orderBy('id')->get() as $user) {
            if ($user->updated_at !== null && $user->updated_at->gt($takenAt)) {
                continue;
            }

            $before = self::person($user);

            if ($persons->has($user->ulid)) {
                $this->people->person(self::envelope('accounts.user.upserted', $tenantId, null, $persons[$user->ulid], $at));
            }

            $access = $holds[$user->ulid] ?? ($user->role_key === null ? null : [
                'user_id' => $user->ulid, 'product' => 'erp', 'role_key' => null, 'outlet_ids' => [],
            ]);

            if ($access !== null) {
                $this->people->access(self::envelope('accounts.access.changed', $tenantId, null, $access, $at));
            }

            if (self::person($user->fresh()) !== $before) {
                $changed++;
            }
        }

        return $changed;
    }

    /**
     * What ERP holds for this business that Accounts does not list. Named,
     * never deleted: the books an outlet wrote are still its books.
     *
     * @param  array<string, mixed>  $snapshot
     * @return list<string>
     */
    private function strays(string $tenantId, array $snapshot, CarbonImmutable $takenAt): array
    {
        // A row changed after the snapshot was taken is news the snapshot
        // has not heard, not a stray.
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
            $strays[] = 'ERP holds an outlet called "'.$name.'" that Accounts does not list for this business.';
        }

        $listed = array_map(fn (array $row) => $row['outlet_id'].':'.$row['product'], $snapshot['entitlements']);

        $plans = DB::table('khana_entitlements')->where('tenant_id', $tenantId)->where('changed_at', '<=', $asOf)->orderBy('id')->get();

        foreach ($plans as $plan) {
            if (! in_array($plan->outlet_id.':'.$plan->product, $listed, true)) {
                $strays[] = 'ERP holds a '.(self::PRODUCTS[$plan->product] ?? $plan->product).' plan for '
                    .(isset($names[$plan->outlet_id]) ? '"'.$names[$plan->outlet_id].'"' : 'an outlet it does not know')
                    .' that Accounts does not list.';
            }
        }

        return $strays;
    }

    /**
     * Apply one part and say whether the row it is about came out
     * different: 1 if it did, 0 if ERP already had it right.
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
     * A reference row as it is compared: without the time it was last
     * written, which every application moves, and with its JSON read rather
     * than spelt, so the order of keys is not a difference.
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

    /** @return array<string, mixed> what Accounts has a say in, of one person's row */
    private static function person(User $user): array
    {
        $outlets = array_values((array) $user->outlet_ids);
        sort($outlets);

        return [
            'name' => $user->name, 'email' => $user->email, 'mobile' => $user->mobile,
            'is_active' => (bool) $user->is_active, 'role_key' => $user->role_key, 'outlet_ids' => $outlets,
        ];
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
