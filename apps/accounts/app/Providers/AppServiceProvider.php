<?php

namespace App\Providers;

use App\Models\Passport\AccessToken;
use App\Models\Passport\Client;
use App\Services\AccountsDirectory;
use App\Support\MachineScopes;
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
        | Never normally shown, for the reason above - but Passport asks for
        | it before it knows whether it will be, so without it every sign-in
        | through /oauth/authorize fails before it starts.
        */
        Passport::authorizationView('auth.authorize');

        // A key made for one outlet gets tokens that say so.
        Passport::useAccessTokenEntity(AccessToken::class);

        /*
        | The scopes a machine token may carry. Passport drops any scope it
        | has not been told about, silently, so one missing from this list
        | is a token that is issued and then refused by the product it was
        | meant for. Which of them a client may ask for is on the client.
        */
        Passport::tokensCan(MachineScopes::ALL);

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
