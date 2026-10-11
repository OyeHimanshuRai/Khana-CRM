<?php

namespace App\Services;

use App\Events\Outbound\CrmEvents;
use App\Models\ActivityLog;
use App\Models\CustomerLedger;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\SalesReturn;
use App\Models\SalesReturnItem;
use App\Models\Shop;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Accepting goods back.
 *
 * Approving a return does three things at once, and either all of them
 * happen or none do:
 *
 *   the invoice line records how much has now come back
 *   the customer is credited, refunded, or neither
 *   the return is signed off
 *
 * There is no shelf to put resalable goods back on and no stock to write off
 * as damaged - CRM tracks none. What condition the goods came back in is
 * still recorded on the line (see SalesReturnItem::isResalable()); it just
 * no longer moves anything.
 */
class SalesReturnService
{
    private readonly CrmEvents $events;

    /*
     | The events recorder is optional only so the older tests that build
     | this service by hand keep working; the container always passes it.
     */
    public function __construct(
        private readonly LedgerService $ledger,
        ?CrmEvents $events = null,
    ) {
        $this->events = $events ?? app(CrmEvents::class);
    }

    /**
     * Accept a return.
     */
    public function approve(SalesReturn $return, ?string $note = null): SalesReturn
    {
        if ($return->isAccepted()) {
            throw new RuntimeException('That return has already been accepted.');
        }

        if (! $return->isEditable()) {
            throw new RuntimeException('That return has already been '.strtolower($return->statusLabel()).'.');
        }

        $items = $return->items()->with(['product', 'invoiceItem'])->get();

        if ($items->isEmpty()) {
            throw new RuntimeException('There is nothing to accept — the return has no lines.');
        }

        return DB::transaction(function () use ($return, $items, $note) {
            $shop = Shop::withTrashed()->findOrFail($return->shop_id);

            $this->settleTotals($return, $items);

            foreach ($items as $item) {
                $this->markInvoiceLine($item);
            }

            $this->settleCustomer($return, $shop);

            $user = Auth::user();

            $return->forceFill([
                'status' => SalesReturn::APPROVED,
                'approved_by' => $user?->id,
                'approved_by_name' => $user?->name ?? 'System',
                'approved_at' => now(),
                'review_note' => $note ?: $return->review_note,
            ])->save();

            ActivityLog::record(
                'sales_return.approved',
                sprintf('Accepted return %s for %s (₹%s)',
                    $return->reference,
                    $return->customer_name ?: 'a walk-in',
                    number_format((float) $return->grand_total, 2),
                ),
                $return,
                ['written_off' => $return->writtenOffValue()],
            );

            // crm.sale.returned: ERP puts back only the packaged goods that
            // came back resalable - see App\Events\Outbound\SaleReturnPayload.
            $this->events->saleReturned($return);

            return $return->refresh();
        });
    }

    /** Turn a return down without moving anything. */
    public function reject(SalesReturn $return, string $reason): SalesReturn
    {
        if (! $return->isEditable()) {
            throw new RuntimeException('That return has already been '.strtolower($return->statusLabel()).'.');
        }

        if (trim($reason) === '') {
            throw new RuntimeException('Refusing a return needs a reason — the customer will ask.');
        }

        $user = Auth::user();

        $return->forceFill([
            'status' => SalesReturn::REJECTED,
            'approved_by' => $user?->id,
            'approved_by_name' => $user?->name ?? 'System',
            'approved_at' => now(),
            'review_note' => $reason,
        ])->save();

        ActivityLog::record(
            'sales_return.rejected',
            "Refused return {$return->reference}: {$reason}",
            $return,
        );

        return $return;
    }

    /* ------------------------------------------------------------ totals */

    /**
     * Add the return up from its lines.
     *
     * @param  \Illuminate\Support\Collection<int, SalesReturnItem>  $items
     */
    private function settleTotals(SalesReturn $return, $items): void
    {
        $subtotal = 0.0;
        $tax = 0.0;
        $cost = 0.0;

        foreach ($items as $item) {
            $taxable = (float) $item->quantity * (float) $item->unit_price;
            $lineTax = $taxable * (float) $item->tax_rate / 100;

            $item->forceFill([
                'taxable_value' => round($taxable, 2),
                'tax_amount' => round($lineTax, 2),
                'line_total' => round($taxable + $lineTax, 2),
            ])->save();

            $subtotal += $taxable;
            $tax += $lineTax;
            $cost += (float) $item->quantity * (float) $item->unit_cost;
        }

        $return->forceFill([
            'subtotal' => round($subtotal, 2),
            'tax_total' => round($tax, 2),
            'grand_total' => round($subtotal + $tax, 2),
            'cost_total' => round($cost, 4),
        ])->save();
    }

