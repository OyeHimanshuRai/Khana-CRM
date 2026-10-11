<?php

namespace Khana\Kit\Events;

use Illuminate\Database\Eloquent\Model;

/**
 * An event this service has recorded, waiting to be sent or already sent.
 *
 * @property int $id
 * @property string $event_id
 * @property int|null $seq
 * @property string $type
 * @property int $version
 * @property string|null $tenant_id
 * @property string|null $outlet_id
 * @property \Carbon\CarbonImmutable $occurred_at
 * @property array<string, mixed> $data
 */
class OutboxEvent extends Model
{
    protected $table = 'khana_outbox';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'seq' => 'integer',
            'version' => 'integer',
            'data' => 'array',
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function toEnvelope(string $source): Envelope
    {
        return new Envelope(
            id: $this->event_id,
            type: $this->type,
            version: $this->version,
            source: $source,
            seq: (int) $this->seq,
            occurredAt: $this->occurred_at->utc()->format('Y-m-d\TH:i:s\Z'),
            tenantId: $this->tenant_id,
            outletId: $this->outlet_id,
            data: $this->data ?? [],
        );
    }
}
