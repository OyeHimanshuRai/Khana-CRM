<?php

namespace App\Providers;

use App\Auth\AccountsSignIn;
use App\Auth\EndSessions;
use App\Auth\PeopleHandlers;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Khana\Kit\Events\Inbox\Handlers;
use Khana\Kit\Sso\LocalSignIn;
use Khana\Kit\Sso\RevokesSessions;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bound here, in register(), because the kit decides whether to
        // offer /sso/login by asking whether anything answers LocalSignIn.
        $this->app->bind(LocalSignIn::class, AccountsSignIn::class);
        $this->app->bind(RevokesSessions::class, EndSessions::class);
    }

    public function boot(): void
    {
        /*
        | One rule for every `can()` in ERP: the ability is one of
        | App\Support\Roles::ABILITIES and the person's role either lists it
        | or does not. Anything else is left to whoever defined it.
        */
        Gate::before(function (User $user, string $ability) {
            return isset(Roles::ABILITIES[$ability]) ? $user->allows($ability) : null;
        });

        PeopleHandlers::register($this->app->make(Handlers::class));
    }
}
