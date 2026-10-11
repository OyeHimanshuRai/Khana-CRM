<?php

namespace Database\Seeders;

use App\Models\Batch;
use App\Models\Role;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\StockService;
use App\Stock\Posting;
use App\Support\Outlets;
use App\Support\Roles;
use App\Support\Units;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * A restaurant to look at on a developer's machine: the reference copy
 * Accounts would have sent, the people to open ERP as, and a store room
 * with a week's stock in it.
 *
 * It has two outlets, so there is somewhere to send stock, and one role of
 * its own, so the Roles screen has something on it.
 *
 * Safe to run twice - everything is keyed and looked up before it is made.
 */
class DemoSeeder extends Seeder
{
    public const TENANT = '01K6DEM0TENANT000000000001';

    public const OUTLET = '01K6DEM0SH0P00000000000001';

    /** The second outlet: a branch with empty shelves. */
    public const BRANCH = '01K6DEM0SH0P00000000000002';

    public function run(): void
    {
        $this->reference();
        $this->people();

        if (StockItem::allTenants()->where('tenant_id', self::TENANT)->exists()) {
            return;
        }

        $this->storeRoom();
        $this->call(DemoServiceSeeder::class);
    }

    private function reference(): void
    {
        $stamp = ['changed_at' => '2026-09-26 00:00:00'];

        DB::table('khana_tenants')->updateOrInsert(['id' => self::TENANT], ['name' => 'Spice Garden', 'status' => 'active'] + $stamp);

        foreach ([
            self::OUTLET => ['Spice Garden, MG Road', 'SGMG', '12 MG Road', '110001'],
            self::BRANCH => ['Spice Garden, Saket', 'SGSK', '4 Press Enclave Road', '110017'],
        ] as $outlet => [$name, $code, $street, $pincode]) {
            DB::table('khana_outlets')->updateOrInsert(['id' => $outlet], [
                'tenant_id' => self::TENANT,
                'name' => $name,
                'code' => $code,
                'address' => json_encode(['line1' => $street, 'line2' => null, 'city' => 'Delhi', 'pincode' => $pincode]),
                'state_code' => '07',
                'timezone' => 'Asia/Kolkata',
                'day_cutoff' => '04:00',
                'status' => 'active',
            ] + $stamp);

            foreach (['crm', 'erp'] as $product) {
                DB::table('khana_entitlements')->updateOrInsert(['outlet_id' => $outlet, 'product' => $product], [
                    'tenant_id' => self::TENANT,
                    'state' => 'active',
                    'plan' => 'suite',
                    'limits' => '{}',
                ] + $stamp);
            }
        }
    }

    private function people(): void
    {
        // The business's own role: a chef who writes recipes, makes the
        // gravy and counts the kitchen, and never sees a supplier's bill.
        Role::allTenants()->updateOrCreate(['tenant_id' => self::TENANT, 'key' => 'head_chef'], [
            'name' => 'Head Chef',
            'abilities' => Roles::grantable(['recipes.manage', 'wastage', 'count']),
            'based_on' => 'storekeeper',
        ]);

        foreach ([
            ['01K6DEM0PERS0N000000000001', 'Meera Owner', 'owner', [self::OUTLET, self::BRANCH]],
            ['01K6DEM0PERS0N000000000002', 'Ravi Storekeeper', 'storekeeper', [self::OUTLET]],
            ['01K6DEM0PERS0N000000000003', 'Anil Accountant', 'accountant', [self::OUTLET]],
            ['01K6DEM0PERS0N000000000004', 'Sunita Chef', 'head_chef', [self::OUTLET]],
            ['01K6DEM0PERS0N000000000005', 'Kiran at Saket', 'storekeeper', [self::BRANCH]],
        ] as [$ulid, $name, $role, $outlets]) {
            User::query()->updateOrCreate(['ulid' => $ulid], [
                'tenant_id' => self::TENANT,
                'name' => $name,
                'email' => strtolower(strtok($name, ' ')).'@spicegarden.test',
                'role_key' => $role,
                'outlet_ids' => $outlets,
            ]);
        }
    }

    private function storeRoom(): void
    {
        ['main' => $main, 'kitchen' => $kitchen] = Outlets::stores(self::OUTLET);
        $stock = app(StockService::class);
        $today = Outlets::businessDate(self::OUTLET);

        // name, category, base, rupees per kg/litre/piece, received (kg/l/pc), to kitchen, days to expiry, reorder
        $items = [
            ['Paneer', 'Dairy', 'g', 400, 12, 4, 6, 5],
            ['Curd', 'Dairy', 'g', 80, 10, 3, 4, 4],
            ['Butter', 'Dairy', 'g', 520, 6, 2, 30, 2],
            ['Cream', 'Dairy', 'ml', 260, 5, 2, 5, 2],
            ['Chicken', 'Meat', 'g', 240, 15, 6, 2, 8],
            ['Basmati Rice', 'Dry goods', 'g', 110, 50, 10, null, 20],
            ['Wheat Flour', 'Dry goods', 'g', 42, 40, 10, null, 15],
            ['Refined Oil', 'Dry goods', 'ml', 150, 30, 10, null, 10],
            ['Onion', 'Vegetables', 'g', 35, 25, 10, null, 10],
            ['Tomato', 'Vegetables', 'g', 40, 20, 8, null, 8],
            ['Tikka Masala', 'Spices', 'g', 600, 2, 1, null, 1],
            ['Cola 300 ml', 'Beverages', 'pc', 28, 96, 24, 120, 48],
        ];

        foreach ($items as [$name, $category, $base, $price, $received, $issued, $expires, $reorder]) {
            $big = match ($base) {
                'g' => 'kg', 'ml' => 'l', default => 'pc',
            };

            $item = StockItem::allTenants()->create([
                'tenant_id' => self::TENANT,
                'name' => $name,
                'category' => $category,
                'base_unit' => $base,
                'track_expiry' => $expires !== null,
                'reorder_qty' => Units::toBase($reorder, $big, $base),
                'barcode' => $name === 'Cola 300 ml' ? '8901764012273' : null,
            ]);

            $batch = $expires === null ? null : Batch::allOutlets()->create([
                'outlet_id' => self::OUTLET,
                'stock_item_id' => $item->id,
                'batch_no' => strtoupper(substr($name, 0, 3)).'-'.date('md'),
                'expiry_date' => now()->parse($today)->addDays($expires)->toDateString(),
            ]);

            $stock->receive($main, $item, Units::toBase($received, $big, $base), Units::rateFromQuote($price, $base),
                new Posting(StockMovement::OPENING, reason: 'Opening stock', businessDate: $today), $batch);

            $stock->move($main, $kitchen, $item, Units::toBase($issued, $big, $base),
                new Posting(StockMovement::TRANSFER_OUT, businessDate: $today));
        }
    }
}
