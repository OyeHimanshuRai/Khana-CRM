<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\GoodsReceipt;
use App\Models\Payment;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Models\Shop;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierLedger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Sending goods back to a supplier.
 *
 * The mirror of SalesReturnService, on the other side of the counter.
 * Approving a return does three things at once, and either all of them
 * happen or none do:
 *
 *   the goods leave the shelf, at the cost they arrived at
 *   the receipt line records how much of it has now gone back
 *   the supplier is credited, refunded, or neither
 *
 * The cost the goods arrived at, not today's price, is deliberate for the
 * same reason it is on the sales side: unwinding a purchase at a different
 * figure than it was booked at would quietly change a month already
 * reported.
 */
class PurchaseReturnService
{
    public function __construct(
        private readonly StockService $stock,
        private readonly SupplierLedgerService $ledger,
    ) {}

    /**
     * Accept a return: ship the goods, book the credit.
     */
    public function approve(PurchaseReturn $return, ?string $note = null): PurchaseReturn
    {
        if ($return->isAccepted()) {
            throw new RuntimeException('That return has already been accepted.');
        }

        if (! $return->isEditable()) {
            throw new RuntimeException('That return has already been '.strtolower($return->statusLabel()).'.');
        }

        $items = $return->items()->with(['product', 'batch', 'goodsReceiptItem'])->get();

        if ($items->isEmpty()) {
            throw new RuntimeException('There is nothing to accept — the return has no lines.');
        }

        return DB::transaction(function () use ($return, $items, $note) {
            $shop = Shop::withTrashed()->findOrFail($return->shop_id);
            $warehouse = $return->warehouse;

            $this->settleTotals($return, $items);

            foreach ($items as $item) {
                $this->ship($return, $item, $shop, $warehouse);
                $this->markReceiptLine($item);
            }

            $this->settleSupplier($return, $shop);

            $user = Auth::user();

            $return->forceFill([
                'status' => PurchaseReturn::APPROVED,
                'approved_by' => $user?->id,
                'approved_by_name' => $user?->name ?? 'System',
                'approved_at' => now(),
                'review_note' => $note ?: $return->review_note,
            ])->save();

            ActivityLog::record(
                'purchase_return.approved',
                sprintf('Accepted return %s to %s (₹%s)',
                    $return->reference,
                    $return->supplier_name ?: 'a supplier',
                    number_format((float) $return->grand_total, 2),
                ),
                $return,
            );

            return $return->refresh();
        });
    }

    /** Turn a return down without moving anything. */
    public function reject(PurchaseReturn $return, string $reason): PurchaseReturn
    {
        if (! $return->isEditable()) {
            throw new RuntimeException('That return has already been '.strtolower($return->statusLabel()).'.');
        }

        if (trim($reason) === '') {
            throw new RuntimeException('Refusing a return needs a reason.');
        }

        $user = Auth::user();

        $return->forceFill([
            'status' => PurchaseReturn::REJECTED,
            'approved_by' => $user?->id,
            'approved_by_name' => $user?->name ?? 'System',
            'approved_at' => now(),
            'review_note' => $reason,
        ])->save();

        ActivityLog::record(
            'purchase_return.rejected',
            "Refused return {$return->reference}: {$reason}",
            $return,
        );

        return $return;
    }

    /* ------------------------------------------------------------ totals */

    /**
     * Add the return up from its lines.
     *
     * @param  \Illuminate\Support\Collection<int, PurchaseReturnItem>  $items
     */
    private function settleTotals(PurchaseReturn $return, $items): void
    {
        $subtotal = 0.0;
        $tax = 0.0;

        foreach ($items as $item) {
            $taxable = (float) $item->quantity * (float) $item->unit_cost;
            $lineTax = $taxable * (float) $item->tax_rate / 100;

            $item->forceFill([
                'taxable_value' => round($taxable, 2),
                'tax_amount' => round($lineTax, 2),
                'line_total' => round($taxable + $lineTax, 2),
            ])->save();

            $subtotal += $taxable;
            $tax += $lineTax;
        }

        $return->forceFill([
            'subtotal' => round($subtotal, 2),
            'tax_total' => round($tax, 2),
            'grand_total' => round($subtotal + $tax, 2),
        ])->save();
    }

    /* ------------------------------------------------------------- stock */

    /** Take one line's goods off the shelf and send them back. */
    private function ship(PurchaseReturn $return, PurchaseReturnItem $item, Shop $shop, $warehouse): void
    {
        if ($item->product === null || $warehouse === null) {
            return;
        }

        $quantity = (float) $item->quantity;

        if ($quantity <= 0) {
            return;
        }

        $this->stock->issue(
            $item->product,
            $quantity,
            $warehouse,
            $item->batch,
            StockMovement::PURCHASE_RETURN,
            $return,
            'Returned on '.$return->reference,
            $shop->id,
        );
    }

    /**
     * Tell the receipt line how much of it has gone back.
     *
     * Kept on the line rather than recomputed from the returns, so "can this
     * still be sent back" is one read when building the next one.
     */
    private function markReceiptLine(PurchaseReturnItem $item): void
    {
        $line = $item->goodsReceiptItem;

        if ($line === null) {
            return;
        }

        $line->forceFill([
            'returned_quantity' => (float) $line->returned_quantity + (float) $item->quantity,
        ])->save();
    }

    /* ---------------------------------------------------------- supplier */

    /** Credit, refund, or neither. */
    private function settleSupplier(PurchaseReturn $return, Shop $shop): void
    {
        $amount = (float) $return->grand_total;
        $supplier = $return->supplier;

        if ($amount <= 0.004 || $return->settlement === PurchaseReturn::NONE || $supplier === null) {
            return;
        }

        // What the shop owes them drops either way - the return happened,
        // and the ledger should say so regardless of how it was settled.
        $entry = $this->ledger->settle(
            $supplier,
            $amount,
            'Return '.$return->reference,
            SupplierLedger::PURCHASE_RETURN,
            $return,
            $return->returned_on,
        );

        if ($return->settlement === PurchaseReturn::REFUND) {
            $this->refund($return, $shop, $amount);

            // Cash came back rather than a lower bill, so the balance must
            // not actually move - the reversal is what keeps the statement
            // honest about why.
            $this->ledger->reverse($supplier, $entry, 'Refunded against '.$return->reference);

            return;
        }

        // Credited against the account: take it off the receipt it came from.
        $this->reduceReceiptDue($return, $amount);
    }

    /** Take cash back from the supplier. */
    private function refund(PurchaseReturn $return, Shop $shop, float $amount): void
    {
        $payment = Payment::query()->create([
            'shop_id' => $shop->id,
            'number' => Payment::nextNumber($shop, Payment::IN),
            'direction' => Payment::IN,
            'method' => Payment::CASH,
            'party_type' => Supplier::class,
            'party_id' => $return->supplier_id,
            'party_name' => $return->supplier_name ?: 'Supplier',
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
     * Take the credit off the receipt it came from.
     *
     * Only the outstanding part - crediting a receipt already paid in full
     * would leave it showing a negative balance, when what the shop
     * actually has is an advance on the account, which the ledger entry
     * above already records.
     */
    private function reduceReceiptDue(PurchaseReturn $return, float $amount): void
    {
        $receipt = $return->goodsReceipt;

        if ($receipt === null || (float) $receipt->due_total <= 0.004) {
            return;
        }

        $applied = min($amount, (float) $receipt->due_total);
        $due = round((float) $receipt->due_total - $applied, 2);

        $receipt->forceFill(['due_total' => $due])->save();
    }
}
