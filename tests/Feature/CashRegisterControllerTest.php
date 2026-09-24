<?php

namespace Tests\Feature;

use App\Models\CashRegister;
use App\Models\Shop;
use App\Models\User;
use App\Services\CashRegisterService;
use App\Support\CurrentShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The Day Close screens over HTTP: opening, closing and approving a
 * register through the actual routes/permissions, and that the views
 * render at every stage of the lifecycle.
 */
class CashRegisterControllerTest extends TestCase
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
            'name' => 'Register Staff '.$counter,
            'email' => "registerstaff{$counter}@example.test",
            'password' => 'staff-password-1',
            'is_admin' => true,
        ]);

        $user->givePermissionTo($permissions);
        $user->shops()->attach($this->shop->id, ['is_default' => true]);
        $user->forceFill(['current_shop_id' => $this->shop->id])->save();

        return $user;
    }

    public function test_the_index_shows_an_open_form_when_no_register_exists_today(): void
    {
        $staff = $this->staff(['pos.registers.view', 'pos.registers.create']);

        $this->actingAs($staff)->get(route('admin.registers.index'))
            ->assertOk()
            ->assertSee('Open today\'s register', false);
    }

    public function test_a_staff_member_can_open_close_and_a_manager_can_approve(): void
    {
        $cashier = $this->staff(['pos.registers.view', 'pos.registers.create', 'pos.registers.edit']);
        $manager = $this->staff(['pos.registers.view', 'pos.registers.approve']);

        $open = $this->actingAs($cashier)->postJson(route('admin.registers.store'), [
            'opening_float' => 1000,
        ]);
        $open->assertOk()->assertJsonPath('success', true);

        $register = CashRegister::allShops()->sole();
        $this->assertSame(CashRegister::OPEN, $register->status);

        $this->actingAs($cashier)->get(route('admin.registers.show', $register))->assertOk();

        $close = $this->actingAs($cashier)->putJson(route('admin.registers.close', $register), [
            'counted_cash' => 1000,
        ]);
        $close->assertOk()->assertJsonPath('success', true);
        $this->assertSame(CashRegister::CLOSED, $register->fresh()->status);

        $approve = $this->actingAs($manager)->putJson(route('admin.registers.approve', $register), [
            'review_note' => 'Checked, balances.',
        ]);
        $approve->assertOk()->assertJsonPath('success', true);
        $this->assertSame(CashRegister::APPROVED, $register->fresh()->status);

        $this->actingAs($manager)->get(route('admin.registers.show', $register))->assertOk();
    }

    public function test_a_cashier_without_edit_permission_cannot_close_the_register(): void
    {
        $cashier = $this->staff(['pos.registers.view', 'pos.registers.create']);
        $register = (new CashRegisterService())->open($this->shop, 500);

        $this->actingAs($cashier)->putJson(route('admin.registers.close', $register), [
            'counted_cash' => 500,
        ])->assertForbidden();
    }

    public function test_staff_without_permission_cannot_open_the_screen(): void
    {
        $staff = $this->staff([]);

        $this->actingAs($staff)->get(route('admin.registers.index'))->assertForbidden();
    }
}
