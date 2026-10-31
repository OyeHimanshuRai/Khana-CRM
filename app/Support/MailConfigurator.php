<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Config;

/**
 * Applies the Mail Configuration saved under Settings > General.
 *
 * Without this the screen stores host, port and credentials that nothing
 * ever reads - the app keeps using whatever is in .env. Called once per
 * request from AppServiceProvider, off the cached settings map, so it costs
 * a single array lookup.
 *
 * .env stays the fallback: a key left blank on the screen does not clobber
 * a working value the server was deployed with.
 */
final class MailConfigurator
{
    public static function apply(): void
    {
        // A settings table that has not been migrated yet - during the very
        // first `migrate`, say - must not take the whole app down.
        try {
            $stored = Setting::map();
        } catch (\Throwable) {
            return;
        }

        if ($stored === []) {
            return;
        }

        $mailer = self::value($stored, 'mail_mailer');

        if ($mailer !== null) {
            Config::set('mail.default', $mailer);
        }

        $mailer ??= Config::get('mail.default');

        // Only the SMTP transport has a host and credentials; the others
        // take their settings from elsewhere entirely.
        if ($mailer === 'smtp') {
            self::set($stored, 'mail_host', 'mail.mailers.smtp.host');
            self::set($stored, 'mail_port', 'mail.mailers.smtp.port', fn ($v) => (int) $v);
            self::set($stored, 'mail_username', 'mail.mailers.smtp.username');
            self::set($stored, 'mail_password', 'mail.mailers.smtp.password');

            /*
             | Laravel 11+ calls this `scheme`, and expects "smtps" for
             | implicit TLS rather than the older "ssl"/"tls" wording the
             | settings screen uses. Explicit "none" turns encryption off.
             */
            $encryption = self::value($stored, 'mail_encryption');

            if ($encryption !== null) {
                Config::set('mail.mailers.smtp.scheme', match (strtolower($encryption)) {
                    'ssl', 'smtps' => 'smtps',
                    'tls' => 'smtp',
                    default => null,
                });
            }
        }

        self::set($stored, 'mail_from_address', 'mail.from.address');
        self::set($stored, 'mail_from_name', 'mail.from.name');
    }

    /**
     * @param  array<string, string|null>  $stored
     */
    private static function set(array $stored, string $key, string $path, ?callable $cast = null): void
    {
        $value = self::value($stored, $key);

        if ($value === null) {
            return;
        }

        Config::set($path, $cast ? $cast($value) : $value);
    }

    /**
     * A stored value, or null when it is unset or blank - blank means
     * "leave whatever .env has alone".
     *
     * @param  array<string, string|null>  $stored
     */
    private static function value(array $stored, string $key): ?string
    {
        $value = $stored[$key] ?? null;

        return $value === null || $value === '' ? null : (string) $value;
    }
}
