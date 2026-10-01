<?php

namespace App\Providers;

use App\Models\Passport\Client;
use App\Services\AccountsDirectory;
use Illuminate\Support\ServiceProvider;
use Khana\Kit\Tenancy\Directory;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Accounts owns the businesses and outlets, so it answers the
        // tenancy layer from its own tables, not a reference copy.
        $this->app->bind(Directory::class, AccountsDirectory::class);
    }

    public function boot(): void
    {
        /*
        | Every client is first-party (our own CRM, ERP and back office), so
        | the consent screen is skipped and clients are trusted. The custom
        | client model is what does the skipping - see App\Models\Passport.
        */
        Passport::useClientModel(Client::class);

        /*
        | The scopes a machine token may carry. A product reading the
        | snapshot asks for `tenants:read` and nothing else.
        */
        Passport::tokensCan([
            'tenants:read' => 'Read businesses, outlets, people and entitlements',
            'roles:read' => 'Read the roles a product offers, a business\'s own included',
        ]);

        /*
        | A person's browser token is short; a refresh token carries the
        | silent re-sign-in between products. Machine tokens are shorter
        | still - a product caches one for an hour and asks again.
        */
        Passport::tokensExpireIn(now()->addMinutes(60));
        Passport::refreshTokensExpireIn(now()->addDays(30));
        Passport::personalAccessTokensExpireIn(now()->addDays(30));
    }
}
