<?php

namespace App\Models\Passport;

use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Passport\Client as PassportClient;

/**
 * Every client is one of our own products, created by `khana:clients`: there
 * is no third-party app to ask the person's consent for. Switching from CRM
 * to ERP therefore passes through Accounts without a screen.
 *
 * Which is exactly why a client may ask only for the scopes it was given by
 * name. With no consent screen, whatever a request asks for is what its
 * token carries - and the products trust a scope without asking whose token
 * it is on. Passport reads a client with no list as "may ask for anything",
 * so a person signing in through a browser client made that way could ask
 * for `tenants:read` and read any business. Here no list means none.
 */
class Client extends PassportClient
{
    public function skipsAuthorization(Authenticatable $user, array $scopes): bool
    {
        return true;
    }

    public function hasScope(string $scope): bool
    {
        return isset($this->attributes['scopes']) && parent::hasScope($scope);
    }
}
