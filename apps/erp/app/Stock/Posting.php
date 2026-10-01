<?php

namespace App\Stock;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Why stock is moving: what every ledger row written for one piece of work
 * has in common.
 *
 * A goods receipt, a count, one line of one event - each makes a Posting and
 * hands it to StockService with every movement it asks for. The posting
 * stamps the type, the document, the day and, for work done from an event,
 * the event's id and line. It also numbers the movements of that line, which
 * is what the ledger's unique index needs to refuse the same event twice.
 */
final class Posting
{
    private int $part = 0;

    public function __construct(
        public readonly string $type,
        public readonly ?Model $reference = null,
        public readonly ?string $reason = null,
        public readonly ?string $eventId = null,
        public readonly ?string $eventLine = null,
        public readonly ?string $businessDate = null,
        public readonly ?CarbonInterface $occurredAt = null,
        public readonly ?int $userId = null,
    ) {}

    /** The same piece of work, booking a different kind of movement. */
    public function as(string $type, ?string $reason = null): self
    {
        $copy = new self(
            $type, $this->reference, $reason ?? $this->reason, $this->eventId, $this->eventLine,
            $this->businessDate, $this->occurredAt, $this->userId,
        );
        $copy->part = &$this->part;

        return $copy;
    }

    /** Null for work nobody will ever replay; 1, 2, 3... within an event line. */
    public function nextPart(): ?int
    {
        return $this->eventId === null ? null : ++$this->part;
    }
}
