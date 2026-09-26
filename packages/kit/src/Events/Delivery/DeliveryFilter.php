<?php

namespace Khana\Kit\Events\Delivery;

use Khana\Kit\Events\OutboxEvent;

/**
 * Narrows what one receiver is sent beyond its event types - for example,
 * CRM sends ERP only the events of outlets that also own ERP.
 */
interface DeliveryFilter
{
    public function accepts(string $subscriber, OutboxEvent $event): bool;
}
