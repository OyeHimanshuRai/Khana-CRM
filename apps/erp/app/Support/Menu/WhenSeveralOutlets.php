<?php

namespace App\Support\Menu;

use App\Support\Outlets;
use Khana\Kit\Tenancy\CurrentOutlet;

/**
 * Transfers are only worth a tab when there is somewhere to send stock: the
 * business has another outlet using ERP. A single restaurant never sees it.
 *
 * Named in config/menu.php as a tab's `when`.
 */
final class WhenSeveralOutlets
{
    public function __invoke(array $tab): bool
    {
        $outlet = CurrentOutlet::id() ?? (CurrentOutlet::accessibleIds()[0] ?? null);

        return $outlet !== null && Outlets::find((string) $outlet) !== null && Outlets::others((string) $outlet)->isNotEmpty();
    }
}
