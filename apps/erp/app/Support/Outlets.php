<?php

namespace App\Support;

use App\Models\OutletSetting;
use App\Models\Store;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Khana\Kit\Support\BusinessDay;
use Khana\Kit\Tenancy\Entitlements;
use RuntimeException;

/**
 * What ERP knows about an outlet: the reference copy Accounts keeps current
 * (khana_outlets), and the few things ERP adds - its stores and how its
 * stock behaves.
 */
final class Outlets
{
    /** @var array<string, object|null> */
    private static array $rows = [];

    /** @var array<string, Collection> */
    private static array $others = [];

    public static function find(string $outletId): ?object
    {
        return self::$rows[$outletId] ??= DB::table('khana_outlets')->where('id', $outletId)->first();
    }

    public static function tenantOf(string $outletId): string
    {
        return self::find($outletId)?->tenant_id
            ?? throw new RuntimeException("ERP has not heard of outlet {$outletId}.");
    }

    public static function name(string $outletId): string
    {
        return self::find($outletId)?->name ?? 'Unknown outlet';
    }

    /** The day a moment belongs to at this outlet - its timezone, its cutoff. */
    public static function businessDate(string $outletId, ?CarbonInterface $at = null): string
    {
        $outlet = self::find($outletId);

        return BusinessDay::dateOf(
            $at ?? now(),
            $outlet?->timezone ?? 'Asia/Kolkata',
            $outlet?->day_cutoff ?? BusinessDay::MIDNIGHT,
        );
    }

    /** A stored (UTC) moment, on the outlet's own clock. */
    public static function local(CarbonInterface $at, string $outletId): CarbonInterface
    {
        return $at->copy()->timezone(self::find($outletId)?->timezone ?? 'Asia/Kolkata');
    }

    public static function settings(string $outletId): OutletSetting
    {
        return OutletSetting::query()->find($outletId)
            ?? new OutletSetting(['outlet_id' => $outletId, 'consume_stage' => 'ready', 'exclude_expired' => true, 'input_tax_is_cost' => true, 'kitchen_only' => false]);
    }

    /**
     * The outlet's main and kitchen stores, made the first time they are
     * asked for - an outlet is ready to receive goods the moment ERP is
     * opened for it.
     *
     * @return array{main: Store, kitchen: Store}
     */
    public static function stores(string $outletId): array
    {
        $stores = Store::allOutlets()->where('outlet_id', $outletId)->whereIn('kind', [Store::MAIN, Store::KITCHEN])
            ->orderBy('id')->get()->keyBy('kind');

        foreach ([Store::MAIN => ['Main store', 'MAIN'], Store::KITCHEN => ['Kitchen', 'KIT']] as $kind => [$name, $code]) {
            if (! $stores->has($kind)) {
                $stores[$kind] = Store::allOutlets()->create([
                    'tenant_id' => self::tenantOf($outletId),
                    'outlet_id' => $outletId,
                    'name' => $name,
                    'code' => $code,
                    'kind' => $kind,
                ]);
            }
        }

        return ['main' => $stores[Store::MAIN], 'kitchen' => $stores[Store::KITCHEN]];
    }

    /**
     * The other outlets of the same business that stock can be sent to:
     * the ones that are open and can use ERP today. An outlet running CRM
     * alone has no shelf here to put it on.
     *
     * @return Collection<int, object{id: string, name: string, code: string}>
     */
    public static function others(string $outletId): Collection
    {
        return self::$others[$outletId] ??= DB::table('khana_outlets')
            ->join('khana_entitlements', 'khana_entitlements.outlet_id', '=', 'khana_outlets.id')
            ->where('khana_entitlements.product', 'erp')
            ->whereIn('khana_entitlements.state', Entitlements::USABLE)
            ->where('khana_outlets.tenant_id', self::tenantOf($outletId))
            ->where('khana_outlets.id', '!=', $outletId)
            ->where('khana_outlets.status', 'active')
            ->orderBy('khana_outlets.name')
            ->get(['khana_outlets.id', 'khana_outlets.name', 'khana_outlets.code']);
    }

    /** For tests and long-running workers: forget what was memoised. */
    public static function forget(): void
    {
        self::$rows = [];
        self::$others = [];
    }
}
