<?php

namespace Khana\Kit\Events\Inbox\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Khana\Kit\Events\Inbox\Processor;

/**
 * Queued whenever a batch brings something new. The minute sweep in the
 * scheduler picks up anything this misses, and anything waiting to retry.
 */
final class ProcessInbox implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public int $uniqueFor = 300;

    public function __construct()
    {
        $queue = config('khana.events.queue');

        if (is_string($queue) && $queue !== '') {
            $this->onQueue($queue);
        }
    }

    public function uniqueId(): string
    {
        return 'khana-inbox-process';
    }

    /**
     * Keep going while a pass makes progress: the outlet a waiting sale needs
     * often arrives in the very batch that queued this job, and is applied
     * in the pass after the sale was first held back.
     */
    public function handle(Processor $processor): void
    {
        for ($pass = 0; $pass < 10; $pass++) {
            $counts = $processor->run();

            if ($counts['busy'] || $counts['applied'] + $counts['ignored'] === 0) {
                return;
            }
        }
    }
}
