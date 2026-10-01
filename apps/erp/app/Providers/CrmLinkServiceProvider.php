<?php

namespace App\Providers;

use App\Events\StockMoved;
use App\Models\PaidOut;
use App\Models\SalesItem;
use App\Models\SupplierBill;
use App\Services\Availability;
use App\Services\Consumption;
use App\Services\MenuCopy;
use App\Services\Purchasing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Khana\Kit\Events\Envelope;
use Khana\Kit\Events\Inbox\Handlers;

/**
 * The link with CRM: what ERP does with each event CRM sends, and the
 * listener that sends sold-out and cost back.
 *
 * Each handler is a thin translation from the event's contract
 * (contracts/events/crm.*.json) to the service that does the work. The
 * kit's inbox has already checked the signature, dropped repeats, and made
 * sure the event is about an outlet of the business it names that owns ERP
 * - and it runs each handler in a transaction, in order, per outlet.
 */
class CrmLinkServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One per request (or per worker run): it collects what moved and
        // looks once, after the commit.
        $this->app->singleton(Availability::class);
    }

    public function boot(): void
    {
        Event::listen(StockMoved::class, fn (StockMoved $event) => $this->app->make(Availability::class)->stockMoved($event));

        $handlers = $this->app->make(Handlers::class);

        $handlers->on('crm.menu.item_upserted', 1, fn (Envelope $e) => app(MenuCopy::class)
            ->upsert($e->tenantId, $e->outletId, $e->data));

        $handlers->on('crm.menu.item_archived', 1, fn (Envelope $e) => app(MenuCopy::class)
            ->archive($e->outletId, $e->data['item_id']));

        $handlers->on('crm.kitchen.line_advanced', 1, fn (Envelope $e) => app(Consumption::class)
            ->kitchenAdvanced($e->tenantId, $e->outletId, $e->data, $e->id, Consumption::moment($e->occurredAt)));

        $handlers->on('crm.kitchen.line_cancelled', 1, fn (Envelope $e) => app(Consumption::class)
            ->kitchenCancelled($e->tenantId, $e->outletId, $e->data, $e->id, Consumption::moment($e->occurredAt)));

        $handlers->on('crm.sale.recorded', 1, fn (Envelope $e) => app(Consumption::class)
            ->saleRecorded($e->tenantId, $e->outletId, SalesItem::CRM, self::sale($e->data), $e->id, Consumption::moment($e->occurredAt)));

        $handlers->on('crm.sale.cancelled', 1, fn (Envelope $e) => app(Consumption::class)
            ->saleCancelled($e->outletId, SalesItem::CRM, $e->data['sale_id'], $e->data['reason'], $e->id, Consumption::moment($e->occurredAt)));

        $handlers->on('crm.sale.returned', 1, fn (Envelope $e) => app(Consumption::class)
            ->saleReturned($e->outletId, $e->data, $e->id, Consumption::moment($e->occurredAt)));

        $handlers->on('crm.cash.paid_out', 1, fn (Envelope $e) => self::paidOut($e));
    }

    /**
     * crm.sale.recorded, in the shape Consumption takes a sale from any
     * source.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function sale(array $data): array
    {
        return [
            'ref' => $data['sale_id'],
            'invoice_no' => $data['invoice_no'],
            'business_date' => $data['business_date'],
            'channel' => $data['channel'],
            'totals' => $data['totals'],
            'lines' => array_map(fn (array $line) => [
                'ref' => $line['line_id'],
                'item_ref' => $line['item_id'],
                'variant_ref' => $line['variant_id'],
                'option_refs' => $line['option_ids'],
                'name' => $line['name'],
                'qty' => $line['qty'],
                'net_paise' => $line['net_paise'],
                'tax_paise' => $line['tax_paise'],
                'kitchen_refs' => $line['order_line_ids'],
            ], $data['lines']),
        ];
    }

    /**
     * Cash left the till. It waits to be filed - unless the cashier named
     * the supplier bill it paid, in which case it is a payment already.
     */
    private static function paidOut(Envelope $event): void
    {
        $paidOut = PaidOut::allOutlets()->firstOrCreate(['ulid' => $event->data['paid_out_id']], [
            'tenant_id' => $event->tenantId,
            'outlet_id' => $event->outletId,
            'business_date' => $event->data['business_date'],
            'amount_paise' => $event->data['amount_paise'],
            'category' => $event->data['category'],
            'note' => $event->data['note'],
        ]);

        if (! $paidOut->wasRecentlyCreated || $event->data['supplier_bill_id'] === null) {
            return;
        }

        $bill = SupplierBill::allOutlets()->with('supplier')
            ->where('outlet_id', $event->outletId)->where('ulid', $event->data['supplier_bill_id'])->first();

        if ($bill !== null) {
            app(Purchasing::class)->filePaidOutAsPayment($paidOut, $bill->supplier, $bill->status === SupplierBill::OPEN ? $bill : null);
        }
    }
}
