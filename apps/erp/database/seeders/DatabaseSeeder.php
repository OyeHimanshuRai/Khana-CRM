<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * A fresh ERP needs nothing seeded: businesses, outlets and people arrive
 * from Khana Accounts, and each outlet's stores are made the first time it
 * is opened.
 *
 * On a developer's machine there is no Accounts to send any of that, so the
 * demo seeder stands in for it.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('local')) {
            $this->call(DemoSeeder::class);
        }
    }
}
