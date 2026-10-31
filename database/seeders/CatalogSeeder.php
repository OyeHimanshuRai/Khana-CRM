<?php

namespace Database\Seeders;

use App\Models\Shop;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\Warehouse;
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
     * Idempotent on the natural keys, so re-running never duplicates a slab
     * and never overwrites a rate the business has since corrected.
     */
    public function run(): void
    {
        $this->units();
        $this->taxRates();
        $this->warehouses();
    }

    private function units(): void
    {
        $units = [
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

        foreach ($units as $index => [$code, $name, $decimal, $precision]) {
            Unit::firstOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'allow_decimal' => $decimal,
                    'precision' => $precision,
                    'is_active' => true,
                    'sort_order' => $index,
                ]
            );
        }

        $this->command?->info(sprintf('  %d units.', count($units)));
    }

    private function taxRates(): void
    {
        // The Indian GST slabs a restaurant actually meets. Food service sits
        // at 5%; the wider set is kept because a shop sells packaged goods and
        // beverages alongside.
        $slabs = [
            ['Exempt / Nil', 0, true],
            ['GST 3%', 3, false],
            ['GST 5%', 5, false],
            ['GST 12%', 12, false],
            ['GST 18%', 18, false],
            ['GST 28%', 28, false],
        ];

        foreach ($slabs as $index => [$name, $rate, $isDefault]) {
            TaxRate::firstOrCreate(
                ['name' => $name],
                [
                    'rate' => $rate,
                    // Intra-state splits evenly across these slabs.
                    'cgst' => $rate / 2,
                    'sgst' => $rate / 2,
                    'igst' => $rate,
                    'cess' => 0,
                    'is_default' => $isDefault,
                    'is_active' => true,
                    'sort_order' => $index,
                ]
            );
        }

        $this->command?->info(sprintf('  %d tax slabs.', count($slabs)));
    }

    /**
     * Give every shop somewhere to put stock.
     */
    private function warehouses(): void
    {
        $created = 0;

        foreach (Shop::withTrashed()->get() as $shop) {
            $exists = Warehouse::allShops()->where('shop_id', $shop->id)->exists();

            if ($exists) {
                continue;
            }

            Warehouse::withoutEvents(fn () => Warehouse::query()->create([
                'shop_id' => $shop->id,
                'name' => 'Main Store',
                'code' => 'MAIN',
                'is_default' => true,
                'is_active' => true,
                'sort_order' => 0,
            ]));

            $created++;
        }

        $this->command?->info(sprintf('  %d warehouse(s) created.', $created));
    }
}
