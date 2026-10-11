<?php

namespace Tests;

use App\Models\StockItem;
use App\Models\User;
use App\Support\Outlets;
use App\Support\Roles;
use App\Support\Units;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Khana\Kit\Support\Ulid;
use Khana\Kit\Tenancy\Context;
use Khana\Kit\Tenancy\Entitlements;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Memoised for the life of the process, which in a suite is many
        // databases - see App\Support\Outlets and App\Support\Roles.
        Outlets::forget();
        Roles::forget();
    }

    protected function tearDown(): void
    {
        Outlets::forget();
        Roles::forget();

        parent::tearDown();
    }

    /**
     * A business with one outlet that has bought ERP, the way Accounts'
     * events would have left the reference copy.
     *
     * @param  array<string, string>  $products  product => entitlement state
     * @return array{tenant: string, outlet: string}
     */
    protected function business(string $name = 'Spice Garden', array $products = ['erp' => 'active'], ?string $tenant = null): array
    {
        $tenant ??= Ulid::generate();
        $outlet = Ulid::generate();

        DB::table('khana_tenants')->updateOrInsert(['id' => $tenant], [
            'name' => $name,
            'status' => 'active',
            'changed_at' => '2026-09-26 00:00:00',
        ]);

        DB::table('khana_outlets')->insert([
            'id' => $outlet,
            'tenant_id' => $tenant,
            'name' => $name.' '.substr($outlet, -3),
            'code' => 'O'.substr($outlet, -3),
            'address' => json_encode(['line1' => '1 Road', 'line2' => null, 'city' => 'Delhi', 'pincode' => null]),
            'state_code' => '07',
            'timezone' => 'Asia/Kolkata',
            'day_cutoff' => '04:00',
            'status' => 'active',
            'changed_at' => '2026-09-26 00:00:00',
        ]);

        foreach ($products as $product => $state) {
            DB::table('khana_entitlements')->insert([
                'tenant_id' => $tenant,
                'outlet_id' => $outlet,
                'product' => $product,
                'state' => $state,
                'plan' => $product,
                'limits' => '{}',
                'changed_at' => '2026-09-26 00:00:00',
            ]);
        }

        app(Entitlements::class)->forget();

        return ['tenant' => $tenant, 'outlet' => $outlet];
    }

    /**
     * Somebody at this business, signed in.
     *
     * @param  array{tenant: string, outlet: string}  $at
     */
    protected function signInAs(string $role, array $at, array $extraOutlets = []): User
    {
        $user = User::create([
            'ulid' => Ulid::generate(),
            'tenant_id' => $at['tenant'],
            'name' => ucfirst(str_replace('_', ' ', $role)),
            'email' => $role.'.'.strtolower(substr($at['outlet'], -4)).'@example.test',
            'role_key' => $role,
            'outlet_ids' => [$at['outlet'], ...$extraOutlets],
        ]);

        $this->actingAs($user);
        app(Context::class)->forget();

        return $user;
    }

    /** A stock item of this business. $costPerBigUnit is rupees per kg / litre / piece. */
    protected function stockItem(string $tenant, string $name, string $base = 'g', string|int $costPerBigUnit = 0, array $extra = []): StockItem
    {
        return StockItem::allTenants()->create(array_merge([
            'tenant_id' => $tenant,
            'name' => $name,
            'base_unit' => $base,
            'last_cost_rate' => Units::rateFromQuote($costPerBigUnit, $base),
        ], $extra));
    }
}
