<?php

namespace Khana\Kit\Events\Console;

use Illuminate\Console\Command;
use Khana\Kit\Events\Delivery\Subscribers;
use Khana\Kit\Events\OutboxCursor;
use Khana\Kit\Events\OutboxEvent;

final class OutboxStatusCommand extends Command
{
    protected $signature = 'outbox:status';

    protected $description = 'Show how far each receiver has got through the outbox';

    public function handle(Subscribers $subscribers): int
    {
        $newest = (int) OutboxEvent::query()->max('seq');
        $unnumbered = OutboxEvent::query()->whereNull('seq')->count();

        $this->components->twoColumnDetail('Newest seq', (string) $newest);
        $this->components->twoColumnDetail('Committed, not yet numbered', (string) $unnumbered);

        $rows = [];

        foreach (array_keys($subscribers->all()) as $name) {
            $cursor = OutboxCursor::for($name);
            $behind = OutboxEvent::query()->where('seq', '>', $cursor->last_seq)->count();
            $oldest = OutboxEvent::query()->where('seq', '>', $cursor->last_seq)->min('created_at');

            $rows[] = [
                $name,
                $cursor->last_seq,
                $behind,
                $oldest === null ? '-' : now()->diffForHumans($oldest, true),
                $cursor->attempts,
                $cursor->last_error ?? '-',
            ];
        }

        $this->table(['Receiver', 'Sent up to', 'Behind', 'Oldest waiting', 'Failures', 'Last error'], $rows);

        return self::SUCCESS;
    }
}
