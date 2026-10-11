<?php

namespace Khana\Kit\Tenancy;

use Khana\Kit\Events\Delivery\DeliveryFilter;
use Khana\Kit\Events\OutboxEvent;

/**
 * Send a product only the events of outlets that own it.
 *
 * CRM tells ERP about an outlet's sales only while that outlet holds ERP -
 * and the other way round for sold-out signals. A CRM-only restaurant
 * therefore never has its sales sent anywhere, and nothing in CRM waits on
 * ERP to exist. Events that belong to no outlet always go.
 *
 * The subscriber's name is the product: "erp" or "crm".
 */
final class OnlyEntitledOutlets implements DeliveryFilter
{
    public function __construct(private readonly Entitlements $entitlements) {}

    public function accepts(string $subscriber, OutboxEvent $event): bool
    {
        if ($event->outlet_id === null) {
            return true;
        }

        return $this->entitlements->owns($event->outlet_id, $subscriber);
    }
}
