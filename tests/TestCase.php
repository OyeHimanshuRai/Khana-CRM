<?php

namespace Tests;

use App\Models\Setting;
use App\Support\CurrentShop;
use App\Support\CurrentTenant;
use App\Support\Modules;
use App\Support\ScannerSettings;
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

    protected function tearDown(): void
    {
        $this->forgetMemos();

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
