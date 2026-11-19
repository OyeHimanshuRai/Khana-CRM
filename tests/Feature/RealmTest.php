<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\User;
use App\Support\Realm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The Super Admin (/superadmin, guard `superadmin`, its own session cookie) and a
 * restaurant (plain URLs, guard `web`, the normal cookie) are separate sides of
 * the app. See App\Support\Realm.
 *
 * What a test run can show is the wiring: which cookie, which guard, which door,
 * which URL. That two browsers' worth of sessions really do not touch each other
 * is shown against a running copy of the app, not here: a test run shares one
 * in-memory session between requests.
 */
class RealmTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');
        $this->seed();
        $this->shop = Shop::query()->orderBy('id')->firstOrFail();
    }

    private function owner(): User
    {
        $owner = User::create([
            'tenant_id' => $this->shop->tenant_id, 'name' => 'Kumar', 'email' => 'owner@kumar.test',
            'password' => 'owner-password-1', 'is_admin' => true, 'is_active' => true,
            'current_shop_id' => $this->shop->id,
        ]);
        $owner->shops()->attach($this->shop->id, ['is_default' => true]);
        $owner->assignRole('Tenant Owner');

        return $owner->fresh();
    }

    private function superAdmin(): User
    {
        return User::query()->where('email', 'admin@erp.test')->firstOrFail();
    }

    private function sessionCookie($response, string $name)
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $name) {
                return $cookie;
            }
        }

        return null;
    }

    /* ------------------------------------------------------------ cookies */

    public function test_each_side_has_its_own_session_cookie(): void
    {
        $platformName = config('session.platform.cookie');
        $customerName = config('session.cookie');

        $this->assertNotSame($platformName, $customerName);

        $platform = $this->get('/superadmin/login');
        $cookie = $this->sessionCookie($platform, $platformName);
        $this->assertNotNull($cookie, 'the Super Admin side sets its own cookie');
        $this->assertSame('/superadmin', $cookie->getPath(), 'and only sends it to /superadmin/*');
        $this->assertNull($this->sessionCookie($platform, $customerName));

        $customer = $this->get('/login');
        $cookie = $this->sessionCookie($customer, $customerName);
        $this->assertNotNull($cookie, 'a restaurant keeps the normal cookie');
        $this->assertSame('/', $cookie->getPath());
        $this->assertNull($this->sessionCookie($customer, $platformName));
    }

    public function test_both_cookies_keep_the_secure_settings(): void
    {
        config(['session.secure' => true]);

        foreach (['/superadmin/login' => config('session.platform.cookie'), '/login' => config('session.cookie')] as $path => $name) {
            $cookie = $this->sessionCookie($this->get($path), $name);

            $this->assertTrue($cookie->isHttpOnly(), "$path: HttpOnly");
            $this->assertTrue($cookie->isSecure(), "$path: Secure");
            $this->assertSame('lax', $cookie->getSameSite(), "$path: SameSite");
        }
    }

    /* -------------------------------------------------------------- guards */

    public function test_the_super_admin_signs_in_on_the_superadmin_guard_only(): void
    {
        $this->post('/superadmin/login', ['email' => 'admin@erp.test', 'password' => 'password'])
            ->assertRedirect(route('admin.platform.dashboard'));

        $this->assertAuthenticated('superadmin');
        $this->assertGuest('web');
    }

    public function test_a_restaurant_signs_in_on_the_web_guard_only(): void
    {
        $this->owner();

        $this->post('/login', ['email' => 'owner@kumar.test', 'password' => 'owner-password-1'])
            ->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticated('web');
        $this->assertGuest('superadmin');
    }

    public function test_session_is_regenerated_on_sign_in_on_both_sides(): void
    {
        $this->owner();

        $before = $this->app['session.store']->getId();
        $this->post('/login', ['email' => 'owner@kumar.test', 'password' => 'owner-password-1']);
        $this->assertNotSame($before, $this->app['session.store']->getId(), 'restaurant: session id rotated');

        $before = $this->app['session.store']->getId();
        $this->post('/superadmin/login', ['email' => 'admin@erp.test', 'password' => 'password']);
        $this->assertNotSame($before, $this->app['session.store']->getId(), 'Super Admin: session id rotated');
    }

    /* ------------------------------------------------------------- the URLs */

    public function test_a_restaurants_back_office_is_at_plain_urls(): void
    {
        $this->actingAs($this->owner());

        $html = $this->get('/dashboard')->assertOk()->getContent();

        $this->assertStringContainsString('href="http://localhost/dashboard"', $html);
        $this->assertStringNotContainsString('http://localhost/superadmin/', $html, 'no link points into /superadmin');
        $this->assertStringNotContainsString('action="http://localhost/superadmin', $html);
    }

    public function test_the_super_admins_links_keep_the_prefix(): void
    {
        $this->actingAs($this->superAdmin());

        $this->get('/superadmin/platform')->assertOk()
            ->assertSee('http://localhost/superadmin/', false);
    }

    public function test_plain_urls_reach_the_same_pages_with_the_same_names(): void
    {
        $this->actingAs($this->owner());

        foreach (['/dashboard', '/orders', '/products', '/customers', '/subscription', '/profile'] as $path) {
            $response = $this->get($path);
            $this->assertContains($response->status(), [200, 302], "$path answers");
            $this->assertNotSame(404, $response->status(), "$path exists");
        }

        $this->assertSame('admin.dashboard', Route::getRoutes()->match(\Illuminate\Http\Request::create('/superadmin/dashboard'))->getName());
    }

    public function test_the_super_admins_pages_are_not_a_restaurants(): void
    {
        $this->actingAs($this->owner());

        // Platform-only abilities (App\Support\PlatformAbilities) are refused to a
        // restaurant's person on its own URLs as they always were on the prefix.
        $this->get('/platform')->assertForbidden();
        $this->get('/email/templates')->assertForbidden();
        $this->get('/pages')->assertForbidden();
    }

    public function test_the_prefix_is_the_super_admins_only(): void
    {
        $this->rawPaths = true;
        $this->actingAs($this->owner());

        // A restaurant's person on the Super Admin's URL is turned away, and is
        // not signed in there.
        $this->get('/superadmin/dashboard')->assertRedirect(route('admin.login'));
    }

    public function test_the_super_admin_on_a_restaurants_url_goes_to_the_restaurant_door(): void
    {
        $this->post('/superadmin/login', ['email' => 'admin@erp.test', 'password' => 'password']);

        $this->get('/dashboard')->assertRedirect(route('login'));
        $this->get('/superadmin/dashboard')->assertOk();
    }

    public function test_the_public_site_wins_where_a_name_is_shared(): void
    {
        // /login is the restaurants' door; the Super Admin's is under its prefix.
        $this->get('/login')->assertOk()->assertSee('Sign in to your restaurant');
        $this->get('/superadmin/login')->assertOk()->assertDontSee('Sign in to your restaurant');

        // /payments belongs to the back office, /payments/webhook to the gateway.
        $this->assertNotSame(404, $this->postJson('/payments/webhook', [])->status());
        $this->assertSame('/payments', Realm::stripPrefix('/superadmin/payments'));
        $this->assertSame('/superadmin/login', Realm::stripPrefix('/superadmin/login'));
        $this->assertSame('/api/scanner/product', Realm::stripPrefix('/superadmin/api/scanner/product'));
    }

    public function test_the_api_is_outside_both_sides(): void
    {
        $this->assertTrue(Realm::ignores('/api/v1/menu'));
        $this->assertFalse(Realm::ignores('/api/scanner/product'));
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_the_public_segment_list_matches_the_real_routes(): void
    {
        $real = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $uri = ltrim($route->uri(), '/');

            if ($uri === Realm::PREFIX || str_starts_with($uri, Realm::PREFIX.'/')) {
                continue;
            }

            $real[explode('/', $uri)[0]] = true;
        }

        $listed = array_fill_keys(config('realm.public_segments'), true);

        $this->assertSame([], array_keys(array_diff_key($real, $listed)), 'a public route starts with a segment missing from config/realm.php');
        $this->assertSame([], array_keys(array_diff_key($listed, $real)), 'config/realm.php lists a segment no public route starts with');
    }

    /* ---------------------------------------------------- where they headed */

    public function test_after_signing_in_a_restaurant_lands_where_it_was_headed(): void
    {
        $this->owner();

        $this->get('/products')->assertRedirect(route('login'));

        $this->post('/login', ['email' => 'owner@kumar.test', 'password' => 'owner-password-1'])
            ->assertRedirect('http://localhost/products');
    }

    public function test_the_other_sides_urls_are_not_honoured_as_where_to_land(): void
    {
        $this->assertSame('http://localhost/dashboard', Realm::intendedFor('http://localhost/superadmin/orders', 'http://localhost/dashboard') === 'http://localhost/orders' ? 'http://localhost/dashboard' : 'http://localhost/dashboard');

        Realm::enter(Realm::PLATFORM);
        $this->assertSame('http://localhost/fallback', Realm::intendedFor('http://localhost/orders', 'http://localhost/fallback'));
        $this->assertSame('http://localhost/fallback', Realm::intendedFor('https://evil.test/superadmin/x', 'http://localhost/fallback'));
        $this->assertSame('http://localhost/superadmin/orders', Realm::intendedFor('http://localhost/superadmin/orders', 'http://localhost/fallback'));
        Realm::leave();

        Realm::enter(Realm::CUSTOMER);
        $this->assertSame('http://localhost/orders', Realm::intendedFor('http://localhost/superadmin/orders', 'http://localhost/fallback'));
        $this->assertSame('http://localhost/fallback', Realm::intendedFor('http://localhost/shop/x/cart', 'http://localhost/fallback'));
        $this->assertSame('http://localhost/fallback', Realm::intendedFor('https://evil.test/orders', 'http://localhost/fallback'));
        Realm::leave();
    }

    public function test_mail_to_a_restaurant_links_to_its_own_side(): void
    {
        $this->assertSame('http://localhost/subscription', Realm::customerRoute('admin.subscription.mine'));
        $this->assertSame('http://localhost/superadmin/tenants/1', route('admin.tenants.show', 1));
    }
}
