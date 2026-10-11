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
 * .env stays the fallback for the credentials: a key left blank on the
 * screen does not clobber a working value the server was deployed with.
 *
 * The transport itself goes the other way. Which mailer runs is a deployment
 * decision - it is what decides whether a message leaves the machine at all -
 * and a row in a database the application itself can write must not be able
 * to overrule it. A staging box pinned to `log` stayed pinned to `log` in
 * everybody's head and was quietly delivering through Gmail, because this
 * class read the settings table last. So: MAIL_MAILER wins when it is set,
 * and the screen decides only when the environment has stayed silent.
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

        $mailer = self::pinnedMailer() ?? self::value($stored, 'mail_mailer');

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
     * The mailer the environment pinned, if it pinned one.
     *
     * Read twice on purpose. Normally `env()` answers, but a host that has
     * run `config:cache` never loads the .env file at all, so `env()` returns
     * null there for a key that is certainly set. In that case the cached
     * `mail.default` is literally what .env said when the cache was built,
     * and nothing has overwritten it yet at this point in the boot - so it is
     * the environment's answer, not the database's.
     */
    private static function pinnedMailer(): ?string
    {
        $value = env('MAIL_MAILER');

        if ($value === null && app()->configurationIsCached()) {
            $value = Config::get('mail.default');
        }

        return filled($value) ? (string) $value : null;
    }

    /**
     * Which fields the environment has already decided, for the settings
     * screen. A field somebody can type into that will then be ignored is
     * worse than a field that says why it is disabled.
     *
     * @return array<int, string>
     */
    public static function lockedByEnvironment(): array
    {
        return self::pinnedMailer() === null ? [] : ['mail_mailer'];
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
