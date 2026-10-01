<?php

namespace App\Events;

/**
 * An item's level changed at an outlet.
 *
 * Raised by StockService for every movement. Whoever cares what stock can
 * now cover - the sold-out signal to CRM, most of all - listens for this
 * rather than being called by each of the dozen things that move stock.
 */
final class StockMoved
{
    public function __construct(
        public readonly string $outletId,
        public readonly int $stockItemId,
    ) {}
}
