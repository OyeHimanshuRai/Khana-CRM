<?php

namespace Khana\Kit\Events\Inbox;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Applies stored events, oldest first, one stream at a time.
 *
 * A stream is one sender's events for one outlet. An event that fails is
 * marked failed and its stream stops there - later events for that outlet
 * wait behind it, because applying a sale before the menu change it depends
 * on would be wrong in a way nobody could untangle - while every other
 * outlet carries on. Somebody then fixes the cause and retries it, or skips
 * it with a reason (inbox:retry, inbox:skip).
 *
 * Each event's effect and its "applied" mark are one transaction, so an
 * event is applied exactly once however often this runs.
 */
final class Processor
{
    public function __construct(
        private readonly Handlers $handlers,
        private readonly ReferenceGuard $guard,
    ) {}

    /**
     * @return array{applied: int, ignored: int, failed: int, waiting: int, busy: bool}
     */
    public function run(int $limit = 500): array
    {
        $counts = ['applied' => 0, 'ignored' => 0, 'failed' => 0, 'waiting' => 0, 'busy' => false];

        $lock = Cache::lock('khana:inbox:process', 300);

        if (! $lock->get()) {
            return ['busy' => true] + $counts;
        }

        try {
            $blocked = InboxEvent::query()
                ->where('status', InboxEvent::FAILED)
                ->distinct()
                ->pluck('stream')
                ->flip()
                ->all();

            $events = InboxEvent::query()
                ->where('status', InboxEvent::PENDING)
                ->orderBy('id')
                ->limit($limit)
                ->get();

            foreach ($events as $event) {
                if (isset($blocked[$event->stream])) {
                    $counts['waiting']++;

                    continue;
                }

                $outcome = $this->apply($event);
                $counts[$outcome]++;

                if ($outcome === 'failed' || $outcome === 'waiting') {
                    $blocked[$event->stream] = true;
                }
            }
        } finally {
            $lock->release();
        }

        return $counts;
    }

    /**
     * @return 'applied'|'ignored'|'failed'|'waiting'
     */
    private function apply(InboxEvent $event): string
    {
        $handler = $this->handlers->for($event->type, $event->version);

        if ($handler === null) {
            $event->markIgnored('No handler here for this type and version.');

            return 'ignored';
        }

        try {
            // Is the event about an outlet this service knows, of the business
            // it names, that owns this product? See ReferenceGuard.
            $reason = $this->guard->check($event);

            if ($reason !== null) {
                $event->markIgnored($reason);

                return 'ignored';
            }

            DB::transaction(function () use ($handler, $event) {
                $handler($event->toEnvelope());
                $event->markApplied();
            });

            return 'applied';
        } catch (RetryLater $e) {
            $event->refresh()->noteRetry($e->getMessage());

            return 'waiting';
        } catch (Throwable $e) {
            report($e);
            $event->refresh()->markFailed($e);

            return 'failed';
        }
    }
}
