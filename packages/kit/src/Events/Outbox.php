<?php

namespace Khana\Kit\Events;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Khana\Kit\Contracts\ContractValidator;
use Khana\Kit\Events\Jobs\DeliverOutbox;
use Khana\Kit\Support\Ulid;
use RuntimeException;

/**
 * Where a service writes down what happened, for the others to hear about.
 *
 * Call it inside the same transaction as the change:
 *
 *   DB::transaction(function () use ($outbox) {
 *       $invoice = ...;
 *       $outbox->record('crm.sale.recorded', [...], $tenantId, $outletId);
 *   });
 *
 * If the change rolls back, the event never existed. Delivery is queued for
 * after the commit, and nothing here waits for another service to answer.
 */
final class Outbox
{
    public function __construct(private readonly ContractValidator $contracts) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function record(
        string $type,
        array $data,
        ?string $tenantId = null,
        ?string $outletId = null,
        int $version = 1,
        ?CarbonInterface $occurredAt = null,
    ): OutboxEvent {
        $occurredAt = CarbonImmutable::instance($occurredAt ?? now())->utc()->startOfSecond();

        $envelope = new Envelope(
            id: Ulid::generate(),
            type: $type,
            version: $version,
            source: self::source(),
            // Not numbered until the sequencer sees it committed. The
            // placeholder only exists so the envelope can be checked now.
            seq: 1,
            occurredAt: $occurredAt->format('Y-m-d\TH:i:s\Z'),
            tenantId: $tenantId,
            outletId: $outletId,
            data: $data,
        );

        if ($this->validates()) {
            $this->contracts->assertValid($envelope->toArray());
        }

        $event = OutboxEvent::query()->create([
            'event_id' => $envelope->id,
            'type' => $type,
            'version' => $version,
            'tenant_id' => $tenantId,
            'outlet_id' => $outletId,
            'occurred_at' => $occurredAt,
            'data' => $data,
        ]);

        DeliverOutbox::dispatch()->afterCommit();

        return $event;
    }

    public static function source(): string
    {
        $service = config('khana.service');

        if (! is_string($service) || preg_match(Envelope::SOURCE_PATTERN, $service) !== 1) {
            throw new RuntimeException('Set KHANA_SERVICE (accounts, crm or erp) before recording events.');
        }

        return $service;
    }

    private function validates(): bool
    {
        $setting = config('khana.events.validate');

        if ($setting === null || $setting === '') {
            return app()->environment(['local', 'testing']);
        }

        return filter_var($setting, FILTER_VALIDATE_BOOL);
    }
}
