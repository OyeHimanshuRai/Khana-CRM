<?php

namespace App\Events\Inbound;

use Khana\Kit\Events\Envelope;
use Khana\Kit\Events\Inbox\Handler;

/**
 * erp.availability.changed: a dish, or one size of it, ran out or came back,
 * judged from ERP's stock.
 *
 * Written onto the same flags staff throw by hand, so the POS, the QR menu,
 * the cart and the API all refuse it the way they already refuse a dish the
 * kitchen marked sold out - and staff can still override it, as the
 * contract says:
 *
 *   the whole dish   products.is_sold_out, with sold_out_until cleared: ERP
 *                    will say when it is back, so no timer should bring it
 *                    back first.
 *   one size         product_variants.is_available.
 *
 * Neither column is in crm.menu.item_upserted, so this never echoes back to
 * ERP as a menu change.
 *
 * CRM keeps sold-out per dish, not per outlet: in a business with several
 * outlets, one outlet running out takes the dish off every outlet's menu
 * until ERP says it is back. That is how CRM's own sold-out switch has
 * always worked; a per-outlet flag would be a change to the menu itself.
 */
final class ApplyAvailability implements Handler
{
    public function __construct(private readonly MenuItemLookup $lookup) {}

    public function handle(Envelope $event): void
    {
        $found = $this->lookup->find($event);

        if ($found === null) {
            return;
        }

        [$product, $variant] = $found;
        $available = (bool) $event->data['available'];

        if ($variant !== null) {
            $variant->forceFill(['is_available' => $available])->save();

            return;
        }

        $product->forceFill([
            'is_sold_out' => ! $available,
            'sold_out_until' => null,
        ])->save();
    }
}
