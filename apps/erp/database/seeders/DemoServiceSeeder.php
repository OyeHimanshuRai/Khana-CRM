<?php

namespace Database\Seeders;

use App\Models\PaidOut;
use App\Models\SalesItem;
use App\Models\StockItem;
use App\Models\Supplier;
use App\Services\Consumption;
use App\Services\MenuCopy;
use App\Services\Purchasing;
use App\Services\Recipes;
use App\Services\StoreRoom;
use App\Support\Outlets;
use App\Support\Units;
use Illuminate\Database\Seeder;
use Khana\Kit\Support\Ulid;

/**
 * The rest of the demo restaurant: who it buys from, the menu CRM would
 * have sent, the chef's recipes, and a day of service - so every screen in
 * ERP has something in it to look at.
 *
 * Everything goes through the same services the screens and the event
 * handlers use; nothing is inserted behind their backs.
 */
class DemoServiceSeeder extends Seeder
{
    public function run(): void
    {
        $outlet = DemoSeeder::OUTLET;
        $tenant = DemoSeeder::TENANT;
        $today = Outlets::businessDate($outlet);
        $yesterday = now()->parse($today)->subDay()->toDateString();

        $item = fn (string $name) => StockItem::allTenants()->where('tenant_id', $tenant)->where('name', $name)->firstOrFail();

        /* ----------------------------------------------------- suppliers */

        $dairy = Supplier::allTenants()->create(['tenant_id' => $tenant, 'name' => 'Amul Dairy Distributors', 'contact_name' => 'Rakesh', 'phone' => '9810000011', 'terms_days' => 7]);
        Supplier::allTenants()->create(['tenant_id' => $tenant, 'name' => 'Azadpur Vegetables', 'phone' => '9810000022', 'terms_days' => 0]);
        $grocer = Supplier::allTenants()->create(['tenant_id' => $tenant, 'name' => 'Shree Kirana Wholesale', 'terms_days' => 15]);

        $line = fn (StockItem $stock, string|int $amount, string $unit, int $rupees, array $extra = []) => [
            'item' => $stock, 'qty' => Units::toBase($amount, $unit, $stock->base_unit), 'entered_qty' => (string) $amount,
            'unit' => $unit, 'amount_paise' => $rupees * 100, 'batch_no' => null, 'expiry' => null,
        ] + $extra;

        $purchasing = app(Purchasing::class);
        $main = Outlets::stores($outlet)['main'];

        $purchasing->receive($outlet, ['store' => $main, 'supplier' => $dairy, 'received_on' => $yesterday, 'bill_no' => 'AD/2291'], [
            $line($item('Paneer'), 5, 'kg', 2050, ['batch_no' => 'PAN-'.date('md', strtotime($yesterday)), 'expiry' => now()->parse($today)->addDays(5)->toDateString()]),
            $line($item('Butter'), 2, 'kg', 1040, ['expiry' => now()->parse($today)->addDays(40)->toDateString()]),
        ]);

        $purchasing->order($outlet, $grocer, $today, now()->parse($today)->addDays(2)->toDateString(), [
            $line($item('Basmati Rice'), 50, 'kg', 5500),
            $line($item('Refined Oil'), 30, 'l', 4500),
        ], 'Deliver before 10 am');

        /* ---------------------------------------------- a prepared item */

        $gravy = StockItem::allTenants()->create([
            'tenant_id' => $tenant, 'name' => 'Makhani Gravy', 'category' => 'Prepared', 'base_unit' => 'ml', 'is_prepared' => true,
        ]);

        $ingredient = fn (StockItem $stock, string|int $amount, string $unit, bool $optional = false) => [
            'item' => $stock, 'qty' => Units::toBase($amount, $unit, $stock->base_unit),
            'entered_qty' => (string) $amount, 'unit' => $unit, 'optional' => $optional,
        ];

        $recipes = app(Recipes::class);

        $recipes->save($outlet, ['stock_item_id' => $gravy->id], [
            $ingredient($item('Tomato'), 4, 'kg'),
            $ingredient($item('Onion'), 2, 'kg'),
            $ingredient($item('Butter'), 500, 'g'),
            $ingredient($item('Cream'), 500, 'ml'),
        ], Units::toBase(5, 'l', 'ml'), 'Simmer for 40 minutes, blend and strain.');

        app(StoreRoom::class)->produce($outlet, Outlets::stores($outlet)['kitchen'], $gravy, Units::toBase(5, 'l', 'ml'), $today, 'Morning prep');

        /* ------------------------------------------- the menu, from CRM */

        $menu = app(MenuCopy::class);

        $dish = fn (string $name, int $rupees, array $sizes = [], array $extra = []) => array_merge([
            'item_id' => Ulid::generate(), 'name' => $name,
            'category' => ['category_id' => Ulid::generate(), 'name' => 'Mains'],
            'food_type' => 'veg', 'active' => true, 'price_paise' => $rupees * 100, 'sku' => null, 'barcode' => null,
            'sizes' => array_map(fn (array $size, int $i) => [
                'variant_id' => Ulid::generate(), 'name' => $size[0], 'sku' => null, 'barcode' => null,
                'price_paise' => $size[1] * 100, 'is_default' => $i === 0,
            ], $sizes, array_keys($sizes)),
            'addons' => [],
        ], $extra);

        $tikka = $dish('Paneer Tikka', 320, [['Full', 320], ['Half', 180]]);
        $butterChicken = $dish('Butter Chicken', 420);
        $rice = $dish('Jeera Rice', 160);
        $cola = $dish('Cola', 60, [], ['barcode' => '8901764012273']);
        $dal = $dish('Dal Makhani', 240);

        foreach ([$tikka, $butterChicken, $rice, $cola, $dal] as $data) {
            $menu->upsert($tenant, $outlet, $data);
        }

        $on = fn (array $data) => SalesItem::allOutlets()->where('outlet_id', $outlet)->where('ref', $data['item_id'])->firstOrFail();
        $size = fn (array $data, string $name) => $on($data)->sizes()->where('name', $name)->firstOrFail()->id;

        // Dal Makhani is left without a recipe, so "Needs recipe" has an entry.
        $recipes->save($outlet, ['sales_item_id' => $on($tikka)->id, 'sales_item_size_id' => $size($tikka, 'Full')], [
            $ingredient($item('Paneer'), 250, 'g'), $ingredient($item('Curd'), 50, 'g'), $ingredient($item('Tikka Masala'), 10, 'g'),
        ]);
        $recipes->save($outlet, ['sales_item_id' => $on($tikka)->id, 'sales_item_size_id' => $size($tikka, 'Half')], [
            $ingredient($item('Paneer'), 125, 'g'), $ingredient($item('Curd'), 25, 'g'), $ingredient($item('Tikka Masala'), 5, 'g'),
        ]);
        $recipes->save($outlet, ['sales_item_id' => $on($butterChicken)->id], [
            $ingredient($item('Chicken'), 200, 'g'), $ingredient($gravy, 150, 'ml'), $ingredient($item('Butter'), 20, 'g'),
            $ingredient($item('Cream'), 15, 'ml', optional: true),
        ]);
        $recipes->save($outlet, ['sales_item_id' => $on($rice)->id], [
            $ingredient($item('Basmati Rice'), 120, 'g'), $ingredient($item('Refined Oil'), 10, 'ml'),
        ]);

        /* --------------------------------------------- a day of service */

        $consumption = app(Consumption::class);
        $at = now();

        $cooked = function (array $data, ?string $sizeName, int $qty) use ($consumption, $tenant, $outlet, $at) {
            $variant = $sizeName === null ? null : collect($data['sizes'])->firstWhere('name', $sizeName)['variant_id'];
            $ref = Ulid::generate();

            $consumption->kitchenAdvanced($tenant, $outlet, [
                'order_id' => Ulid::generate(), 'line_id' => $ref, 'item_id' => $data['item_id'], 'variant_id' => $variant,
                'option_ids' => [], 'qty' => $qty.'.0000', 'stage' => 'ready',
            ], Ulid::generate(), $at);

            return [$data, $variant, $qty, $ref];
        };

        $bill = function (array $rows, int $number) use ($consumption, $tenant, $outlet, $today, $at) {
            $net = 0;

            $lines = array_map(function (array $row) use (&$net) {
                [$data, $variant, $qty, $kitchenRef] = $row;
                $price = $variant === null ? $data['price_paise'] : collect($data['sizes'])->firstWhere('variant_id', $variant)['price_paise'];
                $net += $price * $qty;

                return [
                    'ref' => Ulid::generate(), 'item_ref' => $data['item_id'], 'variant_ref' => $variant, 'option_refs' => [],
                    'name' => $data['name'], 'qty' => $qty.'.0000', 'net_paise' => $price * $qty,
                    'tax_paise' => (int) round($price * $qty * 0.05), 'kitchen_refs' => $kitchenRef ? [$kitchenRef] : [],
                ];
            }, $rows);

            $tax = (int) array_sum(array_column($lines, 'tax_paise'));

            $consumption->saleRecorded($tenant, $outlet, SalesItem::CRM, [
                'ref' => Ulid::generate(), 'invoice_no' => sprintf('POS/SGMG/%05d', $number), 'business_date' => $today, 'channel' => 'dine_in',
                'totals' => ['net_paise' => $net, 'tax_paise' => $tax, 'round_off_paise' => 0, 'grand_paise' => $net + $tax],
                'lines' => $lines,
            ], Ulid::generate(), $at);
        };

        $bill([$cooked($tikka, 'Full', 2), $cooked($butterChicken, null, 2), $cooked($rice, null, 2), [$cola, null, 3, null]], 412);
        $bill([$cooked($tikka, 'Half', 1), $cooked($dal, null, 2), [$cola, null, 2, null]], 413);
        $bill([$cooked($butterChicken, null, 3), $cooked($rice, null, 3)], 414);

        /* ------------------------------------------- cash from the till */

        PaidOut::allOutlets()->create([
            'ulid' => Ulid::generate(), 'tenant_id' => $tenant, 'outlet_id' => $outlet, 'business_date' => $today,
            'amount_paise' => 185_000, 'category' => 'gas', 'note' => 'Two commercial cylinders',
        ]);
    }
}
