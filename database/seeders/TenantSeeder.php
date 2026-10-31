<?php

namespace Database\Seeders;

use App\Models\Setting;
use App\Models\Shop;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;

class TenantSeeder extends Seeder
{
    /**
     * Create the first tenant and adopt everything that predates tenancy.
     *
     * The install was single-company before this, so there is exactly one
     * business to infer and its identity is already sitting in Settings >
     * Company - the same place ShopSeeder reads to name the first branch.
     *
     * Runs before ShopSeeder so a branch created on a fresh install has a
     * tenant to belong to, and is idempotent so an existing install is
     * adopted rather than duplicated.
     */
    public function run(): void
    {
        $tenant = Tenant::query()->orderBy('id')->first() ?? $this->create();

        $this->adoptShops($tenant);
        $this->adoptUsers($tenant);
    }

    private function create(): Tenant
    {
        $name = Setting::get('company_name', config('app.name'));

        return Tenant::query()->create([
            'name' => $name,
            'code' => 'MAIN',
            'slug' => Tenant::uniqueSlug($name),
            'legal_name' => Setting::get('company_title'),
            'gstin' => Setting::get('gstin'),
            'pan' => Setting::get('pan_no'),
            'phone' => Setting::get('phone'),
            'email' => Setting::get('company_email'),
            'address_line1' => Setting::get('address_line_1'),
            'address_line2' => Setting::get('address_line_2'),
            'city' => Setting::get('city'),
            'state' => Setting::get('state'),
            'pincode' => Setting::get('pincode'),
            'currency' => Setting::get('currency', 'INR'),
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }

    /**
     * Every branch without a tenant belongs to the first one.
     *
     * True by definition on an install that has only ever had one business.
     * Once a second tenant exists this adopts nothing, because by then every
     * branch was created with its tenant already set.
     */
    private function adoptShops(Tenant $tenant): void
    {
        $adopted = Shop::withTrashed()
            ->whereNull('tenant_id')
            ->update(['tenant_id' => $tenant->id]);

        if ($adopted > 0) {
            $this->command?->info(sprintf('  %d branch(es) adopted by "%s".', $adopted, $tenant->name));
        }
    }

    /**
     * Staff join the tenant; Super Admins deliberately do not.
     *
     * A null tenant_id on a Super Admin is not an omission - it is what
     * makes them cross-tenant. Filling it in would quietly demote the one
     * account that has to be able to see every business.
     */
    private function adoptUsers(Tenant $tenant): void
    {
        $adopted = 0;

        foreach (User::query()->whereNull('tenant_id')->get() as $user) {
            if ($user->isSuperAdmin()) {
                continue;
            }

            $user->forceFill(['tenant_id' => $tenant->id])->save();
            $adopted++;
        }

        $this->command?->info(sprintf(
            '  %d user(s) joined "%s"; %d super admin(s) left cross-tenant.',
            $adopted,
            $tenant->name,
            User::query()->whereNull('tenant_id')->count(),
        ));
    }
}
