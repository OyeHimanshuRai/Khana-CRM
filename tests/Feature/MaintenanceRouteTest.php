<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MaintenanceRouteTest extends TestCase
{
    use RefreshDatabase;

    private function user(bool $superAdmin): User
    {
        $user = User::create([
            'name' => 'Maint Tester',
            'email' => 'maint'.uniqid().'@example.test',
            'password' => 'maint-password-1',
            'is_admin' => true,
        ]);

        if ($superAdmin) {
            Role::findOrCreate(User::SUPER_ADMIN, 'web');
            $user->assignRole(User::SUPER_ADMIN);
        }

        return $user->fresh();
    }

    public function test_guests_are_sent_to_login_and_nothing_runs(): void
    {
        $this->get('/clear-all')->assertRedirect();
    }

    public function test_regular_admins_are_forbidden(): void
    {
        $this->actingAs($this->user(false))->get('/clear-all')->assertForbidden();
    }

    public function test_super_admin_can_clear_caches(): void
    {
        // The seven commands are recorded, not run: really running them
        // empties the developer's compiled views and config cache and
        // re-links storage on every test run, and races any test running
        // alongside.
        Artisan::shouldReceive('call')->times(7)->andReturn(0);

        $this->actingAs($this->user(true))
            ->get('/clear-all')
            ->assertOk()
            ->assertJson(['status' => true]);
    }
}
