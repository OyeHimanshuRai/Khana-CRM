<?php

namespace App\Support;

use App\Models\OutletSetting;
use App\Models\Store;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Khana\Kit\Support\BusinessDay;
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

    /** For tests and long-running workers: forget what was memoised. */
    public static function forget(): void
    {
        self::$rows = [];
    }
}
