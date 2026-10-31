<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * A pre-deployment checklist for the settings that decide whether a live
 * install is safe. Read-only: it reports, it never changes anything.
 *
 * Exits non-zero when a blocking problem is found, so a deploy script can
 * stop on it.
 */
class SecurityAudit extends Command
{
    protected $signature = 'security:audit';

    protected $description = 'Check the environment and accounts for unsafe production settings';

    /** Passwords the seeders and docs use; any account still on one is open. */
    private const KNOWN_PASSWORDS = ['password', 'admin', 'secret', '12345678'];

    public function handle(): int
    {
        $problems = 0;
        $warnings = 0;

        $check = function (bool $ok, string $label, string $fix, bool $blocking = true) use (&$problems, &$warnings): void {
            if ($ok) {
                $this->line("  <info>PASS</info>  {$label}");

                return;
            }

            $blocking ? $problems++ : $warnings++;
            $this->line('  '.($blocking ? '<error> FAIL </error>' : '<comment> WARN </comment>')." {$label}");
            $this->line("          fix: {$fix}");
        };

        $this->info('Environment');
        $check(config('app.env') === 'production', 'APP_ENV is production', 'set APP_ENV=production', false);
        $check(! config('app.debug'), 'APP_DEBUG is off', 'set APP_DEBUG=false (debug pages leak code and secrets)');
        $check(filled(config('app.key')), 'APP_KEY is set', 'run php artisan key:generate');
        $check(str_starts_with((string) config('app.url'), 'https://'), 'APP_URL uses https', 'serve over TLS and set APP_URL=https://...');
        $check((bool) config('session.secure'), 'Session cookie is Secure', 'set SESSION_SECURE_COOKIE=true');
        $check((bool) config('session.http_only'), 'Session cookie is HttpOnly', 'set SESSION_HTTP_ONLY=true');
        $check(config('logging.channels.'.config('logging.default').'.level', 'debug') !== 'debug',
            'Log level is not debug', 'set LOG_LEVEL=warning or higher', false);

        // The deploy steps that fail silently when forgotten (see SystemHealthService).
        $health = app(\App\Services\SystemHealthService::class);
        $check($health->pendingMigrations() === [], 'Database migrations are up to date', 'run php artisan migrate');
        $check($health->unsyncedPermissions() === [], 'Every declared permission exists', 'run php artisan permissions:sync');

        $this->newLine();
        $this->info('Accounts');

        $weak = User::query()->withoutGlobalScopes()->get(['id', 'email', 'password'])
            ->filter(function (User $user) {
                foreach (self::KNOWN_PASSWORDS as $guess) {
                    if (Hash::check($guess, $user->password)) {
                        return true;
                    }
                }

                return false;
            });

        $check($weak->isEmpty(), 'No account uses a well-known password',
            'change the password of: '.$weak->pluck('email')->take(10)->implode(', '));

        $check(! User::query()->withoutGlobalScopes()->where('email', config('admin.default_email'))->where('email', 'like', '%@erp.test')->exists(),
            'Default admin email has been replaced', 'create your own Super Admin and remove '.config('admin.default_email'), false);

        $this->newLine();
        $problems === 0
            ? $this->info("Done. No blocking problems ({$warnings} warning(s)).")
            : $this->error("{$problems} blocking problem(s), {$warnings} warning(s).");

        return $problems === 0 ? self::SUCCESS : self::FAILURE;
    }
}
