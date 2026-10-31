<?php

namespace Database\Seeders;

use App\Models\Setting;
use App\Models\Shop;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;

class ShopSeeder extends Seeder
{
    /**
     * Create the first shop and put every existing admin in it.
     *
     * A multi-tenant system with no tenants has nowhere to file anything, so
     * the install needs one from the start. It borrows its identity from
     * Settings > General, which is where a single-shop business will already
     * have typed its name and address.
     *
     * Idempotent: re-running attaches anyone new without disturbing the shop.
     */
    public function run(): void
    {
        $shop = Shop::firstOrCreate(
            ['code' => 'MAIN'],
            [
                // Branches hang off a tenant now; TenantSeeder runs first and
                // guarantees there is one.
                'tenant_id' => Tenant::query()->orderBy('id')->value('id'),
                'name' => Setting::get('company_name', config('app.name')),
                'slug' => Shop::uniqueSlug(Setting::get('company_name', 'main-shop')),
                'phone' => Setting::get('phone'),
                'email' => Setting::get('company_email'),
                'address_line1' => Setting::get('address_line_1'),
                'address_line2' => Setting::get('address_line_2'),
                'currency' => Setting::get('currency', 'INR'),
                'is_active' => true,
                'sort_order' => 0,
            ]
        );

        /*
         | Attach every admin account. Super Admin does not need the pivot -
         | it reaches every shop regardless - but having the row means the
         | switcher and the "my shops" list read the same for everyone.
         */
        $admins = User::where('is_admin', true)->get();

        foreach ($admins as $admin) {
            $shop->users()->syncWithoutDetaching([
                $admin->id => ['is_default' => true],
            ]);

            if ($admin->current_shop_id === null) {
                $admin->forceFill(['current_shop_id' => $shop->id])->save();
            }
        }

        $this->command?->info(sprintf(
            '  Shop "%s" (%s) with %d user(s).',
            $shop->name,
            $shop->code,
            $admins->count(),
        ));
    }
}
