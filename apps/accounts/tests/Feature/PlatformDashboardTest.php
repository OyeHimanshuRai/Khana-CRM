<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Signup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PlatformDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = new User;
        $admin->forceFill([
            'name' => 'Platform Admin',
            'email' => 'admin@khana.test',
            'password' => 'password',
            'status' => User::ACTIVE,
            'is_platform_admin' => true,
            'email_verified_at' => now(),
        ])->save();

        return $admin;
    }

    private function business(string $name, string $email): User
    {
        $plan = Plan::query()->firstOrCreate(['code' => 'suite'], [
            'name' => 'Suite', 'products' => ['crm', 'erp'], 'monthly_price_paise' => 149900,
            'yearly_price_paise' => 1499000, 'trial_days' => 14, 'is_active' => true, 'sort_order' => 0,
        ]);

        return app(Signup::class)->register(
            business: ['name' => $name],
            outlet: ['name' => 'Main', 'code' => 'M', 'city' => 'Delhi', 'state_code' => '07'],
            owner: ['name' => 'Owner', 'email' => $email, 'password' => 'secret12'],
            plan: $plan,
        );
    }

    public function test_the_platform_owner_sees_how_many_businesses_are_registered_and_active(): void
    {
        $this->business('Spice Route', 'a@spice.test');
        $this->business('Green Bowl', 'b@green.test');
        Tenant::query()->where('name', 'Green Bowl')->update(['status' => Tenant::SUSPENDED]);

        $this->actingAs($this->admin())
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Platform overview')
            ->assertSee('Businesses registered')
            ->assertSee('Spice Route')
            ->assertSee('1 suspended');
    }

    public function test_an_owner_cannot_open_it(): void
    {
        $owner = $this->business('Spice Route', 'a@spice.test');

        $this->actingAs($owner)->get(route('admin.dashboard'))->assertStatus(403);
    }

    public function test_the_platform_owner_lands_on_it_after_signing_in(): void
    {
        $this->actingAs($this->admin())->get('/')->assertRedirect(route('admin.dashboard'));
    }
}
