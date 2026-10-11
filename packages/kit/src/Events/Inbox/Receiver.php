<?php

namespace Khana\Kit\Events\Inbox;

use Carbon\CarbonImmutable;
use Khana\Kit\Events\Envelope;
use Khana\Kit\Events\InvalidEnvelope;
use Khana\Kit\Events\Signature;

/**
 * Takes a signed batch off the wire and stores it, once.
 *
 * Storing is all it does: applying is the Processor's job, later and in
 * order. A receiver that answered only after applying would hold the
 * sender's worker for as long as the slowest handler, and one broken event
 * would stop every outlet's delivery rather than just its own.
 *
 * Refusals are for batches nobody should ever accept - an unknown sender, a
 * bad signature, a malformed envelope - and the sender keeps retrying them,
 * loudly, until someone fixes the cause.
 */
final class Receiver
{
    public const MAX_BATCH = 500;

    /**
     * @return array{received: int, stored: int, duplicates: int}
     *
     * @throws ReceiveRefused
     */
    public function receive(string $source, ?string $signature, string $body): array
    {
        $secret = config('khana.events.senders.'.$source);

        if ($source === '' || ! is_string($secret) || $secret === '') {
            throw new ReceiveRefused(401, 'Unknown sender.');
        }

        if (! Signature::verify($signature, $body, $secret, (int) config('khana.events.tolerance', 300))) {
            throw new ReceiveRefused(401, 'Signature missing, wrong or too old.');
        }

        $payload = json_decode($body, true);

        if (! is_array($payload) || ! isset($payload['events']) || ! is_array($payload['events']) || ! array_is_list($payload['events'])) {
            throw new ReceiveRefused(400, 'Expected {"events": [...]}.');
        }

        if (count($payload['events']) > self::MAX_BATCH) {
            throw new ReceiveRefused(413, 'At most '.self::MAX_BATCH.' events per batch.');
        }

        $rows = [];

        foreach ($payload['events'] as $index => $raw) {
            try {
                $event = Envelope::fromArray($raw);
            } catch (InvalidEnvelope $e) {
                throw new ReceiveRefused(422, "Event {$index}: ".$e->getMessage());
            }

            // A sender speaks for itself only.
            if ($event->source !== $source) {
                throw new ReceiveRefused(422, "Event {$index} claims to come from {$event->source}, but the batch was signed by {$source}.");
            }

            $rows[] = [
                'event_id' => $event->id,
                'source' => $event->source,
                'seq' => $event->seq,
                'type' => $event->type,
                'version' => $event->version,
                'tenant_id' => $event->tenantId,
                'outlet_id' => $event->outletId,
                'stream' => InboxEvent::streamFor($event->source, $event->tenantId, $event->outletId),
                'occurred_at' => CarbonImmutable::parse($event->occurredAt)->utc()->format('Y-m-d H:i:s'),
                'data' => json_encode((object) $event->data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'status' => InboxEvent::PENDING,
                'received_at' => now()->utc()->format('Y-m-d H:i:s'),
            ];
        }

        // The unique event_id is what makes a repeat harmless - an index,
        // not a "have I seen this" query that two requests could both pass.
        $stored = $rows === [] ? 0 : InboxEvent::query()->insertOrIgnore($rows);

        return [
            'received' => count($rows),
            'stored' => $stored,
            'duplicates' => count($rows) - $stored,
        ];
    }
}
