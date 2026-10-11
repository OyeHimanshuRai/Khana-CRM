<?php

namespace Khana\Kit\Events\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Khana\Kit\Events\Delivery\Deliverer;

/**
 * Queued after every commit that recorded an event.
 *
 * Unique until it starts: a bill that records five events queues one job,
 * not five, and an event recorded while a run is already going queues the
 * next run so it is not left for the minute sweep. Never retried by the
 * queue - the sweep in the scheduler is the retry, with its own backoff.
 */
final class DeliverOutbox implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 120;

    public function __construct()
    {
        $queue = config('khana.events.queue');

        if (is_string($queue) && $queue !== '') {
            $this->onQueue($queue);
        }
    }

    public function uniqueId(): string
    {
        return 'khana-outbox-deliver';
    }

    public function handle(Deliverer $deliverer): void
    {
        $deliverer->run();
    }
}
