<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * Is anything quietly broken? (§17)
 *
 * ---------------------------------------------------------------------------
 * What this is for
 * ---------------------------------------------------------------------------
 *
 * §17 asks for three things that are all the same thing wearing different
 * hats: failed-job monitoring, daily error logs, and system health. All three
 * are about failures that do not interrupt anybody - a queued email that threw
 * at two in the morning, a backup that stopped running in March, a scheduler
 * nobody restarted after a reboot. Nothing on a till or a kitchen screen ever
 * says so, which is exactly why they go unnoticed for months.
 *
 * So this reads the state of things and reports it. It changes nothing, and
 * every check is written to degrade rather than throw: a missing log directory
 * or a queue table that was never migrated has to render as "cannot tell"
 * beside the checks that did work, because a health screen that 500s is the
 * one thing worse than no health screen.
 *
 * ---------------------------------------------------------------------------
 * Tone, not pass/fail
 * ---------------------------------------------------------------------------
 *
 * Each check returns a tone - success, warning, danger - because the useful
 * question is not "is it broken" but "should somebody look today". A single
 * failed job is a warning; fifty is a danger; one that failed in January and
 * was never cleared is noise pretending to be either, which is why the ages
 * are reported alongside the counts.
 */
class SystemHealthService
{
    /** More than this many failed jobs and something is systematically wrong. */
    private const FAILED_JOBS_SERIOUS = 25;

    /**
     * A backup older than this means the schedule has stopped running.
     * Configurable, because "too old" depends on how often it is scheduled.
     */
    private function staleHours(): int
    {
        return (int) config('backup.stale_hours', 36);
    }

    /**
     * Every check, ready to render.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'queue' => $this->queue(),
            'backups' => $this->backups(),
            'environment' => $this->environment(),
            'storage' => $this->storage(),
        ];
    }

    /* ------------------------------------------------------------- queue */

    /**
     * Pending and failed work.
     *
     * Both tables are checked for existence first: a deployment using Redis or
     * SQS has no `jobs` table, and that is a correct configuration rather than
     * a fault, so it reports "not this driver" instead of a red panel.
     *
     * @return array<string, mixed>
     */
    public function queue(): array
    {
        $driver = config('queue.default');

        $pending = null;
        $failed = null;
        $oldestFailed = null;

        if (Schema::hasTable('jobs')) {
            $pending = DB::table('jobs')->count();
        }

        if (Schema::hasTable('failed_jobs')) {
            $failed = DB::table('failed_jobs')->count();

            $oldest = DB::table('failed_jobs')->min('failed_at');
            $oldestFailed = $oldest ? Carbon::parse($oldest) : null;
        }

        return [
            'driver' => $driver,
            'pending' => $pending,
            'failed' => $failed,
            'oldest_failed' => $oldestFailed,
            'tone' => match (true) {
                $failed === null => 'default',
                $failed === 0 => 'success',
                $failed >= self::FAILED_JOBS_SERIOUS => 'danger',
                default => 'warning',
            },
            /*
             | A deep backlog on a sync driver is impossible, and on a database
             | driver it means nothing is running `queue:work` - which is the
             | failure this number exists to catch.
             */
            'worker_suspect' => $driver !== 'sync' && $pending !== null && $pending > 0,
        ];
    }

    /**
     * The failed jobs themselves, newest first.
     *
     * The payload is decoded only far enough to name the job class. The whole
     * payload is deliberately not exposed: a failed email job carries the
     * rendered message, and a failed payment job carries gateway identifiers.
     *
     * @return Collection<int, object>
     */
    public function failedJobs(int $limit = 50): Collection
    {
        if (! Schema::hasTable('failed_jobs')) {
            return collect();
        }

        return collect(DB::table('failed_jobs')
            ->orderByDesc('failed_at')
            ->limit($limit)
            ->get())
            ->map(function (object $row) {
                $payload = json_decode($row->payload ?? '{}', true);

                return (object) [
                    'id' => $row->id,
                    'uuid' => $row->uuid ?? null,
                    'queue' => $row->queue,
                    'job' => $payload['displayName'] ?? 'Unknown job',
                    'failed_at' => $row->failed_at ? Carbon::parse($row->failed_at) : null,
                    /*
                     | The first line only. A stack trace in a table cell is
                     | unreadable, and the line that says what went wrong is
                     | always the first one.
                     */
                    'reason' => trim(strtok((string) $row->exception, "\n") ?: 'No exception recorded'),
                ];
            });
    }

