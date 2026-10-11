<?php

namespace Khana\Kit\Events\Console;

use Illuminate\Console\Command;
use Khana\Kit\Events\Inbox\InboxEvent;
use Khana\Kit\Events\Inbox\Processor;

final class InboxRetryCommand extends Command
{
    protected $signature = 'inbox:retry {event? : Inbox id or event id} {--all : Every failed event}';

    protected $description = 'Try failed events again, once their cause is fixed';

    public function handle(Processor $processor): int
    {
        if ($this->option('all')) {
            $count = InboxEvent::query()->where('status', InboxEvent::FAILED)->update(['status' => InboxEvent::PENDING]);
        } else {
            $event = InboxEvent::findByReference((string) $this->argument('event'));

            if ($event === null || $event->status !== InboxEvent::FAILED) {
                $this->components->error('Name a failed event (inbox:status lists them), or pass --all.');

                return self::FAILURE;
            }

            $event->retry();
            $count = 1;
        }

        $counts = $processor->run();

        $this->components->info("Retrying {$count}: applied {$counts['applied']}, failed {$counts['failed']}, waiting {$counts['waiting']}.");

        return $counts['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
