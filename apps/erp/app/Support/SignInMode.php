<?php

namespace App\Support;

/**
 * How people get in to ERP on this install.
 *
 * Through Khana Accounts whenever it is configured - that is the only way in
 * production. On a developer's machine with no Accounts running, the sign-in
 * page lists the people already mirrored here and lets one be picked, so the
 * product can be opened at all. That door exists only in the `local` and
 * `testing` environments and only while Accounts is not configured, unless
 * AUTH_LOCAL_LOGIN says otherwise.
 */
final class SignInMode
{
    public static function throughAccounts(): bool
    {
        return filled(config('khana.sso.accounts_url')) && filled(config('khana.sso.client_id'));
    }

    public static function localAllowed(): bool
    {
        $explicit = config('auth.local_login');

        if ($explicit !== null) {
            return (bool) $explicit && ! app()->environment('production');
        }

        return ! self::throughAccounts() && app()->environment('local', 'testing');
    }
}
