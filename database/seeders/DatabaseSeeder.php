<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            AdminUserSeeder::class,
            RolePermissionSeeder::class,
            CompanySettingSeeder::class,
            // After the settings, which is where the first tenant borrows
            // its identity from - and before the shops, which need a tenant
            // to belong to.
            TenantSeeder::class,
            // The price list. Deliberately after the tenant and deliberately
            // not connected to it: seeding what is for sale must not sell
            // anything to anybody. See PlanSeeder.
            PlanSeeder::class,
            ShopSeeder::class,
            // After the shops, because it gives each one a warehouse.
            CatalogSeeder::class,
            ExpenseCategorySeeder::class,
        ]);
    }
}
