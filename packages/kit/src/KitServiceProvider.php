<?php

namespace Khana\Kit;

use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
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

final class KitServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/khana.php', 'khana');

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

        if (! $this->app->routesAreCached()) {
            // No middleware group: a server calls this, so there is no
            // session to start and no CSRF token to check. The signature is
            // the authentication.
            Route::post('internal/events', InboxController::class)->name('khana.inbox');
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
     * CRM and ERP keep a read-only copy of what Accounts owns. Accounts
     * holds the originals and needs no copy of itself.
     */
    private function keepsReferenceCopy(): bool
    {
        return $this->app['config']->get('khana.service') !== 'accounts';
    }
}
