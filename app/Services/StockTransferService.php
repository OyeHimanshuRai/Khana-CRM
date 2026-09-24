<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Moving a transfer document through its lifecycle.
 *
 *   approve    a decision, no stock moves
 *   dispatch   stock leaves the sending warehouse
 *   receive    stock arrives in the receiving one
 *
 * Dispatch and receipt are separate because the goods really are in neither
 * warehouse while they are on the van, and a system that pretends otherwise
 * will not reconcile against a physical count taken that afternoon.
 *
 * The gap is visible: everything dispatched and not yet received is in
 * transit, and inTransitValue() is what a stock report should show for it.
 */
class StockTransferService
{
    public function __construct(private readonly StockService $stock) {}

    public function approve(StockTransfer $transfer, ?string $note = null): StockTransfer
    {
        if (! in_array($transfer->status, [StockTransfer::DRAFT, StockTransfer::PENDING], true)) {
            throw new RuntimeException('That transfer has already been '.$transfer->statusLabel().'.');
        }

        if ($transfer->items()->count() === 0) {
            throw new RuntimeException('There is nothing to approve — the transfer has no lines.');
        }

        $user = Auth::user();

        $transfer->forceFill([
            'status' => StockTransfer::APPROVED,
            'approved_by' => $user?->id,
            'approved_by_name' => $user?->name ?? 'System',
            'approved_at' => now(),
            'review_note' => $note ?: $transfer->review_note,
        ])->save();

        ActivityLog::record(
            'stock_transfer.approved',
            "Approved stock transfer {$transfer->reference}",
            $transfer,
        );

        return $transfer;
    }

    /**
     * Take the stock out of the sending warehouse.
     *
     * This is where a transfer can fail for a real-world reason - the stock
     * is not there any more - so it happens in one transaction and leaves
     * the document approved rather than half-dispatched.
     */
    public function dispatch(StockTransfer $transfer): StockTransfer
    {
        if ($transfer->status !== StockTransfer::APPROVED) {
            throw new RuntimeException('Only an approved transfer can be dispatched.');
        }

        $items = $transfer->items()->with(['product', 'batch'])->get();

        return DB::transaction(function () use ($transfer, $items) {
            $quantity = 0.0;
            $value = 0.0;

            foreach ($items as $item) {
                /** @var StockTransferItem $item */
                $slot = $this->stock->issue(
                    $item->product,
                    (float) $item->quantity,
                    $transfer->fromWarehouse,
                    $item->batch,
                    StockMovement::TRANSFER_OUT,
                    $transfer,
                    'To '.$transfer->toWarehouse?->name.' · '.$transfer->reference,
                    $transfer->shop_id,
                );

                // Carry the sending shop's cost so the receiving shop values
                // the goods at what they actually cost, not at list price.
                $item->forceFill(['unit_cost' => (float) $slot->average_cost])->save();

                $quantity += (float) $item->quantity;
                $value += (float) $item->quantity * (float) $slot->average_cost;
            }

            $transfer->forceFill([
                'status' => StockTransfer::DISPATCHED,
                'dispatched_at' => now(),
                'total_quantity' => $quantity,
                'total_value' => $value,
            ])->save();

            ActivityLog::record(
                'stock_transfer.dispatched',
                "Dispatched stock transfer {$transfer->reference}",
                $transfer,
                ['quantity' => $quantity, 'value' => $value],
            );

            return $transfer->refresh();
        });
    }

