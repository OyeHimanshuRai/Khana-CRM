<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\EmailLog;
use Illuminate\Console\Command;

/**
 * Housekeeping for the delivery log.
 *
 * The table gains a row for every email the app sends and is mostly
 * uninteresting after a few months, so it needs a ceiling or it becomes the
 * largest thing in the database.
 */
class PruneEmailLogs extends Command
{
    protected $signature = 'email:prune-logs {--days=90 : Keep entries newer than this}';

    protected $description = 'Delete email log entries older than the retention window';

    public function handle(): int
    {
        $days = (int) $this->option('days');

        if ($days < 1) {
            $this->error('Retention must be at least one day.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);

        // Chunked, because the first run on a neglected table could otherwise
        // be a single delete over a very large number of rows.
        $deleted = 0;

        do {
            $batch = EmailLog::where('created_at', '<', $cutoff)->limit(1000)->delete();
            $deleted += $batch;
        } while ($batch > 0);

        if ($deleted > 0) {
            ActivityLog::record(
                'email_log.pruned',
                "Pruned {$deleted} email log entr(y/ies) older than {$days} days",
            );
        }

        $this->info("Deleted {$deleted} entr".($deleted === 1 ? 'y' : 'ies').".");

        return self::SUCCESS;
    }
}
