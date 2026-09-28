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
            ShopSeeder::class,
            // What Accounts would have sold the shops above, so the seeded
            // install can be signed in to. Not in production - see it.
            CrmEntitlementSeeder::class,
            CatalogSeeder::class,
        ]);
    }
}
