<?php

namespace App\Models\Passport;

use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Passport\Client as PassportClient;

/**
 * Every client is one of our own products, created by `khana:clients`: there
 * is no third-party app to ask the person's consent for. Switching from CRM
 * to ERP therefore passes through Accounts without a screen.
 */
class Client extends PassportClient
{
    public function skipsAuthorization(Authenticatable $user, array $scopes): bool
    {
        return true;
    }
}