    /* ----------------------------------------------------------- backups */

    /**
     * Has a backup run recently, and how many are being kept? (§17)
     *
     * @return array<string, mixed>
     */
    public function backups(): array
    {
        // From config, not storage_path(): a server may dump to a separate
        // volume, and the test suite must never read or clear the real one.
        $directory = config('backup.path');

        if (! File::isDirectory($directory)) {
            return [
                'count' => 0,
                'latest_at' => null,
                'latest_size' => null,
                'total_size' => 0,
                'tone' => 'warning',
                'note' => 'No backup has ever run on this server.',
            ];
        }

        $dumps = collect(File::files($directory))
            ->filter(fn ($f) => preg_match('/\.sql(\.gz)?$/', $f->getFilename()) === 1)
            ->sortByDesc(fn ($f) => $f->getMTime())
            ->values();

        if ($dumps->isEmpty()) {
            return [
                'count' => 0,
                'latest_at' => null,
                'latest_size' => null,
                'total_size' => 0,
                'tone' => 'danger',
                'note' => 'The backup directory exists but is empty.',
            ];
        }

        $latest = $dumps->first();
        $latestAt = Carbon::createFromTimestamp($latest->getMTime());
        $hours = $latestAt->diffInHours(now());
        $stale = $this->staleHours();

        return [
            'count' => $dumps->count(),
            'latest_at' => $latestAt,
            'latest_name' => $latest->getFilename(),
            'latest_size' => $latest->getSize(),
            'total_size' => $dumps->sum(fn ($f) => $f->getSize()),
            'tone' => $hours > $stale ? 'danger' : 'success',
            'note' => $hours > $stale
                ? 'The last backup is '.$latestAt->diffForHumans().'. Check that the scheduler is running.'
                : null,
        ];
    }

    /* ------------------------------------------------------- environment */

    /**
     * The settings that are dangerous in production and invisible until they
     * bite.
     *
     * Each one is a fact plus whether it matters *here* - `APP_DEBUG` on a
     * local machine is correct and on a live server leaks the database
     * password to anybody who can trigger an exception.
     *
     * @return array<int, array<string, mixed>>
     */
    public function environment(): array
    {
        $production = app()->environment('production');

        $checks = [];

        $checks[] = [
            'label' => 'Debug mode',
            'value' => config('app.debug') ? 'On' : 'Off',
            'tone' => config('app.debug') && $production ? 'danger' : 'success',
            'note' => config('app.debug') && $production
                ? 'Debug is on in production. An exception page shows the database password.'
                : null,
        ];

        $checks[] = [
            'label' => 'HTTPS',
            'value' => str_starts_with((string) config('app.url'), 'https://') ? 'Yes' : 'No',
            'tone' => $production && ! str_starts_with((string) config('app.url'), 'https://') ? 'danger' : 'success',
            'note' => $production && ! str_starts_with((string) config('app.url'), 'https://')
                ? '§17 requires HTTPS. Card details and session cookies are crossing the wire in the clear.'
                : null,
        ];

        $checks[] = [
            'label' => 'Environment',
            'value' => app()->environment(),
            'tone' => 'default',
            'note' => null,
        ];

        $checks[] = [
            'label' => 'Queue driver',
            'value' => config('queue.default'),
            /*
             | `sync` means every queued job runs inside the web request that
             | dispatched it. Mail and push then happen while a guest waits for
             | their order confirmation, and a provider outage becomes a
             | timeout on the order rather than a retry in the background.
             */
            'tone' => $production && config('queue.default') === 'sync' ? 'warning' : 'success',
            'note' => $production && config('queue.default') === 'sync'
                ? 'Jobs run inside the request. Email and push will slow down ordering.'
                : null,
        ];

        $checks[] = [
            'label' => 'Broadcasting',
            'value' => config('broadcasting.default'),
            /*
             | Not a fault. Every live screen polls as well - see
             | public/assets/js/realtime.js - so `null` is a complete, working
             | configuration and is only worth saying out loud.
             */
            'tone' => 'default',
            'note' => in_array(config('broadcasting.default'), ['null', 'log'], true)
                ? 'Live screens fall back to polling, which works. Reverb makes them instant.'
                : null,
        ];

        return $checks;
    }

