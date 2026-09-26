<?php

namespace Khana\Kit\Events;

use Illuminate\Database\Eloquent\Model;

/**
 * How far one receiver has got through this service's outbox.
 *
 * @property string $subscriber
 * @property int $last_seq
 * @property int $attempts
 * @property string|null $last_error
 * @property \Carbon\CarbonImmutable|null $last_attempt_at
 * @property \Carbon\CarbonImmutable|null $last_success_at
 * @property \Carbon\CarbonImmutable|null $next_attempt_at
 */
class OutboxCursor extends Model
{
    /** Longest wait between attempts at a receiver that keeps failing. */
    public const MAX_BACKOFF = 300;

    protected $table = 'khana_outbox_cursors';

    protected $primaryKey = 'subscriber';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'last_seq' => 'integer',
            'attempts' => 'integer',
            'last_attempt_at' => 'immutable_datetime',
            'last_success_at' => 'immutable_datetime',
            'next_attempt_at' => 'immutable_datetime',
        ];
    }

    public static function for(string $subscriber): self
    {
        return static::query()->firstOrCreate(['subscriber' => $subscriber], ['last_seq' => 0]);
    }

    public function isWaiting(): bool
    {
        return $this->next_attempt_at !== null && $this->next_attempt_at->isFuture();
    }

    public function advanceTo(int $seq): void
    {
        $this->forceFill([
            'last_seq' => max($this->last_seq, $seq),
            'attempts' => 0,
            'last_error' => null,
            'last_attempt_at' => now(),
            'last_success_at' => now(),
            'next_attempt_at' => null,
        ])->save();
    }

    /**
     * Back off 5s, 10s, 20s ... up to five minutes.
     *
     * Without it, a receiver that is down turns every sale into a ten-second
     * timeout on the queue worker, and the mail queued behind it waits too.
     * The once-a-minute sweep keeps trying in the meantime.
     */
    public function recordFailure(string $error): void
    {
        $attempts = $this->attempts + 1;

        $this->forceFill([
            'attempts' => $attempts,
            'last_error' => mb_substr($error, 0, 2000),
            'last_attempt_at' => now(),
            'next_attempt_at' => now()->addSeconds(min(self::MAX_BACKOFF, 5 * (2 ** min($attempts - 1, 10)))),
        ])->save();
    }

    public function rewindTo(int $lastSeq): void
    {
        $this->forceFill([
            'last_seq' => $lastSeq,
            'attempts' => 0,
            'last_error' => null,
            'next_attempt_at' => null,
        ])->save();
    }
}
