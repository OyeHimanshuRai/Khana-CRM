<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Shop;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentShop;
use App\Support\CurrentTenant;
use App\Support\Modules;
use App\Support\PlanAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Every admin screen that needs no record opens, for the two people it matters
 * to most: the platform's Super Admin and a restaurant's owner.
 *
 * A wide net rather than a sharp one. It cannot say a screen is right, only
 * that nothing on it blows up - which is the failure a view edited alongside
 * a controller tends to produce.
 */
class AdminScreenSmokeTest extends TestCase
{
    use RefreshDatabase;

    /** Downloads and redirects-away; they are not screens. */
    private const SKIP = ['/export', '/template', 'admin/logout', '/download'];

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');
        $this->seed();
        $this->resetState();
    }

    protected function tearDown(): void
    {
        $this->resetState();

        parent::tearDown();
    }

    private function resetState(): void
    {
        CurrentShop::forget();
        CurrentTenant::forget();
        PlanAccess::forget();
        Modules::forget();
    }

    /** @return array<int, string> */
    private function screens(): array
    {
        return collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => in_array('GET', $route->methods(), true)
                && str_starts_with($route->uri(), 'admin')
                && ! str_contains($route->uri(), '{')
                && ! str_contains(implode(',', $route->gatherMiddleware()), 'guest'))
            ->map(fn ($route) => '/'.$route->uri())
            ->reject(fn (string $uri) => collect(self::SKIP)->contains(fn ($skip) => str_contains($uri, $skip)))
            ->unique()
            ->values()
            ->all();
    }

    /** @return array<string, string> uri => what went wrong */
    private function crawl(User $user): array
    {
        $broken = [];

        foreach ($this->screens() as $uri) {
            $this->resetState();
            $response = $this->actingAs($user)->get($uri);

            if ($response->getStatusCode() >= 500) {
                $broken[$uri] = (string) ($response->exception?->getMessage() ?? $response->getStatusCode());
            }
        }

        return $broken;
    }

    public function test_every_screen_opens_for_the_super_admin(): void
    {
        $this->assertSame([], $this->crawl(User::query()->where('email', 'admin@erp.test')->firstOrFail()));
    }

    public function test_every_screen_opens_for_a_restaurant_owner(): void
    {
        $shop = Shop::query()->withoutGlobalScopes()->orderBy('id')->firstOrFail();

        $owner = User::create([
            'tenant_id' => $shop->tenant_id, 'name' => 'Owner', 'email' => 'owner@example.test',
            'password' => 'Owner-1234', 'is_admin' => true, 'is_active' => true,
            'current_shop_id' => $shop->id,
        ]);
        $owner->shops()->attach($shop->id, ['is_default' => true]);
        $owner->assignRole('Tenant Owner');

        $this->assertSame([], $this->crawl($owner->fresh()));
    }

    public function test_the_plan_and_billing_screens_with_a_record_open(): void
    {
        $admin = User::query()->where('email', 'admin@erp.test')->firstOrFail();
        $shop = Shop::query()->withoutGlobalScopes()->orderBy('id')->firstOrFail();
        $tenant = Tenant::query()->findOrFail($shop->tenant_id);
        $plan = Plan::query()->where('code', 'starter')->firstOrFail();

        Subscription::create([
            'tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'billing_period' => 'monthly',
            'price' => 799, 'currency' => 'INR', 'starts_at' => now(), 'ends_at' => now()->addMonth(), 'grace_days' => 3,
        ]);

        foreach ([
            route('admin.plans.show', $plan),
            route('admin.plans.edit', $plan),
            route('admin.subscriptions.show', $tenant),
            route('admin.subscriptions.assign', $tenant),
            route('admin.subscriptions.payment', $tenant),
            route('admin.tenants.show', $tenant),
            route('admin.tenants.edit', $tenant),
            route('admin.shops.edit', $shop),
            route('admin.shops.show', $shop),
        ] as $url) {
            $this->resetState();
            $this->actingAs($admin)->get($url)->assertOk();
        }
    }
}
