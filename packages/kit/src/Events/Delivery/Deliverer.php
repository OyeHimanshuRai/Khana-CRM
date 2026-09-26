<?php

namespace Khana\Kit\Events\Delivery;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Khana\Kit\Events\Outbox;
use Khana\Kit\Events\OutboxCursor;
use Khana\Kit\Events\OutboxEvent;
use Khana\Kit\Events\Sequencer;

/**
 * Sends each receiver what it has not had yet, in seq order.
 *
 * One sender per receiver at a time, held by a lock, so two workers can never
 * interleave batches and put events out of order. The cursor only moves on a
 * 2xx: a receiver that is down simply finds everything waiting when it comes
 * back, which is the whole of the "survives any one service being down"
 * promise.
 *
 * Events a receiver does not subscribe to are stepped over - the cursor
 * still passes them - so seq numbers at the receiver have gaps. That is
 * normal and is not how a missing event is detected; the nightly check is.
 */
final class Deliverer
{
    /** Batches sent to one receiver in one run before yielding to the next. */
    private const MAX_BATCHES_PER_RUN = 20;

    public function __construct(
        private readonly Subscribers $subscribers,
        private readonly Sequencer $sequencer,
        private readonly Transport $transport,
    ) {}

    /**
     * @return array<string, DeliveryReport>
     */
    public function run(?string $only = null, bool $force = false): array
    {
        $this->sequencer->run();

        $reports = [];

        foreach ($this->subscribers->all() as $name => $subscriber) {
            if ($only !== null && $name !== $only) {
                continue;
            }

            $reports[$name] = $this->deliverTo($subscriber, $force);
        }

        return $reports;
    }

    public function deliverTo(Subscriber $subscriber, bool $force = false): DeliveryReport
    {
        $lock = Cache::lock('khana:outbox:deliver:'.$subscriber->name, 120);

        if (! $lock->get()) {
            return DeliveryReport::busy();
        }

        try {
            $cursor = OutboxCursor::for($subscriber->name);

            if (! $force && $cursor->isWaiting()) {
                return DeliveryReport::waiting($cursor->last_seq);
            }

            $source = Outbox::source();
            $batchSize = max(1, min(100, (int) config('khana.events.batch_size', 100)));
            $sent = 0;

            for ($i = 0; $i < self::MAX_BATCHES_PER_RUN; $i++) {
                $rows = OutboxEvent::query()
                    ->where('seq', '>', $cursor->last_seq)
                    ->orderBy('seq')
                    ->limit($batchSize)
                    ->get();

                if ($rows->isEmpty()) {
                    break;
                }

                $batch = $rows->filter(fn (OutboxEvent $event) => $subscriber->wants($event));

                if ($batch->isNotEmpty()) {
                    try {
                        $this->transport->send($subscriber, $source, $this->body($batch, $source));
                    } catch (DeliveryFailed $e) {
                        $cursor->recordFailure($e->getMessage());

                        return DeliveryReport::failed($sent, $cursor->last_seq, $e->getMessage());
                    }

                    $sent += $batch->count();
                }

                $cursor->advanceTo((int) $rows->last()->seq);
            }

            return DeliveryReport::sent($sent, $cursor->last_seq);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  Collection<int, OutboxEvent>  $batch
     */
    private function body(Collection $batch, string $source): string
    {
        return json_encode(
            ['events' => $batch->map(fn (OutboxEvent $event) => $event->toEnvelope($source)->toArray())->values()->all()],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }
}
