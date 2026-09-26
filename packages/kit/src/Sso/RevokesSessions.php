<?php

namespace Khana\Kit\Sso;

/**
 * Ends everything a person has open in this product: browser sessions and
 * device tokens. Run when Accounts says so (accounts.sessions.revoked) -
 * someone was deactivated, or pressed "sign out everywhere".
 */
interface RevokesSessions
{
    public function revoke(string $userId, string $reason): void;
}
