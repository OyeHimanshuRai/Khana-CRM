<?php

namespace App\Services\Accounts;

use RuntimeException;

/**
 * ERP asked Accounts for a business's snapshot and did not get one it could
 * use: no address is set up, the token was refused, Accounts was down, or
 * what came back was an error or not what the contract promises.
 *
 * Whoever asked carries on with the copy ERP already has and asks again
 * later. The message is a plain sentence saying what went wrong.
 */
final class AccountsUnavailable extends RuntimeException {}
