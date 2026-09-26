<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\Supplier;
use App\Models\User;
use App\Support\CurrentShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The supplier master over HTTP - create, edit, and that a supplier's
 * shop and opening balance are fixed once entered (the same rule
 * CustomerController enforces on its own two ledger-reconciled fields).
 */
class SupplierControllerTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');

        $this->seed();
        CurrentShop::forget();

        $this->shop = Shop::firstOrFail();
    }

    protected function tearDown(): void
    {
        CurrentShop::forget();

        parent::tearDown();
    }

    private function staff(array $permissions): User
    {
        static $counter = 0;
        $counter++;

        $user = User::create([
            'name' => 'Supplier Staff '.$counter,
            'email' => "supplierstaff{$counter}@example.test",
            'password' => 'staff-password-1',
            'is_admin' => true,
        ]);

        $user->givePermissionTo($permissions);
        $user->shops()->attach($this->shop->id, ['is_default' => true]);
        $user->forceFill(['current_shop_id' => $this->shop->id])->save();

        return $user;
    }

    public function test_the_list_and_create_screens_render(): void
    {
        $staff = $this->staff(['purchasing.suppliers.view', 'purchasing.suppliers.create']);

        $this->actingAs($staff)->get(route('admin.suppliers.index'))->assertOk();
        $this->actingAs($staff)->get(route('admin.suppliers.create'))->assertOk();
    }

    public function test_a_staff_member_can_create_a_supplier(): void
    {
        $staff = $this->staff(['purchasing.suppliers.create']);

        $response = $this->actingAs($staff)->postJson(route('admin.suppliers.store'), [
            'shop_id' => $this->shop->id,
            'name' => 'Test Agro Distributors',
            'mobile' => '9812345670',
            'opening_balance' => 5000,
        ]);

        $response->assertOk()->assertJsonPath('success', true);

        $supplier = Supplier::allShops()->sole();
        $this->assertSame('Test Agro Distributors', $supplier->name);
        $this->assertNotEmpty($supplier->code);
        $this->assertEqualsWithDelta(5000.0, (float) $supplier->balance, 0.01);
    }

    public function test_creating_without_a_name_is_refused(): void
    {
        $staff = $this->staff(['purchasing.suppliers.create']);

        $this->actingAs($staff)->postJson(route('admin.suppliers.store'), [
            'shop_id' => $this->shop->id,
        ])->assertUnprocessable();

        $this->assertSame(0, Supplier::allShops()->count());
    }

    public function test_a_staff_member_can_update_a_supplier_but_not_its_opening_balance(): void
    {
        $staff = $this->staff(['purchasing.suppliers.create', 'purchasing.suppliers.edit']);

        $create = $this->actingAs($staff)->postJson(route('admin.suppliers.store'), [
            'shop_id' => $this->shop->id,
            'name' => 'Original Name',
            'opening_balance' => 1000,
        ]);

        $supplier = Supplier::allShops()->sole();

        $response = $this->actingAs($staff)->putJson(route('admin.suppliers.update', $supplier), [
            'name' => 'Renamed Supplier',
            'opening_balance' => 999999,
        ]);

        $response->assertOk()->assertJsonPath('success', true);

        $fresh = $supplier->fresh();
        $this->assertSame('Renamed Supplier', $fresh->name);
        // Balance is ledger-owned; the opening_balance in this request must
        // not have moved it.
        $this->assertEqualsWithDelta(1000.0, (float) $fresh->balance, 0.01);
    }

    public function test_a_duplicate_code_in_the_same_shop_is_refused(): void
    {
        $staff = $this->staff(['purchasing.suppliers.create']);

        $this->actingAs($staff)->postJson(route('admin.suppliers.store'), [
            'shop_id' => $this->shop->id, 'name' => 'First', 'code' => 'DUPCODE',
        ]);

        $this->actingAs($staff)->postJson(route('admin.suppliers.store'), [
            'shop_id' => $this->shop->id, 'name' => 'Second', 'code' => 'DUPCODE',
        ])->assertUnprocessable();

        $this->assertSame(1, Supplier::allShops()->count());
    }

    public function test_staff_without_permission_cannot_create_a_supplier(): void
    {
        $staff = $this->staff([]);

        $this->actingAs($staff)->postJson(route('admin.suppliers.store'), [
            'shop_id' => $this->shop->id, 'name' => 'Blocked Supplier',
        ])->assertForbidden();

        $this->assertSame(0, Supplier::allShops()->count());
    }
}
