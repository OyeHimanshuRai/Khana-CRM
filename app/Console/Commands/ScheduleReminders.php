<?php

namespace App\Console\Commands;

use App\Models\Shop;
use App\Services\ReminderService;
use Illuminate\Console\Command;

class ScheduleReminders extends Command
{
    protected $signature = 'reminders:schedule
                            {--shop= : Limit to one shop id}
                            {--dispatch : Send what this run schedules, straight away}';

    protected $description = 'Work out which payment reminders are now due and write them down';

    public function handle(ReminderService $reminders): int
    {
        if (! config('reminders.enabled', true)) {
            $this->warn('Reminders are switched off in config/reminders.php.');

            return self::SUCCESS;
        }

        $shop = $this->option('shop') ? Shop::find((int) $this->option('shop')) : null;

        if ($this->option('shop') && ! $shop) {
            $this->error('No shop with that id.');

            return self::FAILURE;
        }

        $result = $reminders->schedule($shop);

        $this->info(sprintf(
            'Looked at %d outstanding invoice(s); scheduled %d new reminder(s).',
            $result['considered'],
            $result['scheduled'],
        ));

        /*
         | Scheduling and sending are separate on purpose - see
         | ReminderService - but a shop running this by hand usually means
         | "and send them", so the flag is there.
         */
        if ($this->option('dispatch')) {
            $sent = $reminders->dispatch();

            $this->info(sprintf(
                'Sent %d, skipped %d as no longer needed, %d failed.',
                $sent['sent'], $sent['skipped'], $sent['failed'],
            ));
        }

        return self::SUCCESS;
    }
}
