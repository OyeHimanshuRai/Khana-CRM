<?php

namespace Khana\Kit\Events\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Khana\Kit\Events\Inbox\InboxEvent;
use Khana\Kit\Events\Inbox\Processor;

/**
 * Give up on one failed event so its outlet can carry on. The reason is
 * required and logged: a skipped sale is a hole in somebody's stock, and
 * the next person to wonder why deserves to find out.
 */
final class InboxSkipCommand extends Command
{
    protected $signature = 'inbox:skip {event : Inbox id or event id} {--reason= : Why it is being skipped}';

    protected $description = 'Skip a failed event, with a reason, so its outlet can carry on';

    public function handle(Processor $processor): int
    {
        $reason = trim((string) $this->option('reason'));

        if ($reason === '') {
            $this->components->error('A --reason is required.');

            return self::FAILURE;
        }

        $event = InboxEvent::findByReference((string) $this->argument('event'));

        if ($event === null || $event->status !== InboxEvent::FAILED) {
            $this->components->error('Name a failed event; inbox:status lists them.');

            return self::FAILURE;
        }

        $event->skip($reason);

        Log::warning('Inbox event skipped', [
            'event_id' => $event->event_id,
            'type' => $event->type,
            'stream' => $event->stream,
            'reason' => $reason,
        ]);

        $counts = $processor->run();

        $this->components->info("Skipped {$event->event_id}. Then applied {$counts['applied']}, failed {$counts['failed']}.");

        return self::SUCCESS;
    }
}
