<?php

namespace App\Support;

use App\Models\StockItem;
use App\Models\Store;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Khana\Kit\Tenancy\CurrentOutlet;

/**
 * The outlet the signed-in person is working in.
 *
 * Stock is physical: a receipt, a count or an issue happens in one building.
 * So every ERP screen works in exactly one outlet - the one the switcher is
 * set to, or the person's first when it is on "all outlets".
 */
final class Here
{
    public static function outlet(): string
    {
        $outlet = CurrentOutlet::id() ?? (CurrentOutlet::accessibleIds()[0] ?? null);

        abort_if($outlet === null, 403, 'You have not been given an outlet in ERP.');

        return (string) $outlet;
    }

    public static function tenant(): string
    {
        return Outlets::tenantOf(self::outlet());
    }

    /** @return array{main: Store, kitchen: Store} */
    public static function mainStores(): array
    {
        return Outlets::stores(self::outlet());
    }

    /** @return Collection<int, Store> */
    public static function stores(): Collection
    {
        self::mainStores();

        return Store::forOutlet(self::outlet())->where('is_active', true)->orderBy('id')->get();
    }

    /** The stock items of the business this outlet belongs to. */
    public static function items(): Builder
    {
        return StockItem::forTenant(self::tenant());
    }

    public static function businessDate(): string
    {
        return Outlets::businessDate(self::outlet());
    }
}
