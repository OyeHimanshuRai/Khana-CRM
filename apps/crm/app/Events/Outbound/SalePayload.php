<?php

namespace App\Events\Outbound;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ModifierOption;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;

/**
 * One invoice as ERP hears of it: the `data` of crm.sale.recorded, and the
 * body of GET /api/v1/sales/{id} before the status is added.
 *
 * Every figure is the one InvoiceService wrote - taxable value after every
 * discount, the tax as split, the rupee round-off - turned into paise. None
 * is worked out again here, so the event and the printed bill cannot
 * disagree.
 *
 * Two things a line says that matter to ERP more than to anybody else:
 *
 *   order_line_ids   the kitchen lines this line settles. ERP has already
 *                    consumed those dishes from crm.kitchen.line_advanced;
 *                    a line that names none (a bottle from the counter) is
 *                    what ERP consumes from the sale itself.
 *   option_ids       the add-ons, read off the kitchen line, since that is
 *                    where CRM records which were chosen.
 */
final class SalePayload
{
    /**
     * @return array<string, mixed>
     */
    public function for(Invoice $invoice): array
    {
        $shop = Shop::withTrashed()->findOrFail($invoice->shop_id);
        $items = $invoice->items()->orderBy('id')->get();

        return [
            'sale_id' => PublicIds::of($invoice),
            'invoice_no' => Wire::text($invoice->number, 50) ?? (string) $invoice->id,
            'business_date' => OutletClock::dateOf($shop, $invoice->invoiced_at ?? $invoice->created_at),
            'channel' => $this->channel($invoice),
            'lines' => $this->lines($items->all()),
            'totals' => [
                'net_paise' => Wire::nonNegativePaise($invoice->subtotal),
                'tax_paise' => Wire::nonNegativePaise($invoice->tax_total),
                'round_off_paise' => max(-50, min(50, Wire::paise($invoice->round_off))),
                'grand_paise' => Wire::nonNegativePaise($invoice->grand_total),
            ],
        ];
    }

    /**
     * Where the sale was made, in the contract's words (defs.json channel).
     *
     * A bill off a table is dine-in whatever till printed it. An online
     * invoice was raised for an order, and the order says how it left the
     * building. The counter is the counter; a back-office invoice is "other".
     */
    public function channel(Invoice $invoice): string
    {
        if ($invoice->table_session_id !== null) {
            return 'dine_in';
        }

        if ($invoice->channel === Invoice::ONLINE) {
            // Found through the bill's own lines: OrderService::markPaid()
            // links the order to the invoice only after the invoice - and
            // this event - exist. The order's invoice_id is the fallback for
            // a bill raised before its lines carried the link.
            $type = Order::allShops()
                ->whereIn('id', OrderItem::query()
                    ->whereIn('id', $invoice->items()->whereNotNull('order_item_id')->select('order_item_id'))
                    ->select('order_id'))
                ->value('order_type')
                ?? Order::allShops()->where('invoice_id', $invoice->id)->value('order_type');

            return match ($type) {
                Order::DINE_IN => 'dine_in',
                Order::TAKEAWAY => 'takeaway',
                Order::DELIVERY => 'delivery',
                default => 'online',
            };
        }

        return $invoice->channel === Invoice::POS ? 'counter' : 'other';
    }

    /**
     * @param  list<InvoiceItem>  $items
     * @return list<array<string, mixed>>
     */
    private function lines(array $items): array
    {
        $ids = fn (string $column) => collect($items)->pluck($column)->filter()->unique()->values()->all();

        // Straight off the tables, so an archived dish or one outside the
        // signed-in person's context still has its id - see MenuItemPayload.
        $products = PublicIds::map(Product::class, $ids('product_id'));
        $variants = PublicIds::map(ProductVariant::class, $ids('product_variant_id'));
        $kitchen = OrderItem::query()->with('modifiers:id,order_item_id,modifier_option_id')
            ->whereIn('id', $ids('order_item_id'))
            ->get(['id', 'ulid', 'kitchen_status'])
            ->keyBy('id');
        $options = PublicIds::map(
            ModifierOption::class,
            $kitchen->flatMap(fn (OrderItem $line) => $line->modifiers->pluck('modifier_option_id')),
        );

        return array_map(function (InvoiceItem $item) use ($products, $variants, $kitchen, $options) {
            /** @var OrderItem|null $line */
            $line = $kitchen->get($item->order_item_id);

            return [
                'line_id' => PublicIds::of($item),
                'item_id' => $products[$item->product_id] ?? null,
                'variant_id' => $variants[$item->product_variant_id] ?? null,
                'option_ids' => Wire::ulids($line?->modifiers->map(fn ($m) => $options[$m->modifier_option_id] ?? null) ?? []),
                'name' => Wire::name($item->product_name) ?? 'Item',
                'qty' => Wire::quantity($item->quantity),
                'net_paise' => Wire::nonNegativePaise($item->taxable_value),
                'tax_paise' => Wire::nonNegativePaise($item->cgst_amount)
                    + Wire::nonNegativePaise($item->sgst_amount)
                    + Wire::nonNegativePaise($item->igst_amount)
                    + Wire::nonNegativePaise($item->cess_amount),
                // Only a line the kitchen cooked: a web order's line carries
                // the link too, but nothing was ever bumped for it.
                'order_line_ids' => $line !== null && $line->isKitchenLine() ? [PublicIds::of($line)] : [],
            ];
        }, $items);
    }
}
