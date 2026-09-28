<?php

namespace App\Events\Outbound;

use App\Models\ModifierOption;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;

/**
 * One kitchen line, for crm.kitchen.line_advanced and line_cancelled.
 *
 * Both carry the whole dish - item, size, add-ons, quantity - so ERP can
 * consume or reverse a line it has never seen before, which is exactly what
 * happens to the first bump after an outlet switches ERP on.
 *
 * Null when the line names no dish any more: the contract's item_id is
 * required, and a line whose product row was deleted outright has nothing
 * honest to put there. CRM archives dishes rather than deleting them, so
 * this is a guard, not a path anybody walks.
 */
final class KitchenLinePayload
{
    /**
     * @return array<string, mixed>|null
     */
    public function advanced(OrderItem $line, Order $order): ?array
    {
        $common = $this->common($line, $order);
        $stage = Wire::stage($line->kitchen_status);

        if ($common === null || $stage === null || $stage === 'placed') {
            return null;
        }

        return $common + ['stage' => $stage];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function cancelled(OrderItem $line, Order $order, string $reason): ?array
    {
        $common = $this->common($line, $order);
        $stage = Wire::stage($line->kitchen_status);

        if ($common === null || $stage === null) {
            return null;
        }

        return $common + [
            'stage_reached' => $stage,
            'reason' => Wire::text($reason, 500) ?? 'Cancelled',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function common(OrderItem $line, Order $order): ?array
    {
        $item = PublicIds::map(Product::class, [$line->product_id])->first();

        if ($item === null) {
            return null;
        }

        // In the order they were chosen, as the sale line lists them too.
        $optionIds = $line->modifiers()->pluck('modifier_option_id')->filter()->all();
        $options = PublicIds::map(ModifierOption::class, $optionIds);

        return [
            'order_id' => PublicIds::of($order),
            'line_id' => PublicIds::of($line),
            'item_id' => $item,
            'variant_id' => PublicIds::map(ProductVariant::class, [$line->product_variant_id])->first(),
            'option_ids' => Wire::ulids(array_map(fn ($id) => $options[$id] ?? null, $optionIds)),
            'qty' => Wire::quantity($line->quantity),
        ];
    }
}
