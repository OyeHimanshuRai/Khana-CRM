<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Shop;
use App\Models\User;
use App\Models\UserSession;
use App\Support\CurrentShop;
use App\Support\CurrentTenant;
use App\Support\Modules;
use App\Support\PlanAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * "Login as owner": the Super Admin steps into a company as its owner to see
 * what the owner sees, and can always step back out.
 */
class ImpersonationTest extends TestCase
{
    use RefreshDatabase;

    private User $super;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');
        $this->seed();
        $this->reset();

        $this->super = User::query()->where('email', 'admin@erp.test')->firstOrFail();
        $this->shop = Shop::query()->withoutGlobalScopes()->orderBy('id')->firstOrFail();
    }

    private function reset(): void
    {
        CurrentShop::forget();
        CurrentTenant::forget();
        PlanAccess::forget();
        Modules::forget();
    }

    private function staffOf(string $role): User
    {
        $user = User::create([
            'tenant_id' => $this->shop->tenant_id, 'name' => $role.' Person', 'email' => uniqid('u').'@example.test',
            'password' => 'Secret-1234', 'is_admin' => true, 'is_active' => true,
            'current_shop_id' => $this->shop->id,
        ]);
        $user->shops()->attach($this->shop->id, ['is_default' => true]);
        $user->assignRole($role);

        return $user->fresh();
    }

    public function test_super_admin_steps_into_a_company_as_its_owner_and_back_out(): void
    {
        $owner = $this->staffOf('Tenant Owner');

        $this->actingAs($this->super)
            ->post(route('admin.tenants.impersonate', $this->shop->tenant_id))
            ->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($owner);

        $this->reset();
        $this->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Viewing as '.$owner->name)
            ->assertSee('Exit to Platform')
            ->assertSee('Floor Plan');

        $this->assertSame(0, UserSession::query()->where('user_id', $owner->id)->count());

        $this->post(route('admin.impersonate.stop'))->assertRedirect(route('admin.platform.dashboard'));

        $this->assertAuthenticatedAs($this->super);
        $this->assertNull(session(\App\Http\Controllers\Admin\ImpersonationController::SESSION_KEY));

        $this->assertDatabaseHas('activity_logs', ['user_id' => $this->super->id, 'event' => 'auth.impersonate.start']);
        $this->assertDatabaseHas('activity_logs', ['user_id' => $this->super->id, 'event' => 'auth.impersonate.stop']);
    }

    public function test_a_company_with_no_owner_cannot_be_entered(): void
    {
        $this->actingAs($this->super)
            ->post(route('admin.tenants.impersonate', $this->shop->tenant_id))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertAuthenticatedAs($this->super);
        $this->assertSame(0, ActivityLog::query()->where('event', 'auth.impersonate.start')->count());
    }

    public function test_nobody_in_a_company_can_start_it(): void
    {
        $this->staffOf('Tenant Owner');
        $manager = $this->staffOf('Manager');

        $this->actingAs($this->staffOf('Tenant Owner'))
            ->post(route('admin.tenants.impersonate', $this->shop->tenant_id))
            ->assertForbidden();

        $this->reset();
        $this->actingAs($manager)
            ->post(route('admin.tenants.impersonate', $this->shop->tenant_id))
            ->assertForbidden();
    }

    public function test_the_way_back_is_closed_to_anyone_who_never_stepped_in(): void
    {
        $owner = $this->staffOf('Tenant Owner');

        $this->actingAs($owner)->post(route('admin.impersonate.stop'))->assertRedirect(route('admin.login'));

        $this->assertGuest();
    }

    public function test_the_companies_list_offers_the_button_to_the_super_admin_only(): void
    {
        $this->staffOf('Tenant Owner');

        $this->actingAs($this->super)->get(route('admin.tenants.index'))
            ->assertOk()
            ->assertSee('Login as owner');

        $this->reset();
        $this->actingAs($this->staffOf('Tenant Owner'))->get(route('admin.tenants.index'))
            ->assertOk()
            ->assertDontSee('Login as owner');
    }
}
