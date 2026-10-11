<?php

namespace Khana\Kit\Events\Console;

use Illuminate\Console\Command;
use Khana\Kit\Events\Delivery\Deliverer;
use Khana\Kit\Events\Delivery\DeliveryReport;

/**
 * Send whatever is waiting. Run by the scheduler every minute as the retry
 * sweep; by hand, --force ignores the backoff after a receiver is fixed.
 */
final class OutboxDeliverCommand extends Command
{
    protected $signature = 'outbox:deliver {subscriber? : Only this receiver} {--force : Ignore the backoff}';

    protected $description = 'Send recorded events to the services that subscribe to them';

    public function handle(Deliverer $deliverer): int
    {
        $reports = $deliverer->run($this->argument('subscriber'), (bool) $this->option('force'));

        if ($reports === []) {
            $this->components->info('No subscribers are configured.');

            return self::SUCCESS;
        }

        $failed = false;

        foreach ($reports as $name => $report) {
            $line = match ($report->outcome) {
                DeliveryReport::SENT => "{$report->sent} event(s) sent, up to seq {$report->lastSeq}",
                DeliveryReport::WAITING => "backing off after a failure; up to seq {$report->lastSeq} (use --force)",
                DeliveryReport::BUSY => 'another delivery is running',
                default => "failed after {$report->sent} event(s): {$report->error}",
            };

            $failed = $failed || $report->outcome === DeliveryReport::FAILED;

            $this->components->twoColumnDetail($name, $line);
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