    /* ----------------------------------------------------------- storage */

    /**
     * Disk, and whether the directories the app writes to are writable.
     *
     * A read-only `storage` is the classic post-deploy failure: everything
     * looks fine until the first upload or the first log line.
     *
     * @return array<string, mixed>
     */
    public function storage(): array
    {
        $paths = [
            'Logs' => storage_path('logs'),
            'Cache' => storage_path('framework/cache'),
            'Sessions' => storage_path('framework/sessions'),
            'Uploads' => storage_path('app/public'),
            'Backups' => storage_path('app/backups'),
        ];

        $writable = [];

        foreach ($paths as $label => $path) {
            $writable[$label] = File::isDirectory($path) && is_writable($path);
        }

        $free = @disk_free_space(base_path());
        $total = @disk_total_space(base_path());

        $usedPercent = ($free !== false && $total !== false && $total > 0)
            ? (int) round((($total - $free) / $total) * 100)
            : null;

        return [
            'writable' => $writable,
            'all_writable' => ! in_array(false, $writable, true),
            'free_bytes' => $free === false ? null : (int) $free,
            'total_bytes' => $total === false ? null : (int) $total,
            'used_percent' => $usedPercent,
            'tone' => match (true) {
                in_array(false, $writable, true) => 'danger',
                $usedPercent !== null && $usedPercent >= 90 => 'danger',
                $usedPercent !== null && $usedPercent >= 80 => 'warning',
                default => 'success',
            },
        ];
    }

    /* -------------------------------------------------------- error log */

    /**
     * The tail of today's log, newest entry first (§17).
     *
     * ------------------------------------------------------------------
     * Read backwards, and capped
     * ------------------------------------------------------------------
     *
     * A busy laravel.log reaches hundreds of megabytes, and the interesting
     * end is the last one. This seeks to the end and reads a fixed window
     * backwards rather than loading the file - the difference between a screen
     * that opens and one that exhausts memory on the server it was opened to
     * diagnose.
     *
     * Only ERROR and CRITICAL lines are kept. A log full of INFO is how the
     * one line that matters gets missed.
     *
     * @return Collection<int, array<string, string>>
     */
    public function recentErrors(int $limit = 40, int $window = 262144): Collection
    {
        /*
         | Taken from the logging config rather than hard-coded, so a
         | deployment using daily files or a custom path still reports, and so
         | a test can point it at a scratch file instead of truncating the
         | developer's real log - which is how this line came to be written.
         */
        $path = config('logging.channels.'.config('logging.default').'.path')
            ?? storage_path('logs/laravel.log');

        if (! File::exists($path)) {
            return collect();
        }

        $size = filesize($path);
        $handle = @fopen($path, 'r');

        if ($handle === false) {
            return collect();
        }

        try {
            fseek($handle, max(0, $size - $window), SEEK_SET);
            $chunk = fread($handle, min($window, $size));
        } finally {
            fclose($handle);
        }

        if ($chunk === false) {
            return collect();
        }

        /*
         | Entries start with "[2026-09-17 05:12:33] production.ERROR: ...".
         | Split on that rather than on newlines, so a stack trace stays with
         | the message it belongs to instead of becoming forty empty rows.
         */
        $pattern = '/^\[(\d{4}-\d{2}-\d{2}[ T][\d:.]+)\][^\n]*?\.(ERROR|CRITICAL|ALERT|EMERGENCY): (.*)$/m';

        preg_match_all($pattern, $chunk, $matches, PREG_SET_ORDER);

        return collect($matches)
            ->reverse()
            ->take($limit)
            ->map(fn (array $m) => [
                'at' => $m[1],
                'level' => $m[2],
                // One line. The trace is in the file for whoever needs it.
                'message' => trim(mb_strimwidth($m[3], 0, 300, '…')),
            ])
            ->values();
    }
}
