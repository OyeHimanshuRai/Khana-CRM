<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Khana\Kit\Events\Delivery\Subscriber;
use Khana\Kit\Events\Delivery\Subscribers;
use Khana\Kit\Events\Inbox\InboxEvent;
use Khana\Kit\Events\OutboxCursor;
use Khana\Kit\Events\OutboxEvent;
use RuntimeException;

/**
 * The watch on the event plumbing: says so, loudly, when an event has been
 * waiting to go out for more than ten minutes, or one that came in could not
 * be applied.
 *
 * Delivery retries by itself and a receiver that was down finds everything
 * waiting when it comes back, so a short wait is normal and nobody is told.
 * Ten minutes is not: a sold-out dish is still on sale in CRM. And a failed
 * event stops every later event for its outlet until a person retries or
 * skips it (inbox:retry, inbox:skip) - which nobody will do unless told.
 *
 * An event waits only if the receiver actually wants it. One for an outlet
 * with no CRM is stepped over by the sender and is nobody's delay.
 *
 * The alarm is a critical log line and a reported exception, once an hour
 * for each receiver for as long as the trouble lasts - not every ten
 * minutes, which is how an alarm comes to be ignored.
 */
class WatchEvents extends Command
{
    /** Minutes an event may wait to be sent before it is worth waking somebody. */
    private const PATIENCE = 10;

    /** Seconds between two alarms about the same thing. */
    private const QUIET = 3600;

    protected $signature = 'erp:watch';

    protected $description = 'Raise the alarm when events have waited over ten minutes to be sent, or one received has failed';

    public function handle(Subscribers $subscribers): int
    {
        $trouble = false;

        foreach ($subscribers->all() as $subscriber) {
            [$waiting, $oldest] = $this->waitingFor($subscriber);

            if ($waiting === 0) {
                $this->components->twoColumnDetail('To '.$subscriber->name, 'nothing waiting too long');

                continue;
            }

            $trouble = true;
            $cursor = OutboxCursor::query()->find($subscriber->name);

            $this->alarm('outbox:'.$subscriber->name, sprintf(
                '%d ERP event(s) have waited over %d minutes to reach %s; the oldest since %s UTC. Last error: %s',
                $waiting, self::PATIENCE, $subscriber->name, $oldest, $cursor?->last_error ?? 'none recorded',
            ));
        }

        $failed = InboxEvent::query()->where('status', InboxEvent::FAILED)->orderBy('id')->get(['id', 'type', 'stream', 'error']);

        if ($failed->isEmpty()) {
            $this->components->twoColumnDetail('Received', 'nothing failed');
        } else {
            $trouble = true;
            $first = $failed->first();

            $this->alarm('inbox', sprintf(
                '%d event(s) ERP received could not be applied, and %d outlet(s) are waiting behind them. First: #%d %s (%s): %s',
                $failed->count(), $failed->pluck('stream')->unique()->count(), $first->id, $first->type, $first->stream, mb_strimwidth((string) $first->error, 0, 300, '...'),
            ));
        }

        return $trouble ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The events this receiver wants and has not had, recorded more than
     * ten minutes ago - counted the way the sender itself finds what is
     * left to send, plus those not yet numbered: if the sender is not
     * running at all, nothing ever is.
     *
     * @return array{0: int, 1: ?string} how many, and when the oldest was recorded
     */
    private function waitingFor(Subscriber $subscriber): array
    {
        // Read, not made: a watch that has never sent has no cursor to keep.
        $sentUpTo = (int) OutboxCursor::query()->where('subscriber', $subscriber->name)->value('last_seq');

        $waiting = 0;
        $oldest = null;

        OutboxEvent::query()
            ->where(fn ($q) => $q->whereNull('seq')->orWhere('seq', '>', $sentUpTo))
            ->where('created_at', '<', now()->subMinutes(self::PATIENCE))
            ->orderBy('id')
            ->each(function (OutboxEvent $event) use ($subscriber, &$waiting, &$oldest) {
                if ($subscriber->wants($event)) {
                    $waiting++;
                    $oldest ??= $event->created_at?->toDateTimeString();
                }
            });

        return [$waiting, $oldest];
    }

    /** Say it on the console every time; wake somebody at most once an hour. */
    private function alarm(string $about, string $message): void
    {
        $this->components->error($message);

        if (Cache::add('erp:watch:'.$about, true, self::QUIET)) {
            Log::critical($message);
            report(new RuntimeException($message));
        }
    }
}
