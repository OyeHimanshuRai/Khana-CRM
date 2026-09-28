<?php

namespace App\Support;

use Khana\Kit\Ui\EntitledProductSwitcher;

/**
 * Where a job that left CRM is done now.
 *
 * Signing up, passwords, the business's details, its outlets, its team and
 * what it pays are all Khana Accounts' screens. CRM links to them rather than
 * keeping copies, and this is the one place those links are built - through
 * the kit, so every product spells the address the same way.
 *
 * Null when this install has not been told where Accounts is
 * (KHANA_ACCOUNTS_URL). Callers hide the link rather than render one that
 * goes nowhere.
 */
final class Accounts
{
    public static function url(string $path = '/'): ?string
    {
        return EntitledProductSwitcher::url('accounts', '/'.ltrim($path, '/'));
    }
}
