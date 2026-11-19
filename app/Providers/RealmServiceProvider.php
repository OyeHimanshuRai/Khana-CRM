<?php

namespace App\Providers;

use App\Support\Realm;
use Illuminate\Support\ServiceProvider;

/**
 * Settles the session cookie and the default guard for a request's side of the
 * app (see App\Support\Realm) before any other provider can build a session or
 * a guard on the wrong one, and makes generated URLs read right for a
 * restaurant: /orders, not /superadmin/orders.
 */
class RealmServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if ($this->app->runningInConsole() || ! $this->app->bound('request')) {
            return;
        }

        $path = $this->app['request']->getPathInfo();

        if (! Realm::ignores($path)) {
            Realm::configure(Realm::fromPath($path));
        }
    }

    public function boot(): void
    {
        $this->app['url']->formatPathUsing(
            fn (string $path) => Realm::isCustomer() ? Realm::stripPrefix($path) : $path
        );
    }
}
