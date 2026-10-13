<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Signup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Platform admin's business list: filters, CSV, and extending a plan.
final class PlatformBusinessesTest extends TestCase
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

    private function business(string $name, string $email): Tenant
    {
        $plan = Plan::query()->firstOrCreate(['code' => 'suite'], [
            'name' => 'Suite', 'products' => ['crm', 'erp'], 'monthly_price_paise' => 149900,
            'yearly_price_paise' => 1499000, 'trial_days' => 14, 'is_active' => true, 'sort_order' => 0,
        ]);

        return app(Signup::class)->register(
            business: ['name' => $name],
            outlet: ['name' => 'Main', 'code' => strtoupper(substr($name, 0, 4)), 'city' => 'Delhi', 'state_code' => '07'],
            owner: ['name' => $name.' Owner', 'email' => $email, 'password' => 'secret12'],
            plan: $plan,
        )->tenant;
    }

    private function expire(Tenant $tenant): void
    {
        Subscription::query()->where('tenant_id', $tenant->id)->update([
            'trial_ends_at' => now()->subDays(30),
            'ends_at' => now()->subDays(30),
        ]);
    }

    public function test_the_list_shows_owner_plan_and_trial(): void
    {
        $this->business('Spice Route', 'a@spice.test');

        $this->actingAs($this->admin())
            ->get(route('admin.tenants.index'))
            ->assertOk()
            ->assertSee('Spice Route Owner')
            ->assertSee('a@spice.test')
            ->assertSee('Suite')
            ->assertSee('Trial')
            ->assertSee(now()->addDays(14)->format('d M Y'));
    }

    public function test_businesses_filter_by_plan_state_and_status(): void
    {
        $this->business('Spice Route', 'a@spice.test');
        $this->expire($this->business('Green Bowl', 'b@green.test'));
        Tenant::query()->where('name', 'Green Bowl')->update(['status' => Tenant::SUSPENDED]);
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.tenants.index', ['state' => 'trial']))
            ->assertSee('Spice Route')->assertDontSee('Green Bowl');

        $this->actingAs($admin)->get(route('admin.tenants.index', ['state' => 'expired']))
            ->assertSee('Green Bowl')->assertDontSee('Spice Route');

        $this->actingAs($admin)->get(route('admin.tenants.index', ['status' => 'suspended']))
            ->assertSee('Green Bowl')->assertDontSee('Spice Route');

        $this->actingAs($admin)->get(route('admin.tenants.index', ['q' => 'b@green']))
            ->assertSee('Green Bowl')->assertDontSee('Spice Route');
    }

    public function test_the_list_downloads_as_csv(): void
    {
        $this->business('Spice Route', 'a@spice.test');

        $csv = $this->actingAs($this->admin())
            ->get(route('admin.tenants.index', ['export' => 'csv']))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Business,Owner,Email', $csv);
        $this->assertStringContainsString('"Spice Route","Spice Route Owner",a@spice.test', $csv);
    }

    public function test_extending_a_trial_moves_both_dates(): void
    {
        $tenant = $this->business('Spice Route', 'a@spice.test');
        $subscription = Subscription::query()->where('tenant_id', $tenant->id)->firstOrFail();
        $before = $subscription->ends_at->copy();

        $this->actingAs($this->admin())
            ->post(route('admin.tenants.extend', [$tenant, $subscription]), ['days' => 7])
            ->assertRedirect();

        $subscription->refresh();
        $this->assertTrue($subscription->ends_at->equalTo($before->addDays(7)));
        $this->assertTrue($subscription->trial_ends_at->equalTo($subscription->ends_at));
        $this->assertSame(Subscription::TRIAL, $subscription->state());
    }

    public function test_extending_an_expired_plan_restarts_from_today(): void
    {
        $tenant = $this->business('Green Bowl', 'b@green.test');
        $this->expire($tenant);
        $subscription = Subscription::query()->where('tenant_id', $tenant->id)->firstOrFail();

        $this->actingAs($this->admin())
            ->post(route('admin.tenants.extend', [$tenant, $subscription]), ['days' => 10]);

        $subscription->refresh();
        $this->assertTrue($subscription->ends_at->between(now()->addDays(10)->subMinute(), now()->addDays(10)->addMinute()));
        $this->assertSame(Subscription::ACTIVE, $subscription->state());
    }

    public function test_a_subscription_of_another_business_cannot_be_extended_through_this_one(): void
    {
        $spice = $this->business('Spice Route', 'a@spice.test');
        $green = $this->business('Green Bowl', 'b@green.test');
        $theirs = Subscription::query()->where('tenant_id', $green->id)->firstOrFail();

        $this->actingAs($this->admin())
            ->post(route('admin.tenants.extend', [$spice, $theirs]), ['days' => 7])
            ->assertNotFound();
    }

    public function test_the_business_page_shows_dates_payments_and_logins(): void
    {
        $tenant = $this->business('Spice Route', 'a@spice.test');

        $this->actingAs($this->admin())
            ->get(route('admin.tenants.show', $tenant))
            ->assertOk()
            ->assertSee('Trial ends')
            ->assertSee(now()->addDays(14)->format('d M Y'))
            ->assertSee('Extend')
            ->assertSee('No payments yet.')
            ->assertSee('Last login');
    }

    public function test_an_owner_cannot_see_the_list(): void
    {
        $tenant = $this->business('Spice Route', 'a@spice.test');
        $owner = User::query()->where('tenant_id', $tenant->id)->firstOrFail();

        $this->actingAs($owner)->get(route('admin.tenants.index'))->assertStatus(403);
    }
}
