<?php

namespace App\Services;

use App\Models\Shop;
use App\Models\TaxRate;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\Warehouse;

/**
 * The vocabulary a new business cannot open without (§8, §10).
 *
 * ---------------------------------------------------------------------------
 * Why a restaurant is given rows it did not create
 * ---------------------------------------------------------------------------
 *
 * Units and GST slabs are not really anybody's data. They are the words the
 * rest of the system speaks: a product needs a unit before it can be saved and
 * a tax rate before it can be billed, and "kilogram" and "GST 5%" mean the same
 * thing in every restaurant in India. Shipping them means the first dish can be
 * created in one screen instead of four.
 *
 * Until the catalogue belonged to a company this was a seeder's job and ran
 * once per install. Now that each business owns its own masters, every new
 * business needs its own copy - so the lists moved here and CatalogSeeder
 * calls the same code. One list, two callers; a slab added here appears on a
 * fresh install and on tonight's signup without anybody remembering both.
 *
 * ---------------------------------------------------------------------------
 * Copies, not shared rows
 * ---------------------------------------------------------------------------
 *
 * Each company gets its own rows rather than pointing at platform-wide ones,
 * because these are edited: a business corrects a slab when the law changes,
 * renames a unit, or switches one off. Shared rows would make one restaurant's
 * correction everybody's, which is the failure this whole change was made to
 * stop.
 *
 * Idempotent on the natural keys, so running it twice never duplicates a slab
 * and never overwrites a rate the business has since corrected.
 */
class CatalogStarter
{
    /**
     * Units and tax slabs for one business.
     */
    public function forTenant(Tenant|int $tenant): void
    {
        $tenantId = $tenant instanceof Tenant ? $tenant->id : $tenant;

        foreach ($this->units() as $index => [$code, $name, $decimal, $precision]) {
            /*
             | Written with the company named rather than left to the model's
             | `creating` hook: at signup there is no authenticated user and no
             | company in context - the account is being made - so the hook
             | would fall back to the sole-company guess, which is wrong on
             | every install that has more than one.
             |
             | forceCreate, because `tenant_id` is deliberately not fillable on
             | these models: ordinary writes get it stamped by the hook from
             | the request's own company, and a mass-assignable tenant_id is a
             | form field that could file a row under somebody else. This is
             | the one caller that legitimately names it, so this is the one
             | place that goes around the guard.
             */
            $exists = Unit::allTenants()
                ->where('tenant_id', $tenantId)
                ->where('code', $code)
                ->exists();

            if ($exists) {
                continue;
            }

            Unit::query()->forceCreate([
                'tenant_id' => $tenantId,
                'code' => $code,
                'name' => $name,
                'allow_decimal' => $decimal,
                'precision' => $precision,
                'is_active' => true,
                'sort_order' => $index,
            ]);
        }

        foreach ($this->taxRates() as $index => [$name, $rate, $isDefault]) {
            $exists = TaxRate::allTenants()
                ->where('tenant_id', $tenantId)
                ->where('name', $name)
                ->exists();

            if ($exists) {
                continue;
            }

            TaxRate::query()->forceCreate([
                'tenant_id' => $tenantId,
                'name' => $name,
                'rate' => $rate,
                // Intra-state splits evenly across these slabs.
                'cgst' => $rate / 2,
                'sgst' => $rate / 2,
                'igst' => $rate,
                'cess' => 0,
                'is_default' => $isDefault,
                'is_active' => true,
                'sort_order' => $index,
            ]);
        }
    }

    /**
     * Somewhere for an outlet to put stock.
     *
     * Every branch needs one before a purchase can be received or a recipe can
     * consume anything, and a restaurant should not have to know that to open
     * its doors.
     */
    public function forShop(Shop|int $shop): void
    {
        $shopId = $shop instanceof Shop ? $shop->id : $shop;

        if (Warehouse::allShops()->where('shop_id', $shopId)->exists()) {
            return;
        }

        // withoutEvents, like the seeder: the shop is named explicitly and the
        // model's own stamping would only ask the request which branch it is
        // working in - which, at signup, is none yet.
        Warehouse::withoutEvents(fn () => Warehouse::query()->create([
            'shop_id' => $shopId,
            'name' => 'Main Store',
            'code' => 'MAIN',
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]));
    }

    /* ----------------------------------------------------------- the lists */

    /**
     * @return array<int, array{0: string, 1: string, 2: bool, 3: int}>
     */
    public function units(): array
    {
        return [
            // code, name, decimals allowed, precision
            ['PCS', 'Pieces', false, 0],
            ['BAG', 'Bag', false, 0],
            ['BOX', 'Box', false, 0],
            ['PKT', 'Packet', false, 0],
            ['BTL', 'Bottle', false, 0],
            ['KG', 'Kilogram', true, 3],
            ['GM', 'Gram', true, 2],
            ['QTL', 'Quintal', true, 3],
            ['TON', 'Tonne', true, 3],
            ['LTR', 'Litre', true, 3],
            ['ML', 'Millilitre', true, 2],
            ['MTR', 'Metre', true, 2],
            ['SET', 'Set', false, 0],
        ];
    }

    /**
     * The Indian GST slabs a restaurant actually meets.
     *
     * Food service sits at 5%; the wider set is kept because a shop sells
     * packaged goods and beverages alongside.
     *
     * @return array<int, array{0: string, 1: int, 2: bool}>
     */
    public function taxRates(): array
    {
        return [
            ['Exempt / Nil', 0, true],
            ['GST 3%', 3, false],
            ['GST 5%', 5, false],
            ['GST 12%', 12, false],
            ['GST 18%', 18, false],
            ['GST 28%', 28, false],
        ];
    }
}
