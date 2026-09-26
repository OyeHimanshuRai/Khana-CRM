<?php

namespace Khana\Kit\Events;

use Khana\Kit\Support\Ulid;
use stdClass;

/**
 * One event as it travels between services - the shape every event shares,
 * whatever its type:
 *
 *   id, type, version, source, seq, occurred_at, tenant_id, outlet_id, data
 *
 * fromArray() checks the envelope's own structure, which is enough to store
 * an event safely. Whether `data` is right for its type is the contract's
 * business: see ContractValidator.
 */
final class Envelope
{
    public const TYPE_PATTERN = '/^[a-z]+(\.[a-z][a-z0-9_]*)+$/';

    public const SOURCE_PATTERN = '/^[a-z][a-z0-9_]*$/';

    public const TIME_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?Z$/';

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly int $version,
        public readonly string $source,
        public readonly int $seq,
        public readonly string $occurredAt,
        public readonly ?string $tenantId,
        public readonly ?string $outletId,
        public readonly array $data,
    ) {}

    public static function fromArray(mixed $raw): self
    {
        if (! is_array($raw) || array_is_list($raw)) {
            throw new InvalidEnvelope('An event must be a JSON object.');
        }

        $problems = [];

        if (! Ulid::isValid($raw['id'] ?? null)) {
            $problems[] = 'id must be a ULID';
        }

        if (! is_string($raw['type'] ?? null) || preg_match(self::TYPE_PATTERN, $raw['type']) !== 1) {
            $problems[] = 'type must look like "crm.sale.recorded"';
        }

        if (! is_int($raw['version'] ?? null) || $raw['version'] < 1) {
            $problems[] = 'version must be a whole number from 1';
        }

        if (! is_string($raw['source'] ?? null) || preg_match(self::SOURCE_PATTERN, $raw['source']) !== 1) {
            $problems[] = 'source must be a service name';
        }

        if (! is_int($raw['seq'] ?? null) || $raw['seq'] < 1) {
            $problems[] = 'seq must be a whole number from 1';
        }

        if (! is_string($raw['occurred_at'] ?? null) || preg_match(self::TIME_PATTERN, $raw['occurred_at']) !== 1) {
            $problems[] = 'occurred_at must be a UTC time such as 2026-09-26T08:15:30Z';
        }

        foreach (['tenant_id', 'outlet_id'] as $key) {
            if (! array_key_exists($key, $raw)) {
                $problems[] = "{$key} must be present, even when null";
            } elseif ($raw[$key] !== null && ! Ulid::isValid($raw[$key])) {
                $problems[] = "{$key} must be a ULID or null";
            }
        }

        $data = $raw['data'] ?? null;

        if (! is_array($data) || ($data !== [] && array_is_list($data))) {
            $problems[] = 'data must be an object';
        }

        if ($problems !== []) {
            throw new InvalidEnvelope(implode('; ', $problems).'.');
        }

        return new self(
            id: $raw['id'],
            type: $raw['type'],
            version: $raw['version'],
            source: $raw['source'],
            seq: $raw['seq'],
            occurredAt: $raw['occurred_at'],
            tenantId: $raw['tenant_id'],
            outletId: $raw['outlet_id'],
            data: $data,
        );
    }

    /**
     * The wire form. An empty `data` is written as {} rather than [], or it
     * would stop being an object on the way through json_encode.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'version' => $this->version,
            'source' => $this->source,
            'seq' => $this->seq,
            'occurred_at' => $this->occurredAt,
            'tenant_id' => $this->tenantId,
            'outlet_id' => $this->outletId,
            'data' => $this->data === [] ? new stdClass() : $this->data,
        ];
    }
}
