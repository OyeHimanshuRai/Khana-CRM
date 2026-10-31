<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Shop;
use App\Models\User;
use App\Support\CurrentShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The customer master over HTTP - create, edit, and that a customer still
 * owing money cannot be deleted (CustomerController::destroy()'s guard).
 */
class CustomerControllerTest extends TestCase
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
            'name' => 'Customer Staff '.$counter,
            'email' => "customerstaff{$counter}@example.test",
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
        $staff = $this->staff(['crm.customers.view', 'crm.customers.create']);

        $this->actingAs($staff)->get(route('admin.customers.index'))->assertOk();
        $this->actingAs($staff)->get(route('admin.customers.create'))->assertOk();
    }

    public function test_a_staff_member_can_create_a_customer(): void
    {
        $staff = $this->staff(['crm.customers.create']);

        $response = $this->actingAs($staff)->postJson(route('admin.customers.store'), [
            'shop_id' => $this->shop->id,
            'name' => 'Test Farmer',
            'mobile' => '9876500001',
            'type' => 'farmer',
        ]);

        $response->assertOk()->assertJsonPath('success', true);

        $customer = Customer::allShops()->sole();
        $this->assertSame('Test Farmer', $customer->name);
        $this->assertNotEmpty($customer->code);
        $this->assertSame('farmer', $customer->type);
        $this->assertEqualsWithDelta(0.0, (float) $customer->balance, 0.01);
    }

    public function test_creating_without_a_name_is_refused(): void
    {
        $staff = $this->staff(['crm.customers.create']);

        $this->actingAs($staff)->postJson(route('admin.customers.store'), [
            'shop_id' => $this->shop->id,
            'type' => 'retail',
        ])->assertUnprocessable();

        $this->assertSame(0, Customer::allShops()->count());
    }

    public function test_a_duplicate_mobile_in_the_same_shop_is_refused(): void
    {
        $staff = $this->staff(['crm.customers.create']);

        $this->actingAs($staff)->postJson(route('admin.customers.store'), [
            'shop_id' => $this->shop->id, 'name' => 'First', 'mobile' => '9999900001', 'type' => 'retail',
        ]);

        $this->actingAs($staff)->postJson(route('admin.customers.store'), [
            'shop_id' => $this->shop->id, 'name' => 'Second', 'mobile' => '9999900001', 'type' => 'retail',
        ])->assertUnprocessable();

        $this->assertSame(1, Customer::allShops()->count());
    }

    public function test_a_staff_member_can_update_a_customer(): void
    {
        $staff = $this->staff(['crm.customers.create', 'crm.customers.edit']);

        $this->actingAs($staff)->postJson(route('admin.customers.store'), [
            'shop_id' => $this->shop->id, 'name' => 'Original', 'type' => 'retail',
        ]);

        $customer = Customer::allShops()->sole();

        $response = $this->actingAs($staff)->putJson(route('admin.customers.update', $customer), [
            'name' => 'Renamed Customer',
            'type' => 'wholesale',
            'opening_balance' => 999999,
        ]);

        $response->assertOk()->assertJsonPath('success', true);

        $fresh = $customer->fresh();
        $this->assertSame('Renamed Customer', $fresh->name);
        $this->assertSame('wholesale', $fresh->type);
        // opening_balance is fixed after creation - the ledger owns balance.
        $this->assertEqualsWithDelta(0.0, (float) $fresh->balance, 0.01);
    }

    public function test_a_customer_who_owes_money_cannot_be_deleted(): void
    {
        $staff = $this->staff(['crm.customers.delete']);

        $customer = Customer::create([
            'shop_id' => $this->shop->id,
            'code' => Customer::nextCode($this->shop->id),
            'name' => 'Indebted Customer',
            'type' => 'retail',
            'is_active' => true,
        ]);
        $customer->forceFill(['balance' => 500])->save();

        $response = $this->actingAs($staff)->deleteJson(route('admin.customers.destroy', $customer));

        $response->assertStatus(422)->assertJsonPath('success', false);
        $this->assertNotNull($customer->fresh());
    }

    public function test_a_customer_with_no_dues_can_be_deleted(): void
    {
        $staff = $this->staff(['crm.customers.delete']);

        $customer = Customer::create([
            'shop_id' => $this->shop->id,
            'code' => Customer::nextCode($this->shop->id),
            'name' => 'Clear Customer',
            'type' => 'retail',
            'is_active' => true,
        ]);

        $response = $this->actingAs($staff)->deleteJson(route('admin.customers.destroy', $customer));

        $response->assertOk()->assertJsonPath('success', true);
        $this->assertSoftDeleted($customer);
    }

    public function test_staff_without_permission_cannot_create_a_customer(): void
    {
        $staff = $this->staff([]);

        $this->actingAs($staff)->postJson(route('admin.customers.store'), [
            'shop_id' => $this->shop->id, 'name' => 'Blocked', 'type' => 'retail',
        ])->assertForbidden();

        $this->assertSame(0, Customer::allShops()->count());
    }
}
