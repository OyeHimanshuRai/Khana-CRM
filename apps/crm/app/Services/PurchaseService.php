<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Batch;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\PurchaseOrderItem;
use App\Models\Shop;
use App\Models\StockMovement;
use App\Models\SupplierLedger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Receiving goods.
 *
 * Posting a receipt is the single moment that:
 *
 *   creates the batches the lots will be sold from
 *   puts the stock on the shelf, at its landed cost
 *   bills the supplier's account
 *   updates the product's price if the consignment changed it
 *   moves the purchase order along
 *
 * All of it in one transaction. A half-posted receipt - stock in with no
 * bill, or a bill with no stock - is the kind of error that is only found
 * at stock-take, months later.
 *
 * Landed cost is the point of the exercise. Freight and loading are real
 * money the shop spent to get the goods onto the shelf, so they are spread
 * across the lines by value and folded into the cost each lot is carried at.
 * Valuing stock at the invoice line alone quietly overstates every margin
 * the shop reports.
 */
class PurchaseService
{
    public function __construct(
        private readonly StockService $stock,
        private readonly SupplierLedgerService $ledger,
    ) {}

    /**
     * Post a draft receipt: stock in, supplier billed.
     */
    public function post(GoodsReceipt $receipt): GoodsReceipt
    {
        if ($receipt->status === GoodsReceipt::POSTED) {
            throw new RuntimeException('That receipt has already been posted.');
        }

        if ($receipt->status === GoodsReceipt::CANCELLED) {
            throw new RuntimeException('That receipt was cancelled and cannot be posted.');
        }

        $items = $receipt->items()->with('product.unit')->get();

        if ($items->isEmpty()) {
            throw new RuntimeException('There is nothing to receive — the receipt has no lines.');
        }

        return DB::transaction(function () use ($receipt, $items) {
            $shop = Shop::withTrashed()->findOrFail($receipt->shop_id);
            $warehouse = $receipt->warehouse;

            $this->settleTotals($receipt, $items);
            $this->spreadCharges($receipt, $items);

            foreach ($items as $item) {
                $this->receiveLine($receipt, $item, $shop, $warehouse);
            }

            $this->billSupplier($receipt);
            $this->advancePurchaseOrder($receipt, $items);

            $user = Auth::user();

            $receipt->forceFill([
                'status' => GoodsReceipt::POSTED,
                'posted_by' => $user?->id,
                'posted_by_name' => $user?->name ?? 'System',
                'posted_at' => now(),
            ])->save();

            ActivityLog::record(
                'goods_receipt.posted',
                sprintf('Received %s from %s (₹%s)',
                    $receipt->reference,
                    $receipt->supplier?->displayName() ?? 'a supplier',
                    number_format((float) $receipt->grand_total, 2),
                ),
                $receipt,
            );

            return $receipt->refresh();
        });
    }

    /* ------------------------------------------------------------ totals */

    /**
     * Add the receipt up from its lines.
     *
     * @param  \Illuminate\Support\Collection<int, GoodsReceiptItem>  $items
     */
    private function settleTotals(GoodsReceipt $receipt, $items): void
    {
        $interState = (bool) $receipt->is_inter_state;

        $subtotal = 0.0;
        $discount = 0.0;
        $cgst = 0.0;
        $sgst = 0.0;
        $igst = 0.0;

        foreach ($items as $item) {
            $gross = (float) $item->quantity * (float) $item->unit_cost;

            $lineDiscount = (float) $item->discount_amount > 0
                ? (float) $item->discount_amount
                : $gross * (float) $item->discount_percent / 100;

            $lineDiscount = min(max($lineDiscount, 0), $gross);
            $taxable = $gross - $lineDiscount;

            $rate = (float) $item->tax_rate;
            $tax = $taxable * $rate / 100;

            // Which way the tax splits was decided when the receipt was
            // raised, from the supplier's state - and stored, so a later
            // address edit cannot restate a filed purchase.
            $lineCgst = $interState ? 0.0 : $tax / 2;
            $lineSgst = $interState ? 0.0 : $tax / 2;
            $lineIgst = $interState ? $tax : 0.0;

            $item->forceFill([
                'discount_amount' => round($lineDiscount, 2),
                'taxable_value' => round($taxable, 2),
                'cgst_amount' => round($lineCgst, 2),
                'sgst_amount' => round($lineSgst, 2),
                'igst_amount' => round($lineIgst, 2),
                'line_total' => round($taxable + $tax, 2),
            ])->save();

            $subtotal += $taxable;
            $discount += $lineDiscount;
            $cgst += $lineCgst;
            $sgst += $lineSgst;
            $igst += $lineIgst;
        }

        $tax = round($cgst + $sgst + $igst, 2);
        $charges = (float) $receipt->other_charges;

        $raw = round($subtotal + $tax + $charges, 2);
        $grand = round($raw);

        $receipt->forceFill([
            'subtotal' => round($subtotal, 2),
            'discount_total' => round($discount, 2),
            'cgst_total' => round($cgst, 2),
            'sgst_total' => round($sgst, 2),
            'igst_total' => round($igst, 2),
            'tax_total' => $tax,
            'round_off' => round($grand - $raw, 2),
            'grand_total' => $grand,
            'due_total' => round($grand - (float) $receipt->paid_total, 2),
        ])->save();
    }