    /**
     * Book the consignment into the receiving warehouse.
     *
     * @param  array<int, float>  $receivedByItemId  what actually turned up,
     *                                               keyed by transfer item id;
     *                                               omit an id to accept the
     *                                               dispatched quantity.
     */
    public function receive(StockTransfer $transfer, array $receivedByItemId = [], ?string $note = null): StockTransfer
    {
        if ($transfer->status !== StockTransfer::DISPATCHED) {
            throw new RuntimeException('Only a transfer in transit can be received.');
        }

        $items = $transfer->items()->with(['product', 'batch'])->get();

        return DB::transaction(function () use ($transfer, $items, $receivedByItemId, $note) {
            $shortfalls = [];

            foreach ($items as $item) {
                /** @var StockTransferItem $item */
                $received = array_key_exists($item->id, $receivedByItemId)
                    ? (float) $receivedByItemId[$item->id]
                    : (float) $item->quantity;

                $received = max(0.0, min($received, (float) $item->quantity));

                if ($received > 0) {
                    $this->stock->receive(
                        $item->product,
                        $received,
                        $transfer->toWarehouse,
                        $this->batchForReceiver($transfer, $item),
                        (float) $item->unit_cost,
                        StockMovement::TRANSFER_IN,
                        $transfer,
                        'From '.$transfer->fromWarehouse?->name.' · '.$transfer->reference,
                        $transfer->to_shop_id,
                    );
                }

                $item->forceFill(['received_quantity' => $received])->save();

                $short = (float) $item->quantity - $received;

                if ($short > 0.0005) {
                    $shortfalls[] = $item->product->name.' short by '
                        .rtrim(rtrim(number_format($short, 3, '.', ''), '0'), '.');
                }
            }

            $user = Auth::user();

            $transfer->forceFill([
                'status' => StockTransfer::RECEIVED,
                'received_by' => $user?->id,
                'received_by_name' => $user?->name ?? 'System',
                'received_at' => now(),
                'review_note' => $note ?: $transfer->review_note,
            ])->save();

            /*
             | A short receipt is logged loudly. The difference is stock that
             | left one warehouse and arrived in neither - which is either a
             | miscount or a loss, and both need somebody to look.
             */
            ActivityLog::record(
                $shortfalls === [] ? 'stock_transfer.received' : 'stock_transfer.received_short',
                $shortfalls === []
                    ? "Received stock transfer {$transfer->reference} in full"
                    : "Received stock transfer {$transfer->reference} short: ".implode('; ', $shortfalls),
                $transfer,
                ['shortfalls' => $shortfalls],
            );

            return $transfer->refresh();
        });
    }

    public function reject(StockTransfer $transfer, ?string $note = null): StockTransfer
    {
        if (! in_array($transfer->status, [StockTransfer::DRAFT, StockTransfer::PENDING], true)) {
            throw new RuntimeException('Only a transfer awaiting approval can be rejected.');
        }

        $user = Auth::user();

        $transfer->forceFill([
            'status' => StockTransfer::REJECTED,
            'approved_by' => $user?->id,
            'approved_by_name' => $user?->name ?? 'System',
            'approved_at' => now(),
            'review_note' => $note,
        ])->save();

        ActivityLog::record(
            'stock_transfer.rejected',
            "Rejected stock transfer {$transfer->reference}",
            $transfer,
        );

        return $transfer;
    }

    /**
     * Withdraw a transfer.
     *
     * Refused once dispatched: the stock has physically left, and pretending
     * it never did would leave the sending warehouse long by the amount on
     * the van. A dispatched transfer has to be received, short if need be.
     */
    public function cancel(StockTransfer $transfer): StockTransfer
    {
        if (in_array($transfer->status, [StockTransfer::DISPATCHED, StockTransfer::RECEIVED], true)) {
            throw new RuntimeException(
                'This transfer has already left the warehouse. Receive it — short if necessary — rather than cancelling.'
            );
        }

        $transfer->forceFill(['status' => StockTransfer::CANCELLED])->save();

        ActivityLog::record(
            'stock_transfer.cancelled',
            "Cancelled stock transfer {$transfer->reference}",
            $transfer,
        );

        return $transfer;
    }

    /**
     * The lot the receiving shop should book this line into.
     *
     * Within one shop it is the same batch row. Across shops the receiving
     * shop needs its own row with the same number and dates, because a batch
     * belongs to exactly one tenant.
     */
    private function batchForReceiver(StockTransfer $transfer, StockTransferItem $item)
    {
        if ($item->batch === null || ! $transfer->isInterShop()) {
            return $item->batch;
        }

        return \App\Models\Batch::allShops()->firstOrCreate(
            [
                'shop_id' => $transfer->to_shop_id,
                'product_id' => $item->product_id,
                'batch_no' => $item->batch->batch_no,
            ],
            [
                'mfg_date' => $item->batch->mfg_date,
                'expiry_date' => $item->batch->expiry_date,
                'purchase_price' => $item->unit_cost,
                'mrp' => $item->batch->mrp,
                'selling_price' => $item->batch->selling_price,
                'supplier_batch_ref' => $item->batch->supplier_batch_ref,
                'is_active' => true,
            ]
        );
    }
}
