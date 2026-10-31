<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Something changed on a branch's floor or pass (SRS 8, 9).
 *
 * ---------------------------------------------------------------------------
 * One event, and it carries almost nothing
 * ---------------------------------------------------------------------------
 *
 * The obvious design broadcasts the ticket: its lines, its table, its
 * timings, and the screen renders from the payload. That means the ticket is
 * rendered twice - once in Blade for the poll, once in JavaScript for the
 * socket - and the two drift, so a restaurant running Reverb slowly gets a
 * different kitchen screen from one that is not.
 *
 * So this says "something changed here" and nothing else. The screen does
 * what it already knows how to do: fetch its fragment. One renderer, one
 * source of truth, and the socket is purely a nudge that replaces waiting
 * for the next poll.
 *
 * It also means this event is safe by construction. A payload with no order
 * value, no guest name and no dish in it cannot leak any of them, whatever
 * happens to the channel authorisation.
 *
 * ---------------------------------------------------------------------------
 * Dispatching this is never load-bearing
 * ---------------------------------------------------------------------------
 *
 * With BROADCAST_CONNECTION=null - the default, and correct for any install
 * that cannot run a long-lived process - this is dispatched and discarded.
 * Nothing downstream may depend on it having arrived. Every screen that
 * listens also polls, and the poll is what guarantees correctness; the socket
 * only makes it quicker.
 */
class ShopBoardChanged implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param  int  $shopId  the branch whose screens should refresh
     * @param  string  $reason  what happened, for the console and for a
     *                          screen that wants to be choosy. Not trusted
     *                          for anything and not shown to anybody.
     */
    public function __construct(
        public readonly int $shopId,
        public readonly string $reason = 'changed',
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('shop.'.$this->shopId)];
    }

    public function broadcastAs(): string
    {
        return 'board.changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'reason' => $this->reason,
            'at' => now()->toIso8601String(),
        ];
    }
}
