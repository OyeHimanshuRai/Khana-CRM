<?php

namespace App\Events\Outbound;

use App\Models\Invoice;
use App\Models\OrderItem;
use App\Models\SalesReturn;
use App\Models\SalesReturnItem;
use App\Models\Shop;

/**
 * An accepted return, for crm.sale.returned.
 *
 * back_to_stock is the one judgement made here. The contract says CRM offers
 * it "for packaged goods only", and CRM's own word for that is a line that
 * never went through the kitchen: a cooked dish that comes back is not going
 * back on a shelf whatever condition the form recorded. So a line goes back
 * only when it was marked resalable AND the sale line it reverses settled no
 * kitchen line.
 *
 * Null when there is nothing ERP could act on: a return raised against no
 * invoice, or whose lines name no invoice line - the contract identifies a
 * returned line by the sale line it reverses, and has no other way to say it.
 */
final class SaleReturnPayload
{
    /**
     * @return array<string, mixed>|null
     */
    public function for(SalesReturn $return): ?array
    {
        $invoice = $return->invoice_id === null ? null : Invoice::allShops()->find($return->invoice_id);

        if ($invoice === null) {
            return null;
        }

        $items = $return->items()->with('invoiceItem:id,ulid,order_item_id')->orderBy('id')->get();

        $kitchenLines = OrderItem::query()
            ->whereIn('id', $items->pluck('invoiceItem.order_item_id')->filter()->unique())
            ->whereNotNull('kitchen_status')
            ->pluck('id')
            ->flip();

        $lines = $items
            ->filter(fn (SalesReturnItem $item) => $item->invoiceItem !== null && (float) $item->quantity > 0)
            ->map(fn (SalesReturnItem $item) => [
                'line_id' => PublicIds::of($item->invoiceItem),
                'qty' => Wire::quantity($item->quantity),
                'back_to_stock' => $item->isResalable() && ! isset($kitchenLines[$item->invoiceItem->order_item_id]),
            ])
            ->values()
            ->all();

        if ($lines === []) {
            return null;
        }

        return [
            'return_id' => PublicIds::of($return),
            'sale_id' => PublicIds::of($invoice),
            // The day the goods came back, which the return records as a
            // date already; the moment it was accepted only if it has none.
            'business_date' => $return->returned_on?->toDateString()
                ?? OutletClock::dateOf(Shop::withTrashed()->findOrFail($return->shop_id), $return->approved_at ?? now()),
            'lines' => $lines,
        ];
    }
}
