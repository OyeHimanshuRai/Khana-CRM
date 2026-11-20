<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Support\CurrentShop;
use App\Support\CurrentTenant;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * A new member of staff is put on a branch when the owner creates them. Without
 * one they could sign in and see nothing: the till, the kitchen and the bills
 * all belong to a branch.
 */
class UserBranchesTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');
        $this->seed();

        $this->shop = Shop::query()->orderBy('id')->firstOrFail();
        $this->owner = $this->person('Tenant Owner', 'owner@branches.test');
    }

    private function person(string $role, string $email): User
    {
        $user = User::create([
            'tenant_id' => $this->shop->tenant_id, 'name' => $role.' Person', 'email' => $email,
            'password' => 'Secret-1234', 'is_admin' => true, 'is_active' => true,
            'current_shop_id' => $this->shop->id,
        ]);
        $user->shops()->attach($this->shop->id, ['is_default' => true]);
        $user->assignRole($role);

        return $user->fresh();
    }

    private function secondShop(): Shop
    {
        $shop = Shop::create([
            'tenant_id' => $this->shop->tenant_id, 'name' => 'Second Branch', 'code' => 'SB2', 'slug' => 'second-branch',
            'invoice_prefix' => 'INV', 'pos_prefix' => 'POS',
            'currency' => 'INR', 'timezone' => 'Asia/Kolkata', 'is_active' => true,
        ]);
        $this->owner->shops()->attach($shop->id);

        return $shop;
    }

    private function newStaff(array $extra = []): array
    {
        return array_merge([
            'name' => 'Rita Cashier', 'email' => 'rita@branches.test',
            'password' => 'Staff-Pass#2026', 'password_confirmation' => 'Staff-Pass#2026',
            'is_admin' => 1, 'send_welcome' => 0, 'roles' => ['Cashier'],
        ], $extra);
    }

    public function test_a_restaurant_with_one_branch_puts_new_staff_on_it(): void
    {
        $this->actingAs($this->owner)->post('/users', $this->newStaff())->assertRedirect();

        $staff = User::where('email', 'rita@branches.test')->firstOrFail();
        $this->assertSame([$this->shop->id], $staff->shops()->pluck('shops.id')->all());
        $this->assertTrue((bool) $staff->shops()->first()->pivot->is_default);
    }

    public function test_the_form_offers_the_branches_and_ticks_the_only_one(): void
    {
        $this->actingAs($this->owner)->get('/users/create')
            ->assertOk()
            ->assertSee('Branches')
            ->assertSee($this->shop->name)
            ->assertSee('name="shops[]" value="'.$this->shop->id.'"', false);
    }

    public function test_with_several_branches_the_ticked_ones_are_used(): void
    {
        $second = $this->secondShop();

        $this->actingAs($this->owner)->post('/users', $this->newStaff(['shops' => [$second->id]]))->assertRedirect();

        $staff = User::where('email', 'rita@branches.test')->firstOrFail();
        $this->assertSame([$second->id], $staff->shops()->pluck('shops.id')->all());
    }

    public function test_a_branch_of_another_company_cannot_be_ticked(): void
    {
        $rival = Tenant::create(['name' => 'Rival Pvt Ltd', 'code' => 'RIVAL9', 'slug' => 'rival-9', 'is_active' => true]);
        $other = Shop::create(['tenant_id' => $rival->id, 'name' => 'Rival', 'code' => 'RVL', 'slug' => 'rival', 'is_active' => true]);

        $this->actingAs($this->owner)->post('/users', $this->newStaff(['shops' => [$other->id]]))
            ->assertSessionHasErrors('shops.0');

        $this->assertNull(User::where('email', 'rita@branches.test')->first());
    }

    public function test_editing_changes_the_branches_and_a_form_without_them_changes_nothing(): void
    {
        $second = $this->secondShop();
        $this->actingAs($this->owner)->post('/users', $this->newStaff(['shops' => [$this->shop->id]]));
        $staff = User::where('email', 'rita@branches.test')->firstOrFail();

        $fields = ['name' => $staff->name, 'email' => $staff->email, 'is_admin' => 1, 'roles' => ['Cashier']];

        // Not on the form: left as they were.
        $this->actingAs($this->owner)->put('/users/'.$staff->id, $fields)->assertRedirect();
        $this->assertSame([$this->shop->id], $staff->shops()->pluck('shops.id')->all());

        // On the form: replaced by what is ticked.
        $this->actingAs($this->owner)->put('/users/'.$staff->id, $fields + ['shops_submitted' => 1, 'shops' => [$second->id]])->assertRedirect();
        $this->assertSame([$second->id], $staff->fresh()->shops()->pluck('shops.id')->all());
    }

    public function test_nobody_can_take_themselves_off_every_branch(): void
    {
        $this->actingAs($this->owner)->put('/users/'.$this->owner->id, [
            'name' => $this->owner->name, 'email' => $this->owner->email, 'is_admin' => 1,
            'roles' => ['Tenant Owner'], 'shops_submitted' => 1,
        ])->assertSessionHasErrors('shops');

        $this->assertSame([$this->shop->id], $this->owner->shops()->pluck('shops.id')->all());
    }

    public function test_staff_without_a_branch_are_told_so(): void
    {
        $orphan = User::create([
            'tenant_id' => $this->shop->tenant_id, 'name' => 'No Branch', 'email' => 'nobranch@branches.test',
            'password' => 'Secret-1234', 'is_admin' => true, 'is_active' => true,
        ]);
        $orphan->assignRole('Cashier');

        $this->actingAs($orphan->fresh())->get('/dashboard')
            ->assertOk()
            ->assertSee('You have not been given a branch yet');

        // (What was worked out for one person is not carried over to the next request.)
        CurrentShop::forget();
        CurrentTenant::forget();

        $this->actingAs($this->owner)->get('/dashboard')
            ->assertOk()
            ->assertDontSee('You have not been given a branch yet');
    }
}