    /**
     * Spread freight and other charges across the lines, by value.
     *
     * By value rather than by quantity: a consignment of one expensive
     * machine and a thousand cheap sachets did not incur its freight evenly
     * per unit, and costing it that way would make the sachets look
     * ruinous.
     *
     * @param  \Illuminate\Support\Collection<int, GoodsReceiptItem>  $items
     */
    private function spreadCharges(GoodsReceipt $receipt, $items): void
    {
        $charges = (float) $receipt->other_charges;
        $subtotal = (float) $receipt->subtotal;

        foreach ($items as $item) {
            $received = $item->totalQuantity();

            if ($received <= 0) {
                $item->forceFill(['landed_cost' => 0])->save();

                continue;
            }

            $share = ($charges > 0 && $subtotal > 0)
                ? $charges * ((float) $item->taxable_value / $subtotal)
                : 0.0;

            /*
             | Divided by everything that went on the shelf, free units
             | included. Free stock is real stock: counting it here is what
             | makes the weighted average reflect what the shop actually paid
             | per unit it can sell.
             */
            $landed = ((float) $item->taxable_value + $share) / $received;

            $item->forceFill(['landed_cost' => round($landed, 4)])->save();
        }
    }

    /* -------------------------------------------------------------- lines */

    /**
     * Put one line on the shelf.
     */
    private function receiveLine(GoodsReceipt $receipt, GoodsReceiptItem $item, Shop $shop, $warehouse): void
    {
        $product = $item->product;

        if ($product === null) {
            throw new RuntimeException("A line on {$receipt->reference} points at a product that no longer exists.");
        }

        $batch = $this->batchFor($receipt, $item, $product, $shop);

        if ($batch) {
            $item->forceFill(['batch_id' => $batch->id])->save();
        }

        $quantity = $item->totalQuantity();

        if ($quantity <= 0) {
            return;
        }

        $this->stock->receive(
            $product,
            $quantity,
            $warehouse,
            $batch,
            (float) $item->landed_cost,
            StockMovement::PURCHASE,
            $receipt,
            'Receipt '.$receipt->reference
                .($receipt->bill_number ? ' · bill '.$receipt->bill_number : ''),
            $shop->id,
        );

        $this->updateProductPricing($product, $item, $shop);
    }

    /**
     * Find or create the lot this line belongs to.
     *
     * Only for batch-tracked products, and only when a number was captured.
     * A batch-tracked product received without one is a real problem - it
     * can never be sold FEFO - so it is refused rather than silently
     * received into a nameless pool.
     */
    private function batchFor(GoodsReceipt $receipt, GoodsReceiptItem $item, Product $product, Shop $shop): ?Batch
    {
        if (! $product->track_batches) {
            return null;
        }

        if (blank($item->batch_no)) {
            throw new RuntimeException(sprintf(
                '"%s" is batch tracked, so its line needs a batch number before %s can be posted.',
                $product->name,
                $receipt->reference,
            ));
        }

        $batch = Batch::allShops()->firstOrNew([
            'shop_id' => $shop->id,
            'product_id' => $product->id,
            'batch_no' => $item->batch_no,
        ]);

        /*
         | A lot seen before keeps its dates unless this consignment supplies
         | them - a second delivery of the same batch number should not blank
         | out an expiry date the first one recorded.
         */
        // Coalesced to 0 rather than left null: a brand-new Batch has no
        // attribute yet, and the columns are NOT NULL.
        $batch->fill([
            'mfg_date' => $item->mfg_date ?? $batch->mfg_date,
            'expiry_date' => $item->expiry_date ?? $batch->expiry_date,
            'purchase_price' => (float) $item->landed_cost ?: (float) ($batch->purchase_price ?? 0),
            'mrp' => (float) $item->mrp ?: (float) ($batch->mrp ?? 0),
            'selling_price' => (float) $item->selling_price ?: (float) ($batch->selling_price ?? 0),
            'is_active' => true,
        ]);

        $batch->shop_id = $shop->id;
        $batch->product_id = $product->id;
        $batch->batch_no = $item->batch_no;
        $batch->save();

        return $batch;
    }

    /**
     * Carry a price change from the consignment onto the product.
     *
     * A price rise almost always arrives with the goods, and re-typing it
     * on the product screen afterwards is the step everyone forgets. Only
     * written when the line actually carries a figure, so a receipt that
     * leaves them blank changes nothing.
     */
    private function updateProductPricing(Product $product, GoodsReceiptItem $item, Shop $shop): void
    {
        $changes = [];

        if ((float) $item->mrp > 0 && (float) $item->mrp !== (float) $product->mrp) {
            $changes['mrp'] = (float) $item->mrp;
        }

        if ((float) $item->selling_price > 0
            && (float) $item->selling_price !== (float) $product->selling_price) {
            $changes['selling_price'] = (float) $item->selling_price;
        }

        if ((float) $item->landed_cost > 0) {
            $changes['purchase_price'] = (float) $item->landed_cost;
        }

        if ($changes === []) {
            return;
        }

        $product->forceFill($changes)->save();

        ActivityLog::record(
            'product.repriced_on_receipt',
            sprintf('Updated "%s" pricing from a goods receipt', $product->name),
            $product,
            $changes,
        );
    }

