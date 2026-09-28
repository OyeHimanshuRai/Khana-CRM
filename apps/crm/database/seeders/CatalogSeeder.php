<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Services\CatalogStarter;
use Illuminate\Database\Seeder;

class CatalogSeeder extends Seeder
{
    /**
     * The masters a shop cannot open without.
     *
     * Units and GST slabs are not really the user's data - they are the
     * vocabulary the rest of the system speaks. Shipping them means the
     * first product can be created in one screen instead of four.
     *
     * The lists themselves live in App\Services\CatalogStarter, because a
     * business that signs itself up needs exactly the same rows and there must
     * not be two copies of the GST slabs to keep in step. This seeder is now
     * the installer's caller: every company on the install gets a set.
     *
     * Idempotent on the natural keys, so re-running never duplicates a slab
     * and never overwrites a rate the business has since corrected.
     */
    public function run(): void
    {
        $starter = app(CatalogStarter::class);

        $tenants = Tenant::query()->orderBy('id')->get();

        foreach ($tenants as $tenant) {
            $starter->forTenant($tenant);
        }

        $this->command?->info(sprintf(
            '  %d unit(s) and %d tax slab(s) for %d compan%s.',
            count($starter->units()),
            count($starter->taxRates()),
            $tenants->count(),
            $tenants->count() === 1 ? 'y' : 'ies',
        ));
    }
}
