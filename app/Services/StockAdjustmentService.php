<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentItem;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Turning an approved stock adjustment into real movements.
 *
 * Applying is the only moment a document touches the shelf, and it is
 * deliberately one-way: an approved adjustment is never re-applied or
 * un-applied. A mistake is corrected by raising another adjustment, which
 * is what keeps the ledger a history rather than a current opinion.
 */
class StockAdjustmentService
{
    public function __construct(private readonly StockService $stock) {}

    /**
     * Approve a document and move the stock it describes.
     *
     * Runs in one transaction with the movements, so a line that cannot be
     * applied - a batch deleted since the count, say - leaves the document
     * pending rather than half-applied.
     */
    public function approve(StockAdjustment $adjustment, ?string $note = null): StockAdjustment
    {
        if ($adjustment->status === StockAdjustment::APPROVED) {
            throw new RuntimeException('That adjustment has already been applied.');
        }

        if ($adjustment->isDecided()) {
            throw new RuntimeException('That adjustment has already been '.$adjustment->statusLabel().'.');
        }

        $items = $adjustment->items()->with(['product', 'batch'])->get();

        if ($items->isEmpty()) {
            throw new RuntimeException('There is nothing to apply — the adjustment has no lines.');
        }

        return DB::transaction(function () use ($adjustment, $items, $note) {
            $warehouse = $adjustment->warehouse;
            $in = 0.0;
            $out = 0.0;
            $value = 0.0;

            foreach ($items as $item) {
                /** @var StockAdjustmentItem $item */
                $difference = (float) $item->difference;

                if (abs($difference) < 0.0005) {
                    continue;
                }

                /*
                 | adjustTo rather than receive/issue: the line records what
                 | was counted, and counting to a number is not the same as
                 | adding a difference to whatever is there now. If the shelf
                 | moved between the count and the approval, the count is
                 | still the truth being asserted.
                 */
                $slot = $this->stock->adjustTo(
                    $item->product,
                    (float) $item->counted_quantity,
                    $adjustment->reasonLabel().' · '.$adjustment->reference,
                    $warehouse,
                    $item->batch,
                    $adjustment,
                    $adjustment->shop_id,
                    (float) $item->unit_cost,
                );

                /*
                 | The line was costed when it was written, which is before
                 | the slot may have had any cost at all - opening stock is
                 | the usual case. StockService has just settled on one, so
                 | copy it back: the document's value has to agree with what
                 | the ledger recorded, not with what the form guessed.
                 */
                if ((float) $item->unit_cost <= 0 && (float) $slot->average_cost > 0) {
                    $item->forceFill(['unit_cost' => (float) $slot->average_cost])->save();
                }

                $difference > 0 ? $in += $difference : $out += abs($difference);
                $value += $item->valueChange();
            }

            $user = Auth::user();

            $adjustment->forceFill([
                'status' => StockAdjustment::APPROVED,
                'total_in' => $in,
                'total_out' => $out,
                'value_change' => $value,
                'approved_by' => $user?->id,
                'approved_by_name' => $user?->name ?? 'System',
                'approved_at' => now(),
                'review_note' => $note ?: $adjustment->review_note,
            ])->save();

            ActivityLog::record(
                'stock_adjustment.approved',
                sprintf(
                    'Applied stock adjustment %s (+%s / -%s)',
                    $adjustment->reference,
                    rtrim(rtrim(number_format($in, 3, '.', ''), '0'), '.') ?: '0',
                    rtrim(rtrim(number_format($out, 3, '.', ''), '0'), '.') ?: '0',
                ),
                $adjustment,
                ['value_change' => $value],
            );

            return $adjustment->refresh();
        });
    }

    /** Turn a document down without touching stock. */
    public function reject(StockAdjustment $adjustment, ?string $note = null): StockAdjustment
    {
        if ($adjustment->isDecided()) {
            throw new RuntimeException('That adjustment has already been '.$adjustment->statusLabel().'.');
        }

        $user = Auth::user();

        $adjustment->forceFill([
            'status' => StockAdjustment::REJECTED,
            'approved_by' => $user?->id,
            'approved_by_name' => $user?->name ?? 'System',
            'approved_at' => now(),
            'review_note' => $note,
        ])->save();

        ActivityLog::record(
            'stock_adjustment.rejected',
            "Rejected stock adjustment {$adjustment->reference}",
            $adjustment,
        );

        return $adjustment;
    }

    /** Withdraw a document the raiser no longer wants reviewed. */
    public function cancel(StockAdjustment $adjustment): StockAdjustment
    {
        if ($adjustment->status === StockAdjustment::APPROVED) {
            throw new RuntimeException(
                'An applied adjustment cannot be cancelled. Raise a correcting adjustment instead.'
            );
        }

        $adjustment->forceFill(['status' => StockAdjustment::CANCELLED])->save();

        ActivityLog::record(
            'stock_adjustment.cancelled',
            "Cancelled stock adjustment {$adjustment->reference}",
            $adjustment,
        );

        return $adjustment;
    }
}
