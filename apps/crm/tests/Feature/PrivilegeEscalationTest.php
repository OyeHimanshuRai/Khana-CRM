<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentShop;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Nobody can hand out more than they hold.
 *
 * A Tenant Owner manages their own staff, so the user screens are theirs. But
 * roles are defined once for the whole platform, and two of them - Super
 * Admin and Admin - reach every business. So does any `.manage` right, such
 * as support.tickets.manage, which opens every restaurant's support threads.
 *
 * RolePermissionSeeder keeps those out of the Tenant Owner role. These tests
 * hold the other door: an owner must not be able to give them to anybody -
 * themselves included - through the user screens either.
 */
class PrivilegeEscalationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');

        $this->seed();

        CurrentShop::forget();
        CurrentTenant::forget();
    }

    protected function tearDown(): void
    {
        CurrentShop::forget();
        CurrentTenant::forget();

        parent::tearDown();
    }

    private function tenant(): Tenant
    {
        return Tenant::query()->orderBy('id')->firstOrFail();
    }

    private function staff(string $email, string $role): User
    {
        $tenant = $this->tenant();
        $shop = Shop::query()->where('tenant_id', $tenant->id)->orderBy('id')->firstOrFail();

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => ucfirst(strtok($email, '@')),
            'email' => $email,
            'password' => 'a-strong-password-1',
            'is_admin' => true,
        ]);

        $user->assignRole($role);
        $user->shops()->syncWithoutDetaching([$shop->id => ['is_default' => true]]);
        $user->forceFill(['current_shop_id' => $shop->id, 'all_shops_view' => false])->save();

        return $user->fresh();
    }

    private function owner(): User
    {
        return User::query()->where('email', 'owner@example.test')->first()
            ?? $this->staff('owner@example.test', 'Tenant Owner');
    }

    public function test_an_owner_cannot_create_a_super_admin(): void
    {
        $this->actingAs($this->owner())
            ->postJson('/admin/users', [
                'name' => 'Sleeper',
                'email' => 'sleeper@example.test',
                'password' => 'a-strong-password-1',
                'password_confirmation' => 'a-strong-password-1',
                'roles' => [User::SUPER_ADMIN],
            ])
            ->assertStatus(422);

        $this->assertFalse(User::query()->where('email', 'sleeper@example.test')->exists());
    }

    public function test_an_owner_cannot_promote_a_colleague_to_a_platform_role(): void
    {
        $colleague = $this->staff('colleague@example.test', 'Cashier');

        foreach ([User::SUPER_ADMIN, 'Admin'] as $role) {
            $this->actingAs($this->owner())
                ->putJson('/admin/users/'.$colleague->id, [
                    'name' => $colleague->name,
                    'email' => $colleague->email,
                    'roles' => [$role],
                ])
                ->assertStatus(422);

            $this->assertFalse($colleague->fresh()->hasRole($role), "An owner made a colleague {$role}.");
        }
    }

    public function test_an_owner_cannot_promote_themselves(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)
            ->putJson('/admin/users/'.$owner->id, [
                'name' => $owner->name,
                'email' => $owner->email,
                'roles' => ['Tenant Owner', User::SUPER_ADMIN],
            ])
            ->assertStatus(422);

        $this->assertFalse($owner->fresh()->hasRole(User::SUPER_ADMIN));
    }

    public function test_an_owner_cannot_grant_a_platform_right_directly(): void
    {
        $colleague = $this->staff('colleague@example.test', 'Cashier');

        $this->actingAs($this->owner())
            ->postJson('/admin/users/'.$colleague->id.'/permissions', [
                'permissions' => ['support.tickets.manage', 'pos.terminal.view'],
            ])
            ->assertStatus(422);

        $this->assertFalse($colleague->fresh()->hasPermissionTo('support.tickets.manage'));
    }

    public function test_an_owner_cannot_grant_a_right_they_do_not_hold(): void
    {
        $colleague = $this->staff('colleague@example.test', 'Cashier');

        // Roles are platform-wide; editing one edits every business's.
        $this->actingAs($this->owner())
            ->postJson('/admin/users/'.$colleague->id.'/permissions', [
                'permissions' => ['settings.roles.edit'],
            ])
            ->assertStatus(422);

        $this->assertFalse($colleague->fresh()->hasPermissionTo('settings.roles.edit'));
    }

    public function test_an_owner_still_runs_their_own_team(): void
    {
        $colleague = $this->staff('colleague@example.test', 'Cashier');

        $this->actingAs($this->owner())
            ->putJson('/admin/users/'.$colleague->id, [
                'name' => $colleague->name,
                'email' => $colleague->email,
                'roles' => ['Manager'],
            ])
            ->assertOk();

        $this->assertTrue($colleague->fresh()->hasRole('Manager'));

        $this->actingAs($this->owner())
            ->postJson('/admin/users/'.$colleague->id.'/permissions', [
                'permissions' => ['sales.invoices.discount'],
            ])
            ->assertOk();

        $this->assertTrue($colleague->fresh()->hasPermissionTo('sales.invoices.discount'));
    }

    public function test_a_super_admin_may_hand_out_anything(): void
    {
        $admin = User::query()->role(User::SUPER_ADMIN)->firstOrFail();
        $colleague = $this->staff('colleague@example.test', 'Cashier');

        $this->actingAs($admin)
            ->putJson('/admin/users/'.$colleague->id, [
                'name' => $colleague->name,
                'email' => $colleague->email,
                'roles' => ['Admin'],
            ])
            ->assertOk();

        $this->assertTrue($colleague->fresh()->hasRole('Admin'));
    }
}
