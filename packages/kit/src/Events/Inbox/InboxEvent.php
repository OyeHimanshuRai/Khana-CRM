<?php

namespace Khana\Kit\Events\Inbox;

use Illuminate\Database\Eloquent\Model;
use Khana\Kit\Events\Envelope;
use Throwable;

/**
 * An event another service sent this one.
 *
 * @property int $id
 * @property string $event_id
 * @property string $source
 * @property int $seq
 * @property string $type
 * @property int $version
 * @property string|null $tenant_id
 * @property string|null $outlet_id
 * @property string $stream
 * @property \Carbon\CarbonImmutable $occurred_at
 * @property array<string, mixed> $data
 * @property string $status
 * @property int $attempts
 * @property string|null $error
 * @property string|null $skip_reason
 */
class InboxEvent extends Model
{
    public const PENDING = 'pending';

    public const APPLIED = 'applied';

    /** No handler for this type and version here - normal while a sender emits two versions. */
    public const IGNORED = 'ignored';

    /** The handler threw. Its stream waits until somebody retries or skips it. */
    public const FAILED = 'failed';

    /** Given up on deliberately, with a reason, so the stream can carry on. */
    public const SKIPPED = 'skipped';

    protected $table = 'khana_inbox';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'seq' => 'integer',
            'version' => 'integer',
            'attempts' => 'integer',
            'data' => 'array',
            'occurred_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
            'applied_at' => 'immutable_datetime',
        ];
    }

    /**
     * The unit that is applied in order and paused as one: a sender's events
     * for one outlet, or for one business where no outlet is named.
     */
    public static function streamFor(string $source, ?string $tenantId, ?string $outletId): string
    {
        return $source.':'.match (true) {
            $outletId !== null => 'outlet:'.$outletId,
            $tenantId !== null => 'tenant:'.$tenantId,
            default => 'platform',
        };
    }

    public static function findByReference(string $reference): ?self
    {
        return ctype_digit($reference)
            ? static::query()->find((int) $reference)
            : static::query()->where('event_id', $reference)->first();
    }

    public function toEnvelope(): Envelope
    {
        return new Envelope(
            id: $this->event_id,
            type: $this->type,
            version: $this->version,
            source: $this->source,
            seq: $this->seq,
            occurredAt: $this->occurred_at->utc()->format('Y-m-d\TH:i:s\Z'),
            tenantId: $this->tenant_id,
            outletId: $this->outlet_id,
            data: $this->data ?? [],
        );
    }

    public function markApplied(): void
    {
        $this->forceFill([
            'status' => self::APPLIED,
            'attempts' => $this->attempts + 1,
            'error' => null,
            'applied_at' => now(),
        ])->save();
    }

    public function markIgnored(?string $reason = null): void
    {
        $this->forceFill([
            'status' => self::IGNORED,
            'skip_reason' => $reason === null ? null : mb_substr($reason, 0, 255),
            'applied_at' => now(),
        ])->save();
    }

    public function markFailed(Throwable $e): void
    {
        $this->forceFill([
            'status' => self::FAILED,
            'attempts' => $this->attempts + 1,
            'error' => mb_substr(get_class($e).': '.$e->getMessage(), 0, 4000),
        ])->save();
    }

    public function noteRetry(string $why): void
    {
        $this->forceFill([
            'attempts' => $this->attempts + 1,
            'error' => mb_substr($why, 0, 4000),
        ])->save();
    }

    public function retry(): void
    {
        $this->forceFill(['status' => self::PENDING])->save();
    }

    public function skip(string $reason): void
    {
        $this->forceFill([
            'status' => self::SKIPPED,
            'skip_reason' => mb_substr($reason, 0, 255),
            'applied_at' => now(),
        ])->save();
    }
}
