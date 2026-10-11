<?php

namespace App\Providers;

use App\Auth\AccountsSignIn;
use Illuminate\Support\ServiceProvider;
use Khana\Kit\Sso\LocalSignIn;

/**
 * Signing in through Accounts.
 *
 * Binding LocalSignIn is what makes the kit register /sso/login and
 * /sso/callback, so it happens in register() - before the kit boots and
 * looks. Bound on every install: where Accounts is not configured the
 * button is simply not shown, and the routes answer with the reason.
 */
class SignInServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(LocalSignIn::class, AccountsSignIn::class);
    }
}
