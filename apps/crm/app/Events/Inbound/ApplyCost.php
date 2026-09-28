<?php

namespace App\Events\Inbound;

use App\Models\Shop;
use Illuminate\Support\Facades\DB;
use Khana\Kit\Events\Envelope;
use Khana\Kit\Events\Inbox\Handler;

/**
 * erp.cost.changed: what a portion now costs to make, from ERP's weighted
 * average of the stock on hand.
 *
 * Written where CRM already reads a dish's cost, so every bill from here on
 * books it (InvoiceService::costOf, and from there the gross-profit
 * reports):
 *
 *   a size           product_variants.purchase_price - a Half and a Full do
 *                    not cost the same, and a bill line now names its size.
 *   the whole dish   the purchase price this outlet reads: its own override
 *                    on product_shop when it has one (otherwise ERP's figure
 *                    would sit behind an override nobody sees), the dish's
 *                    purchase_price when it does not.
 *
 * food_cost_percent is not kept: CRM works a margin out from price and cost
 * when it needs one, and a stored percentage would go stale the moment the
 * price changed.
 *
 * Like sold-out, the dish-level and size-level costs are per business, not
 * per outlet, except where an outlet keeps its own override.
 */
final class ApplyCost implements Handler
{
    public function __construct(private readonly MenuItemLookup $lookup) {}

    public function handle(Envelope $event): void
    {
        $found = $this->lookup->find($event);

        if ($found === null) {
            return;
        }

        [$product, $variant] = $found;
        $cost = self::rupees((int) $event->data['cost_paise']);

        if ($variant !== null) {
            $variant->forceFill(['purchase_price' => $cost])->save();

            return;
        }

        $shopId = Shop::query()
            ->where('tenant_id', $product->tenant_id)
            ->where('ulid', $event->outletId)
            ->value('id');

        $override = $shopId === null ? null : DB::table('product_shop')
            ->where('product_id', $product->id)
            ->where('shop_id', $shopId)
            ->whereNotNull('purchase_price');

        if ($override !== null && $override->exists()) {
            $override->update(['purchase_price' => $cost, 'updated_at' => now()]);

            return;
        }

        $product->forceFill(['purchase_price' => $cost])->save();
    }

    /** 8125 paise as "81.25", exactly - no float on the way. */
    private static function rupees(int $paise): string
    {
        return intdiv($paise, 100).'.'.str_pad((string) ($paise % 100), 2, '0', STR_PAD_LEFT);
    }
}