    /**
     * Tell the invoice line how much of it has come back.
     *
     * Kept on the line rather than recomputed from the returns, so "can this
     * be returned again" is one read at the counter.
     */
    private function markInvoiceLine(SalesReturnItem $item): void
    {
        $line = $item->invoiceItem;

        if ($line === null) {
            return;
        }

        $line->forceFill([
            'returned_quantity' => (float) $line->returned_quantity + (float) $item->quantity,
        ])->save();
    }

    /* ---------------------------------------------------------- customer */

    /**
     * Credit, refund, or neither.
     */
    private function settleCustomer(SalesReturn $return, Shop $shop): void
    {
        $amount = (float) $return->grand_total;
        $customer = $return->customer;

        if ($amount <= 0.004 || $return->settlement === SalesReturn::NONE) {
            return;
        }

        if ($customer === null) {
            /*
             | A walk-in with no account can only be given money back. If the
             | shop chose "credit" there is nothing to credit it to, so the
             | settlement is corrected rather than silently doing nothing.
             */
            $return->forceFill(['settlement' => SalesReturn::REFUND])->save();
            $this->refund($return, $shop, $amount);

            return;
        }

        if ($return->settlement === SalesReturn::REFUND) {
            $this->refund($return, $shop, $amount);

            // A refund is money out; the ledger entry keeps the statement
            // honest about why the balance did not move.
            $this->ledger->credit(
                $customer,
                $amount,
                CustomerLedger::SALE_RETURN,
                'Return '.$return->reference,
                $return,
                $return->returned_on,
            );

            $this->ledger->debit(
                $customer,
                $amount,
                CustomerLedger::REFUND,
                'Refunded against '.$return->reference,
                $return,
                $return->returned_on,
            );

            return;
        }

        // Credited to the account: it comes off what they owe.
        $this->ledger->credit(
            $customer,
            $amount,
            CustomerLedger::SALE_RETURN,
            'Return '.$return->reference,
            $return,
            $return->returned_on,
        );

        $this->reduceInvoiceDue($return, $amount);
    }

    /**
     * Hand money back.
     */
    private function refund(SalesReturn $return, Shop $shop, float $amount): void
    {
        $payment = Payment::query()->create([
            'shop_id' => $shop->id,
            'number' => Payment::nextNumber($shop, Payment::OUT),
            'direction' => Payment::OUT,
            'method' => Payment::CASH,
            'party_type' => $return->customer ? $return->customer::class : null,
            'party_id' => $return->customer_id,
            'party_name' => $return->customer_name ?: 'Cash customer',
            'reference_type' => $return::class,
            'reference_id' => $return->id,
            'amount' => round($amount, 2),
            'paid_at' => now(),
            'status' => Payment::CLEARED,
            'notes' => 'Refund for return '.$return->reference,
        ]);

        $user = Auth::user();

        $payment->forceFill([
            'created_by' => $user?->id,
            'created_by_name' => $user?->name ?? 'System',
        ])->save();

        $return->forceFill(['refund_amount' => round($amount, 2)])->save();
    }

    /**
     * Take the credit off the invoice it came from.
     *
     * Only the outstanding part: crediting an invoice that has already been
     * paid in full would leave it showing a negative balance, when what the
     * customer actually has is an advance on their account - which the
     * ledger entry above already records.
     */
    private function reduceInvoiceDue(SalesReturn $return, float $amount): void
    {
        $invoice = $return->invoice;

        if ($invoice === null || (float) $invoice->due_total <= 0.004) {
            return;
        }

        $applied = min($amount, (float) $invoice->due_total);
        $due = round((float) $invoice->due_total - $applied, 2);

        $invoice->forceFill([
            'due_total' => $due,
            'status' => $due <= 0.004 ? Invoice::PAID : $invoice->status,
        ])->save();
    }
}
