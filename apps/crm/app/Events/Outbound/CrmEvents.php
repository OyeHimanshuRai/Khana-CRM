<?php

namespace App\Events\Outbound;

use App\Models\CashPaidOut;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\SalesReturn;
use App\Models\Shop;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Khana\Kit\Events\Outbox;
use Khana\Kit\Support\Ulid;

/**
 * Tells ERP what happened at the front of house, through the kit's outbox.
 *
 * The CRM twin of Accounts' App\Services\ReferenceEvents. Call these inside
 * the transaction that made the change: the event is written in the same
 * commit, so it exists exactly when the sale, the bump or the paid-out does,
 * and leaves after the commit in the order it was written. The outbox checks
 * each one against contracts/events in local and testing, so a payload that
 * drifts from its contract breaks the change that produced it.
 *
 * Every event names the business and the outlet by the ids Accounts knows
 * them by (tenants.ulid, shops.ulid). Whether ERP ever hears of it is the
 * kit's call, not this class's: the subscriber filter (OnlyEntitledOutlets)
 * sends an outlet's events only while that outlet owns ERP, so a CRM-only
 * restaurant records them and sends nothing.
 *
 * A shop with no ids - a row from before the link to Accounts, on an install
 * that has not run its migrations - is skipped with a warning rather than
 * failing the bill: the guest has paid, and the nightly check (the sales
 * digest) is where a missing sale is noticed.
 */
final class CrmEvents
{
    public function __construct(
        private readonly Outbox $outbox,
        private readonly SalePayload $sales,
        private readonly SaleReturnPayload $returns,
        private readonly KitchenLinePayload $kitchen,
        private readonly MenuItemPayload $menu,
        private readonly PaidOutPayload $paidOuts,
    ) {}

    /* ------------------------------------------------------------- sales */

    public function saleRecorded(Invoice $invoice): void
    {
        $this->record('crm.sale.recorded', $invoice->shop_id, fn () => $this->sales->for($invoice));
    }

    public function saleCancelled(Invoice $invoice, string $reason): void
    {
        $this->record('crm.sale.cancelled', $invoice->shop_id, fn () => [
            'sale_id' => PublicIds::of($invoice),
            'reason' => Wire::text($reason, 500) ?? 'Cancelled',
        ]);
    }

    public function saleReturned(SalesReturn $return): void
    {
        $this->record('crm.sale.returned', $return->shop_id, fn () => $this->returns->for($return));
    }

    /* ----------------------------------------------------------- kitchen */

    public function kitchenLineAdvanced(OrderItem $line, ?Order $order = null): void
    {
        $order ??= Order::allShops()->find($line->order_id);

        if ($order !== null) {
            $this->record('crm.kitchen.line_advanced', $order->shop_id, fn () => $this->kitchen->advanced($line, $order));
        }
    }

    public function kitchenLineCancelled(OrderItem $line, string $reason, ?Order $order = null): void
    {
        $order ??= Order::allShops()->find($line->order_id);

        if ($order !== null) {
            $this->record('crm.kitchen.line_cancelled', $order->shop_id, fn () => $this->kitchen->cancelled($line, $order, $reason));
        }
    }

    /* -------------------------------------------------------------- menu */

    /**
     * The whole item, once per outlet of its business - or only the outlets
     * named, when the change was one outlet's own (its price, its add-ons).
     *
     * @param  list<int>|null  $shopIds
     */
    public function menuItemUpserted(Product|int $product, ?array $shopIds = null): void
    {
        $product = $this->freshProduct($product);

        // Archived: crm.menu.item_archived has already said so, and an
        // upsert after it would read as the item coming back.
        if ($product === null || $product->trashed()) {
            return;
        }

        foreach ($this->outletsOf($product, $shopIds) as $shop) {
            $this->record('crm.menu.item_upserted', $shop, fn () => $this->menu->for($product, $shop));
        }
    }

    public function menuItemArchived(Product $product): void
    {
        foreach ($this->outletsOf($product) as $shop) {
            $this->record('crm.menu.item_archived', $shop, fn () => ['item_id' => PublicIds::of($product)]);
        }
    }

    /* -------------------------------------------------------------- cash */

    public function cashPaidOut(CashPaidOut $paidOut): void
    {
        $this->record('crm.cash.paid_out', $paidOut->shop_id, fn () => $this->paidOuts->for($paidOut));
    }

    /* --------------------------------------------------------- internals */

    /**
     * @param  callable(): (array<string, mixed>|null)  $data  null when the change has nothing ERP could act on
     */
    private function record(string $type, Shop|int|null $shop, callable $data): void
    {
        $shop = $shop instanceof Shop || $shop === null ? $shop : Shop::withTrashed()->find($shop);
        $tenantId = $shop?->tenant_id === null ? null : DB::table('tenants')->where('id', $shop->tenant_id)->value('ulid');

        if (! Ulid::isValid($shop?->ulid) || ! Ulid::isValid($tenantId)) {
            Log::warning("Not sending {$type}: the outlet has no Accounts ids yet.", ['shop_id' => $shop?->id]);

            return;
        }

        $payload = $data();

        if ($payload === null) {
            Log::info("Not sending {$type}: nothing in it ERP could act on.", ['shop_id' => $shop->id]);

            return;
        }

        $this->outbox->record($type, $payload, $tenantId, $shop->ulid);
    }

    private function freshProduct(Product|int $product): ?Product
    {
        $id = $product instanceof Product ? $product->id : $product;

        return Product::allTenants()->withTrashed()->find($id);
    }

    /**
     * The outlets a dish is on the menu of: every live outlet of its
     * business, narrowed to those named.
     *
     * @param  list<int>|null  $only
     * @return iterable<Shop>
     */
    private function outletsOf(Product $product, ?array $only = null): iterable
    {
        if ($product->tenant_id === null) {
            return [];
        }

        return Shop::query()
            ->where('tenant_id', $product->tenant_id)
            ->when($only !== null, fn ($q) => $q->whereIn('id', $only))
            ->orderBy('id')
            ->get();
    }
}
