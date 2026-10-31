<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\User;
use App\Support\CurrentShop;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Every admin screen that can be opened without choosing a record, opened as
 * a guest, as the Super Admin and as a restricted Cashier.
 *
 * It is a net under every module at once: a screen that throws, a screen a
 * guest can read, and a screen a till operator can read all show up here
 * without anybody having to write a test for that particular screen.
 */
class AdminRoutesSmokeTest extends TestCase
{
    use RefreshDatabase;

    /** Routes that sign somebody out or only make sense as an action. */
    private const SKIP = '/(logout|login\.|password\.set|stream|download|backup)/';

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

    /** @return array<int, array{name: string, uri: string}> */
    private function screens(): array
    {
        $out = [];

        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();

            if (! $name || ! str_starts_with($name, 'admin.') || ! in_array('GET', $route->methods(), true)) {
                continue;
            }

            if (str_contains($route->uri(), '{') || preg_match(self::SKIP, $name)) {
                continue;
            }

            $out[] = ['name' => $name, 'uri' => '/'.$route->uri()];
        }

        return $out;
    }

    private function cashier(): User
    {
        $shop = Shop::query()->orderBy('id')->firstOrFail();

        $user = User::create([
            'tenant_id' => $shop->tenant_id, 'name' => 'Till Operator', 'email' => 'till@example.test',
            'password' => 'till-password-1', 'is_admin' => true,
        ]);
        $user->assignRole('Cashier');
        $user->shops()->syncWithoutDetaching([$shop->id => ['is_default' => true]]);
        $user->forceFill(['current_shop_id' => $shop->id, 'all_shops_view' => false])->save();

        return $user;
    }

    public function test_the_list_of_screens_is_not_empty(): void
    {
        $this->assertGreaterThan(60, count($this->screens()));
    }

    public function test_a_guest_cannot_open_any_admin_screen(): void
    {
        $leaks = [];

        foreach ($this->screens() as $screen) {
            $response = $this->get($screen['uri']);

            if (! in_array($response->getStatusCode(), [301, 302, 401, 403, 404], true)) {
                $leaks[] = "{$screen['name']} -> {$response->getStatusCode()}";
            } elseif (in_array($response->getStatusCode(), [301, 302], true)
                && ! str_contains((string) $response->headers->get('Location'), 'login')) {
                $leaks[] = "{$screen['name']} redirects to ".$response->headers->get('Location');
            }
        }

        $leaks = array_values(array_filter($leaks, fn ($l) => ! str_starts_with($l, 'admin.login ')));

        $this->assertSame([], $leaks, "Screens a guest can reach:\n".implode("\n", $leaks));
    }

    public function test_the_super_admin_can_open_every_admin_screen_without_an_error(): void
    {
        $this->actingAs(User::query()->withoutGlobalScopes()->where('email', 'admin@erp.test')->firstOrFail());

        $broken = [];

        foreach ($this->screens() as $screen) {
            CurrentShop::forget();
            $response = $this->get($screen['uri']);

            if ($response->getStatusCode() >= 400) {
                $broken[] = "{$screen['name']} {$screen['uri']} -> {$response->getStatusCode()}";
            }
        }

        $this->assertSame([], $broken, "Screens that failed for the Super Admin:\n".implode("\n", $broken));
    }

    public function test_a_cashier_never_gets_a_server_error_and_is_kept_out_of_platform_screens(): void
    {
        $this->actingAs($this->cashier());

        $errors = [];

        foreach ($this->screens() as $screen) {
            CurrentShop::forget();
            $status = $this->get($screen['uri'])->getStatusCode();

            if ($status >= 500) {
                $errors[] = "{$screen['name']} -> {$status}";
            }
        }

        $this->assertSame([], $errors, "Server errors for a cashier:\n".implode("\n", $errors));

        // Platform and settings screens are the Super Admin's and the owner's.
        foreach (['admin.users.index', 'admin.roles.index', 'admin.tenants.index', 'admin.plans.index',
            'admin.subscriptions.index', 'admin.health.index', 'admin.activity.index', 'admin.api-tokens.index'] as $name) {
            $this->get(route($name))->assertForbidden();
        }
    }
}
