<?php

namespace Khana\Kit;

use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Khana\Kit\Contracts\ContractValidator;
use Khana\Kit\Events\Console\EventsPingCommand;
use Khana\Kit\Events\Console\InboxRetryCommand;
use Khana\Kit\Events\Console\InboxSkipCommand;
use Khana\Kit\Events\Console\InboxStatusCommand;
use Khana\Kit\Events\Console\InboxWorkCommand;
use Khana\Kit\Events\Console\OutboxDeliverCommand;
use Khana\Kit\Events\Console\OutboxPruneCommand;
use Khana\Kit\Events\Console\OutboxReplayCommand;
use Khana\Kit\Events\Console\OutboxStatusCommand;
use Khana\Kit\Events\Delivery\HttpTransport;
use Khana\Kit\Events\Delivery\Transport;
use Khana\Kit\Events\Envelope;
use Khana\Kit\Events\Inbox\Handlers;
use Khana\Kit\Events\Inbox\InboxController;
use Khana\Kit\Sso\DatabaseSessionRevoker;
use Khana\Kit\Sso\LocalSignIn;
use Khana\Kit\Sso\MachineTokens;
use Khana\Kit\Sso\MachineTokenVerifier;
use Khana\Kit\Sso\RequireMachineToken;
use Khana\Kit\Sso\RevokesSessions;
use Khana\Kit\Sso\SignInClient;
use Khana\Kit\Sso\SsoController;
use Khana\Kit\Tenancy\Context;
use Khana\Kit\Tenancy\Directory;
use Khana\Kit\Tenancy\EnsureEntitled;
use Khana\Kit\Tenancy\Entitlements;
use Khana\Kit\Tenancy\ReferenceDirectory;
use Khana\Kit\Tenancy\ReferenceHandlers;
use Khana\Kit\Ui\AssetController;
use Khana\Kit\Ui\EntitledProductSwitcher;
use Khana\Kit\Ui\OutletController;
use Khana\Kit\Ui\ProductSwitcher;
use Khana\Kit\Ui\Ui;

final class KitServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/khana.php', 'khana');
        $this->mergeConfigFrom(__DIR__.'/../config/icons.php', 'icons');

        $this->app->singleton(Handlers::class);
        $this->app->bind(Transport::class, HttpTransport::class);
        $this->app->singleton(
            ContractValidator::class,
            fn ($app) => new ContractValidator((string) $app['config']->get('khana.contracts_path')),
        );

        // Per request, and per queued job: no tenancy answer outlives the
        // request that worked it out.
        $this->app->scoped(Context::class);
        $this->app->scoped(Entitlements::class);
        $this->app->bindIf(Directory::class, ReferenceDirectory::class);

        $this->app->singleton(SignInClient::class);
        $this->app->singleton(MachineTokens::class);
        $this->app->singleton(MachineTokenVerifier::class);
        $this->app->bindIf(RevokesSessions::class, DatabaseSessionRevoker::class);

        if ($this->keepsReferenceCopy()) {
            $this->app->bindIf(ProductSwitcher::class, EntitledProductSwitcher::class);
        }
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->keepsReferenceCopy()) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations/reference');
        }

        /** @var Router $router */
        $router = $this->app->make('router');
        $router->aliasMiddleware('entitled', EnsureEntitled::class);
        $router->aliasMiddleware('machine', RequireMachineToken::class);

        $this->bootUi();
        $this->bootMountPath();

        if (! $this->app->routesAreCached()) {
            // No middleware group: a server calls this, so there is no
            // session to start and no CSRF token to check. The signature is
            // the authentication.
            Route::post('internal/events', InboxController::class)->name('khana.inbox');

            // Only reached when public/vendor/khana has not been published.
            Route::get('vendor/khana/{path}', AssetController::class)->where('path', '.*')->name('khana.ui.asset');

            if ($this->keepsReferenceCopy()) {
                Route::middleware('web')->group(function () {
                    Route::get('khana/open', [OutletController::class, 'open'])->name('khana.open');
                    Route::post('khana/outlet', [OutletController::class, 'switch'])->middleware('auth')->name('khana.outlet.switch');
                });
            }
        }

        // Sign-in routes exist once the product has said how it signs a
        // person in locally, and not before.
        $this->app->booted(function () {
            if ($this->app->routesAreCached() || ! $this->app->bound(LocalSignIn::class)) {
                return;
            }

            Route::middleware('web')->group(function () {
                Route::get('sso/login', [SsoController::class, 'login'])->name('khana.sso.login');
                Route::get('sso/callback', [SsoController::class, 'callback'])->name('khana.sso.callback');
            });

            $this->app->make('router')->getRoutes()->refreshNameLookups();
        });

        // Signing in or out mid-request changes whose data this is.
        Event::listen([Login::class, Logout::class], function () {
            if ($this->app->resolved(Context::class)) {
                $this->app->make(Context::class)->forget();
            }
        });

        $handlers = $this->app->make(Handlers::class);

        // Diagnostic traffic: stored, marked applied, and otherwise nothing.
        $handlers->on('system.ping', 1, static function (Envelope $event): void {});

        if ($this->keepsReferenceCopy()) {
            ReferenceHandlers::register($handlers);

            $handlers->on('accounts.sessions.revoked', 1, function (Envelope $event) {
                $this->app->make(RevokesSessions::class)->revoke((string) $event->data['user_id'], (string) $event->data['reason']);
            });
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                OutboxDeliverCommand::class,
                OutboxReplayCommand::class,
                OutboxStatusCommand::class,
                OutboxPruneCommand::class,
                InboxWorkCommand::class,
                InboxStatusCommand::class,
                InboxRetryCommand::class,
                InboxSkipCommand::class,
                EventsPingCommand::class,
            ]);

            $this->publishes([__DIR__.'/../config/khana.php' => config_path('khana.php')], 'khana-config');
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            // The retry sweeps. Most events go out and are applied within
            // seconds, on the queue job each commit or batch dispatches;
            // these catch whatever that missed.
            $schedule->command('outbox:deliver')->everyMinute()->withoutOverlapping()->runInBackground();
            $schedule->command('inbox:work')->everyMinute()->withoutOverlapping()->runInBackground();
            $schedule->command('outbox:prune')->dailyAt('03:15');
        });
    }

    /**
     * The shared look: views as khana::, components as <x-khana::...>, and
     * the CSS and JavaScript published to public/vendor/khana.
     */
    /**
     * When APP_URL carries a path (https://khana.example/erp), the app lives
     * under that prefix on a shared domain. The web server strips the prefix
     * before PHP, so the app cannot see it on the request; generate every
     * url(), route() and asset() with it instead.
     */
    private function bootMountPath(): void
    {
        $url = (string) config('app.url');
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');

        if ($path !== '') {
            URL::forceScheme((string) parse_url($url, PHP_URL_SCHEME));
            URL::forceRootUrl(rtrim($url, '/'));
        }
    }

    private function bootUi(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'khana');
        Blade::anonymousComponentPath(__DIR__.'/../resources/views/components', 'khana');

        $this->publishes([Ui::path() => public_path('vendor/khana')], 'khana-ui');
    }

    /**
     * CRM and ERP keep a read-only copy of what Accounts owns. Accounts
     * holds the originals and needs no copy of itself.
     */
    private function keepsReferenceCopy(): bool
    {
        return $this->app['config']->get('khana.service') !== 'accounts';
    }
}
