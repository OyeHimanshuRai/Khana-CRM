<?php

namespace App\Support;

/**
 * How people sign in to CRM on this install.
 *
 * Accounts is the way in: it knows who a person is and which business and
 * outlets they work for, and App\Auth\AccountsSignIn mirrors that here. The
 * email-and-password form is what is left of CRM's own sign-in, for an
 * install with no Accounts to send people to - a developer's machine, the
 * test suite. Offering both on a live install would give every local user
 * a second password nobody manages, so the form goes away as soon as
 * Accounts is configured, unless AUTH_LOCAL_LOGIN says otherwise.
 */
final class SignInMode
{
    /** Whether Accounts is set up to sign people in here. */
    public static function throughAccounts(): bool
    {
        return filled(config('khana.sso.accounts_url')) && filled(config('khana.sso.client_id'));
    }

    /** Whether the email-and-password form is offered and accepted. */
    public static function localAllowed(): bool
    {
        $explicit = config('auth.local_login');

        return $explicit === null ? ! self::throughAccounts() : (bool) $explicit;
    }

    /**
     * A page on Accounts - signing up, a forgotten password - or null when
     * this install does not know where Accounts is.
     */
    public static function accountsUrl(string $path): ?string
    {
        $base = config('khana.urls.accounts') ?: config('khana.sso.accounts_url');

        return filled($base) ? rtrim((string) $base, '/').'/'.ltrim($path, '/') : null;
    }
}
