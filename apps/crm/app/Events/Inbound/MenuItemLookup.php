<?php

namespace App\Events\Inbound;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Khana\Kit\Events\Envelope;
use Khana\Kit\Tenancy\TenantMismatch;

/**
 * The dish (and size) an erp.* event names, found by the ulids CRM gave them.
 *
 * ---------------------------------------------------------------------------
 * An id CRM does not know is skipped, not retried
 * ---------------------------------------------------------------------------
 *
 * RetryLater is for an event that is early - "an outlet this service has not
 * heard about yet", which the accounts.* events will fill in. A menu id is
 * never early here: CRM mints every item and size ulid, and ERP only knows
 * one because crm.menu.item_upserted told it. An id CRM cannot find is one it
 * deleted - a size taken off the form is deleted outright - and waiting will
 * not bring it back.
 *
 * Waiting would also cost far more than the one event. The inbox applies an
 * outlet's events in order and holds the rest behind one that waits, so a
 * stock-out for a deleted size would stop every later sold-out and cost
 * update for that outlet, indefinitely. Failing it outright would do the
 * same until somebody ran inbox:skip. So the handler logs a warning naming
 * the event and returns: the event is recorded as handled, the outlet's
 * stream carries on, and the log says what was passed over.
 *
 * An id that belongs to a different business than the event names is not a
 * stale id but a wrong one, and is refused (TenantMismatch) - the stream
 * stops until somebody looks, as the kit's ReferenceGuard does for outlets.
 */
final class MenuItemLookup
{
    /**
     * @return array{0: Product, 1: ProductVariant|null}|null  null when the event names something CRM no longer has
     *
     * @throws TenantMismatch
     */
    public function find(Envelope $event): ?array
    {
        $itemId = (string) ($event->data['item_id'] ?? '');
        $variantId = $event->data['variant_id'] ?? null;

        // Archived dishes are still found: a stock or cost signal for one is
        // kept, so it is right if the dish comes back.
        $product = Product::allTenants()->withTrashed()->where('ulid', $itemId)->first();

        if ($product === null) {
            return $this->skip($event, "no menu item {$itemId}");
        }

        $owner = DB::table('tenants')->where('id', $product->tenant_id)->value('ulid');

        if ($owner !== $event->tenantId) {
            throw new TenantMismatch("Event {$event->id} names menu item {$itemId} under business {$event->tenantId}; it belongs to {$owner}.");
        }

        if ($variantId === null) {
            return [$product, null];
        }

        $variant = ProductVariant::query()->where('product_id', $product->id)->where('ulid', (string) $variantId)->first();

        if ($variant === null) {
            return $this->skip($event, "no size {$variantId} on menu item {$itemId}");
        }

        return [$product, $variant];
    }

    private function skip(Envelope $event, string $why): null
    {
        Log::warning("Skipped {$event->type} {$event->id}: {$why} here.", [
            'event_id' => $event->id,
            'outlet_id' => $event->outletId,
        ]);

        return null;
    }
}
