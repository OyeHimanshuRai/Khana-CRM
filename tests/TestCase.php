<?php

namespace Tests;

use App\Models\Setting;
use App\Support\CurrentShop;
use App\Support\CurrentTenant;
use App\Support\Modules;
use App\Support\Realm;
use App\Support\ScannerSettings;
use Illuminate\Contracts\Auth\Authenticatable as UserContract;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /*
     | The request-scoped memos live in static properties, so in a test run
     | they outlive the request - and the database - they were read from. A
     | tenant id memoised by one test was being stamped onto users created by
     | the next, against a database where that tenant no longer existed.
     |
     | Cleared on both sides of every test, so no test depends on another
     | having remembered to tidy up after itself. Modules::forget() takes
     | PlanAccess and PriceLists with it.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->forgetMemos();
        Realm::forget();
        $this->recordRoutesHit();
    }

    /*
     | Optional coverage of the HTTP layer: set ROUTE_HIT_LOG to a file path and
     | every route the suite actually runs is appended to it, one name per line.
     | A real measurement of which screens and actions have a test, where a
     | text search of the tests can only guess. Off unless the variable is set.
     */
    private function recordRoutesHit(): void
    {
        $file = getenv('ROUTE_HIT_LOG');

        if (! $file) {
            return;
        }

        $this->app['events']->listen(\Illuminate\Routing\Events\RouteMatched::class, function ($event) use ($file) {
            $route = $event->route;
            @file_put_contents($file, ($route->getName() ?: $route->uri()).' '.implode('|', $route->methods())."\n", FILE_APPEND | LOCK_EX);
        });
    }

    /*
     | The Super Admin (/superadmin, guard `superadmin`) and a restaurant (the
     | normal URLs, guard `web`) are separate sides of the app - see
     | App\Support\Realm. A test acts as one person, so that person is put on
     | both guards, and outside-a-request code (route() in the test body) reads
     | URLs for their side, exactly as the app does inside a request.
     */
    private ?UserContract $actor = null;

    public function actingAs(UserContract $user, $guard = null)
    {
        parent::actingAs($user, $guard);

        // Only the back office's own accounts (a storefront guest signs in on
        // the `customer` guard and stays there).
        if (! $user instanceof \App\Models\User) {
            return $this;
        }

        foreach (['web', 'superadmin'] as $name) {
            $this->app['auth']->guard($name)->setUser($user);
        }

        $this->actor = $user;

        Realm::useFallback($user->isSuperAdmin() ? Realm::PLATFORM : Realm::CUSTOMER);

        return $this;
    }

    /*
     | Many tests were written when a restaurant's back office was also under
     | /superadmin. A restaurant's person asking for /superadmin/orders now asks
     | for /orders - what a browser does - so those paths still read as before.
     | Anyone else, and the Super Admin, are left exactly as written.
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        if ($this->restaurantSide()) {
            $uri = $this->restaurantPath((string) $uri);
        }

        // Like a browser: links on the page that came back read on the side of
        // the page asked for, so route() after a request matches what it redirected to.
        Realm::useFallback(Realm::fromPath((string) (parse_url((string) $uri, PHP_URL_PATH) ?: '/')));

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    /** Is the person this test is signed in as a restaurant's, not the Super Admin? */
    private function restaurantSide(): bool
    {
        $user = $this->actor ?? $this->app['auth']->guard('web')->user();

        return $user !== null && method_exists($user, 'isSuperAdmin') && ! $user->isSuperAdmin();
    }

    private function restaurantPath(string $uri): string
    {
        $path = parse_url($uri, PHP_URL_PATH) ?: '';

        if (! str_starts_with($path, '/superadmin/') || str_starts_with($path, '/superadmin/login')) {
            return $uri;
        }

        return Realm::customerUrl($uri);
    }

    protected function tearDown(): void
    {
        $this->forgetMemos();
        $this->actor = null;
        Realm::forget();

        parent::tearDown();
    }

    private function forgetMemos(): void
    {
        CurrentTenant::forget();
        CurrentShop::forget();
        Modules::forget();
        ScannerSettings::flush();

        // Setting caches through the cache store, which needs the app.
        if (isset($this->app)) {
            Setting::flush();
        }
    }
}
