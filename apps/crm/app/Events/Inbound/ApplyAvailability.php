<?php

namespace App\Events\Inbound;

use App\Models\Shop;
use App\Support\StockOuts;
use Illuminate\Support\Facades\Log;
use Khana\Kit\Events\Envelope;
use Khana\Kit\Events\Inbox\Handler;

/**
 * erp.availability.changed: a dish, or one size of it, ran out or came back
 * at one outlet, judged from that outlet's stock.
 *
 * ---------------------------------------------------------------------------
 * One outlet's truth
 * ---------------------------------------------------------------------------
 *
 * The event is about the outlet it names and no other. It used to be written
 * onto products.is_sold_out and product_variants.is_available, which belong
 * to the whole business, so one outlet running out of paneer took the dish
 * off every outlet's menu. It is now a row for that outlet alone
 * (App\Support\StockOuts): written when stock runs out, removed when it
 * comes back.
 *
 * The two columns are left as the switches staff throw by hand. The POS, the
 * QR menu, the cart and the API refuse a dish for either reason - see
 * Product::isSoldOut() - and staff can still put a dish back against what
 * stock says, as the contract allows.
 *
 * None of it is in crm.menu.item_upserted, and the row is not written
 * through a model, so this never echoes back to ERP as a menu change.
 *
 * ---------------------------------------------------------------------------
 * An outlet CRM has no shop for is passed over
 * ---------------------------------------------------------------------------
 *
 * The kit has already checked the outlet against the copy of Accounts by the
 * time this runs. But CRM makes its own row for an outlet when somebody
 * first signs in to it, so an outlet Accounts knows can still be one CRM has
 * no shop for - and there is then no menu to take the dish off. It is logged
 * and passed over rather than left waiting, for the reason MenuItemLookup
 * gives: one event that waits holds every later one for that outlet. The
 * nightly check puts the outlet right once it exists here.
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

        // Within the dish's own business: an outlet id is only ever looked
        // for among the outlets that could sell the dish.
        $shopId = Shop::query()
            ->where('tenant_id', $product->tenant_id)
            ->where('ulid', $event->outletId)
            ->value('id');

        if ($shopId === null) {
            Log::warning("Skipped {$event->type} {$event->id}: no outlet {$event->outletId} here.", [
                'event_id' => $event->id,
                'outlet_id' => $event->outletId,
            ]);

            return;
        }

        StockOuts::mark((int) $shopId, (int) $product->id, $variant?->id, (bool) $event->data['available']);
    }
}
