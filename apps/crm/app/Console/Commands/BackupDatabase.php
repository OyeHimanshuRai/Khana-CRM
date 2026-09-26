<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Take a database backup, and throw away the ones that are too old (§17).
 *
 * ---------------------------------------------------------------------------
 * Why mysqldump rather than a PHP dumper
 * ---------------------------------------------------------------------------
 *
 * Because the thing being protected is a restaurant's takings, and a backup
 * that cannot be restored is worse than none - it is the same risk plus false
 * confidence. `mysqldump` produces a file that MySQL itself will read back,
 * which is a guarantee no amount of hand-written INSERT generation gives.
 *
 * The cost is a hard dependency on the binary being on PATH. That is stated
 * plainly rather than worked around: the command checks for it and fails with
 * a message naming the problem, so a server missing it says so on the first
 * scheduled run instead of writing empty files for six months.
 *
 * ---------------------------------------------------------------------------
 * The password never appears in the command line
 * ---------------------------------------------------------------------------
 *
 * Arguments are visible to every user on the box through `ps`. The credentials
 * go into a temporary defaults file with 0600 permissions, passed with
 * `--defaults-extra-file`, and that file is deleted in a `finally` - including
 * when the dump fails, which is exactly when it would otherwise be left on
 * disk.
 *
 * ---------------------------------------------------------------------------
 * Restore testing
 * ---------------------------------------------------------------------------
 *
 * §17 asks for "backups and restore testing", and the honest position is that
 * this command does half of it. `--verify` checks the dump is a plausible,
 * complete SQL file - non-trivial in size, and ending with the marker
 * mysqldump writes last, which is what catches the disk filling up mid-write.
 * It does not load the file into a scratch database; that needs a second
 * server and is a deployment decision, not a code one. See docs/ERP-OVERVIEW.
 */
class BackupDatabase extends Command
{
    protected $signature = 'backup:run
                            {--keep= : How many daily backups to retain (default: config backup.keep)}
                            {--no-verify : Skip the sanity check on the written file}
                            {--compress : Gzip the dump (needs the gzip binary)}';

    protected $description = 'Dump the database to storage/app/backups and prune old dumps';

    /** mysqldump writes this as its final line when it finishes cleanly. */
    private const COMPLETION_MARKER = 'Dump completed';

    /** Below this, the file is a header and an error, not a database. */
    private const MIN_PLAUSIBLE_BYTES = 1024;

    public function handle(): int
    {
        $connection = config('database.default');

        if ($connection !== 'mysql') {
            /*
             | Refused rather than attempted. A "backup" that silently did
             | nothing on SQLite would be the worst outcome here, and the test
             | suite runs on SQLite - so this branch is reached in CI and must
             | not be a failure that breaks the build.
             */
            $this->warn("Database connection is [{$connection}], not mysql. Nothing to dump.");

            return self::SUCCESS;
        }

        $config = config('database.connections.mysql');
        // Configurable so a server can dump to a separate volume, and so the
        // test suite never touches a real backup. See config/backup.php.
        $directory = config('backup.path');

        File::ensureDirectoryExists($directory);

        $name = sprintf('%s-%s.sql', $config['database'], now()->format('Y-m-d-His'));
        $path = $directory.DIRECTORY_SEPARATOR.$name;

        $defaults = $this->writeDefaultsFile($config);

        try {
            $this->dump($defaults, $config, $path);
        } catch (ProcessFailedException $e) {
            // Leave nothing half-written behind to be mistaken for a backup.
            File::delete($path);

            $this->error('mysqldump failed: '.trim($e->getProcess()->getErrorOutput()));

            return self::FAILURE;
        } finally {
            File::delete($defaults);
        }

        if (! $this->option('no-verify') && ! $this->verify($path)) {
            File::delete($path);

            $this->error('The dump did not look complete and has been deleted. Check disk space.');

            return self::FAILURE;
        }

        if ($this->option('compress')) {
            $path = $this->compress($path) ?? $path;
        }

        $this->info(sprintf('Backed up to %s (%s).', basename($path), $this->humanSize(filesize($path))));

        $this->prune($directory, (int) ($this->option('keep') ?? config('backup.keep'))); 

        return self::SUCCESS;
    }

