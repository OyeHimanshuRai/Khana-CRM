<?php

namespace App\Console\Commands;

use App\Services\ReminderService;
use Illuminate\Console\Command;

class DispatchReminders extends Command
{
    protected $signature = 'reminders:dispatch
                            {--limit= : How many to attempt in this run}';

    protected $description = 'Send the payment reminders that are due to go out';

    public function handle(ReminderService $reminders): int
    {
        if (! config('reminders.enabled', true)) {
            $this->warn('Reminders are switched off in config/reminders.php.');

            return self::SUCCESS;
        }

        $limit = $this->option('limit') ? (int) $this->option('limit') : null;

        $result = $reminders->dispatch($limit);

        $this->info(sprintf(
            'Sent %d, skipped %d as no longer needed, %d failed.',
            $result['sent'], $result['skipped'], $result['failed'],
        ));

        /*
         | A skip is not a problem - it usually means the customer paid
         | between the reminder being raised and it going out, which is the
         | system working. A failure is, so it is worth a non-zero exit for
         | whatever is watching cron.
         */
        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
