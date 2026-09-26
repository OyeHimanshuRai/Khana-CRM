<?php

namespace Khana\Kit\Tests\Concerns;

use Illuminate\Support\Facades\DB;
use Khana\Kit\Tenancy\Entitlements;

/**
 * Puts businesses, outlets and what each outlet bought into the local copy of
 * Accounts, as the accounts.* events would have.
 */
trait SeedsReference
{
    /**
     * @param  array<string, string>  $products  product => state, e.g. ['crm' => 'active', 'erp' => 'suspended']
     */
    protected function seedOutlet(string $tenantId, string $outletId, array $products = ['crm' => 'active', 'erp' => 'active'], string $name = 'Outlet'): void
    {
        DB::table('khana_tenants')->updateOrInsert(['id' => $tenantId], [
            'name' => 'Business '.substr($tenantId, -4),
            'status' => 'active',
            'changed_at' => '2026-09-26 00:00:00',
        ]);

        DB::table('khana_outlets')->updateOrInsert(['id' => $outletId], [
            'tenant_id' => $tenantId,
            'name' => $name,
            'code' => 'O'.substr($outletId, -3),
            'address' => json_encode(['line1' => '1 Road', 'line2' => null, 'city' => 'Delhi', 'pincode' => null]),
            'state_code' => '07',
            'timezone' => 'Asia/Kolkata',
            'day_cutoff' => '04:00',
            'status' => 'active',
            'changed_at' => '2026-09-26 00:00:00',
        ]);

        foreach ($products as $product => $state) {
            DB::table('khana_entitlements')->updateOrInsert(['outlet_id' => $outletId, 'product' => $product], [
                'tenant_id' => $tenantId,
                'state' => $state,
                'plan' => $product,
                'limits' => '{}',
                'changed_at' => '2026-09-26 00:00:00',
            ]);
        }

        app(Entitlements::class)->forget();
    }
}