    /* ------------------------------------------------------------ ledger */

    /**
     * Put the bill on the supplier's account.
     */
    private function billSupplier(GoodsReceipt $receipt): void
    {
        $supplier = $receipt->supplier;
        $amount = (float) $receipt->grand_total;

        if ($supplier === null || $amount <= 0.004) {
            return;
        }

        // Fall back to the supplier's agreed terms when the bill carries no
        // date of its own, which is most of the time.
        if ($receipt->due_date === null) {
            $days = (int) ($supplier->credit_days ?? 0);

            $receipt->forceFill([
                'due_date' => $receipt->received_on->copy()->addDays($days),
            ])->save();
        }

        $this->ledger->bill(
            $supplier,
            $amount,
            'Bill '.($receipt->bill_number ?: $receipt->reference),
            $receipt,
            $receipt->received_on,
        );

        if ((float) $receipt->paid_total > 0.004) {
            $this->ledger->settle(
                $supplier,
                (float) $receipt->paid_total,
                'Paid against '.($receipt->bill_number ?: $receipt->reference),
                SupplierLedger::PAYMENT,
                $receipt,
                $receipt->received_on,
            );
        }
    }

    /**
     * Tick the ordered quantities off the purchase order.
     *
     * @param  \Illuminate\Support\Collection<int, GoodsReceiptItem>  $items
     */
    private function advancePurchaseOrder(GoodsReceipt $receipt, $items): void
    {
        $order = $receipt->purchaseOrder;

        if ($order === null) {
            return;
        }

        foreach ($items as $item) {
            $line = PurchaseOrderItem::query()
                ->where('purchase_order_id', $order->id)
                ->where('product_id', $item->product_id)
                ->first();

            if ($line === null) {
                // Received something that was not ordered. Allowed - it
                // happens - and the receipt records it; the order simply has
                // no line to tick off.
                continue;
            }

            $line->forceFill([
                'received_quantity' => (float) $line->received_quantity + $item->totalQuantity(),
            ])->save();
        }

        $order->refreshReceiptStatus();
    }

    /* ------------------------------------------------------------ cancel */

    /**
     * Reverse a posted receipt.
     *
     * Refused once any of the stock has been sold on: taking back units that
     * are no longer there would drive the shelf negative and make every
     * later count wrong. The right move then is a purchase return, which is
     * a document about goods going back rather than a claim the delivery
     * never happened.
     */
    public function cancel(GoodsReceipt $receipt, string $reason): GoodsReceipt
    {
        if ($receipt->status !== GoodsReceipt::POSTED) {
            throw new RuntimeException('Only a posted receipt can be cancelled.');
        }

        if (trim($reason) === '') {
            throw new RuntimeException('Cancelling a receipt needs a reason.');
        }

        $items = $receipt->items()->with(['product', 'batch'])->get();

        return DB::transaction(function () use ($receipt, $items, $reason) {
            $shop = Shop::withTrashed()->findOrFail($receipt->shop_id);
            $warehouse = $receipt->warehouse;

            foreach ($items as $item) {
                if ($item->product === null) {
                    continue;
                }

                $quantity = $item->totalQuantity();

                if ($quantity <= 0) {
                    continue;
                }

                // issue() refuses to go negative unless the shop allows it,
                // which is exactly the guard wanted here: it surfaces "the
                // stock has been sold" as a clear refusal.
                $this->stock->issue(
                    $item->product,
                    $quantity,
                    $warehouse,
                    $item->batch,
                    StockMovement::PURCHASE_RETURN,
                    $receipt,
                    'Cancelled receipt '.$receipt->reference,
                    $shop->id,
                );
            }

            $supplier = $receipt->supplier;

            if ($supplier) {
                $net = (float) $receipt->grand_total - (float) $receipt->paid_total;

                if (abs($net) > 0.004) {
                    $this->ledger->settle(
                        $supplier,
                        $net,
                        'Cancelled '.($receipt->bill_number ?: $receipt->reference).' — '.$reason,
                        SupplierLedger::ADJUSTMENT,
                        $receipt,
                    );
                }
            }

            $receipt->payments()->where('status', '!=', Payment::CANCELLED)
                ->each(fn (Payment $payment) => $payment->forceFill([
                    'status' => Payment::CANCELLED,
                    'notes' => trim(($payment->notes ? $payment->notes."\n" : '')
                        .'Voided with receipt '.$receipt->reference),
                ])->save());

            $user = Auth::user();

            $receipt->forceFill([
                'status' => GoodsReceipt::CANCELLED,
                'due_total' => 0,
                'cancelled_by' => $user?->id,
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
            ])->save();

            ActivityLog::record(
                'goods_receipt.cancelled',
                "Cancelled goods receipt {$receipt->reference}: {$reason}",
                $receipt,
            );

            return $receipt->refresh();
        });
    }
}