    /* ---------------------------------------------------------- the dump */

    /**
     * Credentials in a 0600 file, never on the command line. See the docblock.
     *
     * @param  array<string, mixed>  $config
     */
    private function writeDefaultsFile(array $config): string
    {
        $path = rtrim((string) config('backup.path'), '\/').DIRECTORY_SEPARATOR.'.my.cnf.'.bin2hex(random_bytes(8));

        File::put($path, implode("\n", [
            '[client]',
            'user='.($config['username'] ?? ''),
            'password='.($config['password'] ?? ''),
            'host='.($config['host'] ?? '127.0.0.1'),
            'port='.($config['port'] ?? 3306),
            '',
        ]));

        // Best effort: chmod is a no-op on Windows, where the storage
        // directory's own ACL is what protects this.
        @chmod($path, 0600);

        return $path;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function dump(string $defaults, array $config, string $path): void
    {
        $process = new Process([
            'mysqldump',
            '--defaults-extra-file='.$defaults,
            /*
             | A restaurant's database is written to continuously - a KOT lands
             | while the dump is running. Without this the dump is a set of
             | tables from slightly different moments, and an order can exist
             | with no invoice, or an invoice with no order.
             */
            '--single-transaction',
            // Do not hold a global read lock; --single-transaction is enough
            // for InnoDB and this keeps the till working during the backup.
            '--skip-lock-tables',
            // Routines and triggers are part of the schema, not extras.
            '--routines',
            '--triggers',
            '--events',
            $config['database'],
        ]);

        // A large database on a busy evening. The default 60s is not enough.
        $process->setTimeout(3600);

        $handle = fopen($path, 'w');

        try {
            $process->run(function (string $type, string $buffer) use ($handle) {
                if ($type === Process::OUT) {
                    fwrite($handle, $buffer);
                }
            });
        } finally {
            fclose($handle);
        }

        if (! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
    }

    /* ------------------------------------------------------ verification */

    /**
     * Is this a complete dump, or the first half of one?
     *
     * Two cheap checks that between them catch the realistic failures: a
     * truncated write because the disk filled, and an "error connecting"
     * message written where a database should be.
     */
    private function verify(string $path): bool
    {
        if (! File::exists($path) || filesize($path) < self::MIN_PLAUSIBLE_BYTES) {
            return false;
        }

        // The marker is on the last line; read the tail rather than the file.
        $handle = fopen($path, 'r');
        fseek($handle, max(0, filesize($path) - 512), SEEK_SET);
        $tail = fread($handle, 512);
        fclose($handle);

        return str_contains($tail, self::COMPLETION_MARKER);
    }

    private function compress(string $path): ?string
    {
        $process = new Process(['gzip', '-f', $path]);
        $process->setTimeout(600);
        $process->run();

        if (! $process->isSuccessful()) {
            // Not fatal: an uncompressed backup is still a backup.
            $this->warn('gzip failed; keeping the uncompressed dump.');

            return null;
        }

        return $path.'.gz';
    }

    /* ---------------------------------------------------------- retention */

    /**
     * Delete dumps older than the retention window.
     *
     * Counted in files rather than days on purpose. A server whose scheduler
     * was down for a week should not wake up and delete every backup it has
     * because they are all "too old" - keeping the newest N is the behaviour
     * that survives its own outage.
     */
    private function prune(string $directory, int $keep): void
    {
        if ($keep < 1) {
            return;
        }

        $dumps = collect(File::files($directory))
            ->filter(fn ($file) => preg_match('/\.sql(\.gz)?$/', $file->getFilename()) === 1)
            ->sortByDesc(fn ($file) => $file->getMTime())
            ->values();

        $stale = $dumps->slice($keep);

        foreach ($stale as $file) {
            File::delete($file->getPathname());
        }

        if ($stale->isNotEmpty()) {
            $this->line(sprintf('  Pruned %d old backup(s), kept %d.', $stale->count(), min($keep, $dumps->count())));
        }
    }

    private function humanSize(int|false $bytes): string
    {
        if ($bytes === false) {
            return 'unknown size';
        }

        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024) {
                return round($bytes, 1).' '.$unit;
            }

            $bytes /= 1024;
        }

        return round($bytes, 1).' TB';
    }
}
