<?php

namespace Khana\Kit\Events\Console;

use Illuminate\Console\Command;
use Khana\Kit\Events\Inbox\Processor;

final class InboxWorkCommand extends Command
{
    protected $signature = 'inbox:work';

    protected $description = 'Apply received events, in order, per outlet';

    public function handle(Processor $processor): int
    {
        $total = ['applied' => 0, 'ignored' => 0, 'failed' => 0, 'waiting' => 0];

        // Keep going while a pass makes progress; stop when all that is left
        // is waiting behind a failure or for a retry.
        do {
            $counts = $processor->run();

            if ($counts['busy']) {
                $this->components->info('Another worker is applying events.');

                return self::SUCCESS;
            }

            foreach ($total as $key => $value) {
                $total[$key] = $key === 'waiting' ? $counts[$key] : $value + $counts[$key];
            }
        } while ($counts['applied'] + $counts['ignored'] > 0);

        $this->components->info(sprintf(
            'Applied %d, ignored %d, failed %d, waiting %d.',
            $total['applied'],
            $total['ignored'],
            $total['failed'],
            $total['waiting'],
        ));

        return $total['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
