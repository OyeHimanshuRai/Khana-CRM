<?php

namespace Khana\Kit\Events\Console;

use Illuminate\Console\Command;
use Khana\Kit\Events\Delivery\Deliverer;
use Khana\Kit\Events\Delivery\Subscribers;
use Khana\Kit\Events\OutboxCursor;
use Khana\Kit\Events\OutboxEvent;

/**
 * Send one receiver everything again from a chosen seq.
 *
 * Safe by construction: the receiver's inbox is unique on event id, so what
 * it already has is acknowledged and ignored, and only what it lost is
 * stored. Events older than the retention window are gone and cannot be
 * replayed.
 */
final class OutboxReplayCommand extends Command
{
    protected $signature = 'outbox:replay {subscriber} {--from=1 : First seq to send again}';

    protected $description = 'Re-send events to one receiver from a chosen seq';

    public function handle(Subscribers $subscribers, Deliverer $deliverer): int
    {
        $name = (string) $this->argument('subscriber');
        $subscriber = $subscribers->find($name);

        if ($subscriber === null) {
            $this->components->error("\"{$name}\" is not a configured subscriber.");

            return self::FAILURE;
        }

        $from = filter_var($this->option('from'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($from === false) {
            $this->components->error('--from must be a whole number from 1.');

            return self::FAILURE;
        }

        $oldest = OutboxEvent::query()->whereNotNull('seq')->min('seq');

        if ($oldest !== null && $from < (int) $oldest) {
            $this->components->warn("Events before seq {$oldest} have been pruned; replaying from {$oldest}.");
            $from = (int) $oldest;
        }

        OutboxCursor::for($name)->rewindTo($from - 1);

        $report = $deliverer->deliverTo($subscriber, true);

        $this->components->info("Replayed to {$name} from seq {$from}: {$report->sent} event(s) sent, now at seq {$report->lastSeq}.");

        if ($report->error !== null) {
            $this->components->error($report->error);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
