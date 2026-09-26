<?php

namespace Database\Seeders;

use App\Models\Shop;
use App\Models\User;
use App\Support\CurrentShop;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * A shop with a year of trading behind it.
 *
 * Every module gets roughly twenty rows, and - this is the point - they are
 * rows the application itself would have written. Purchases go in through
 * PurchaseService, sales through InvoiceService, movements through
 * StockService, so the stock on the shelf matches the movements that put it
 * there, a customer's balance matches their ledger, and the dashboard's
 * profit tile is arithmetic on real cost prices rather than a number someone
 * typed. Data inserted straight into the tables would look identical on the
 * list screens and fall apart on the first report.
 *
 *     php artisan db:seed --class=DemoDataSeeder
 *
 * Safe to re-run: each seeder leaves a module alone once it holds its target
 * number of rows. To start over, migrate:fresh --seed first.
 *
 * Not called from DatabaseSeeder - a fresh install belongs to the business
 * that bought it, and should come up empty rather than full of invented
 * customers.
 */
class DemoDataSeeder extends Seeder
{
    /**
     * The order is the dependency order, and none of it is negotiable: there
     * is nothing to sell before it has been bought, nothing to buy before
     * there is a supplier, and no supplier before there is a shop to file
     * them under.
     */
    public function run(): void
    {
        $shop = $this->bootstrap();

        $this->command?->info(sprintf('Seeding demo data into "%s".', $shop->name));

        $this->call([
            DemoMenuSeeder::class,
            // After the menu, because it routes the sections that seeder
            // wrote - and before anything that places an order, because a
            // ticket's station is stamped on at placement and cannot be
            // backfilled onto one that has already been sent.
            DemoKitchenSeeder::class,
            // After the menu too: it writes recipes for those dishes, and the
            // ingredients they are made of.
            DemoRecipeSeeder::class,
            // The room before anything that seats people in it.
            DemoDiningSeeder::class,
            DemoPartySeeder::class,
            // Stock first: a sale cannot issue what was never received.
            DemoPurchasingSeeder::class,
            DemoSalesSeeder::class,
            DemoInventorySeeder::class,
            DemoFinanceSeeder::class,
            DemoStorefrontSeeder::class,
            DemoContentSeeder::class,
        ]);

        $this->command?->info('Demo data seeded.');
    }

    /**
     * Sign in as an administrator and pick a shop to work in.
     *
     * The services below read Auth::user() to stamp created_by on documents
     * and CurrentShop to scope every query. Run without either and the
     * seeding either fails on a scoped lookup or files a year of trading
     * against nobody.
     */
    private function bootstrap(): Shop
    {
        $admin = User::query()
            ->where('is_admin', true)
            ->orderBy('id')
            ->first()
            ?? throw new RuntimeException(
                'No admin user to seed as. Run `php artisan db:seed` first.'
            );

        Auth::login($admin);
        CurrentShop::forget();

        $shop = CurrentShop::get()
            ?? Shop::query()->orderBy('id')->first()
            ?? throw new RuntimeException(
                'No shop to seed into. Run `php artisan db:seed` first.'
            );

        // An admin in All-shops mode has no single shop to file a document
        // against, and every document below needs one.
        if (CurrentShop::id() === null) {
            $admin->forceFill(['current_shop_id' => $shop->id, 'all_shops_view' => false])->save();
            CurrentShop::forget();
        }

        return $shop;
    }
}
