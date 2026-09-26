<?php

namespace Khana\Kit\Events\Console;

use Illuminate\Console\Command;
use Khana\Kit\Events\Delivery\Subscribers;
use Khana\Kit\Events\OutboxCursor;
use Khana\Kit\Events\OutboxEvent;

/**
 * Forget events past the retention window - but never one a receiver has
 * not had yet. An outbox that silently dropped an unsent event would break
 * the one promise it exists to keep.
 */
final class OutboxPruneCommand extends Command
{
    protected $signature = 'outbox:prune {--days= : Keep this many days (default: khana.events.retention_days)}';

    protected $description = 'Delete delivered events older than the retention window';

    public function handle(Subscribers $subscribers): int
    {
        $days = (int) ($this->option('days') ?? config('khana.events.retention_days', 90));

        if ($days < 1) {
            $this->components->error('--days must be at least 1.');

            return self::FAILURE;
        }

        $query = OutboxEvent::query()
            ->whereNotNull('seq')
            ->where('created_at', '<', now()->subDays($days));

        $names = array_keys($subscribers->all());

        if ($names !== []) {
            $delivered = min(array_map(fn (string $name) => OutboxCursor::for($name)->last_seq, $names));
            $query->where('seq', '<=', $delivered);
        }

        $deleted = $query->delete();

        $this->components->info("Pruned {$deleted} event(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
