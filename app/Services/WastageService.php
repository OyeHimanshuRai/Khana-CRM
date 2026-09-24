<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\StockMovement;
use App\Models\StockWastage;
use App\Models\Warehouse;
use App\Support\CurrentShop;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Recording what a kitchen threw away (§10).
 *
 * One row, one movement, immediately - see the migration for why this is a log
 * rather than an approved document.
 *
 * The number this exists to produce is the value: a restaurant's food cost can
 * be wrong by ten percent through waste alone, and unrecorded waste is
 * indistinguishable from theft, from over-portioning and from a recipe that is
 * simply wrong. Once it is written down, those become three different
 * conversations.
 */
class WastageService
{
    public function __construct(private readonly StockService $stock) {}

    /**
     * Write off some stock.
     *
     * @param  array{
     *     product: Product,
     *     quantity: float,
     *     reason_code: string,
     *     note?: string|null,
     *     warehouse?: Warehouse|null,
     *     wasted_at?: \DateTimeInterface|string|null,
     * }  $data
     */
    public function record(array $data): StockWastage
    {
        /** @var Product $product */
        $product = $data['product'];
        $quantity = round((float) $data['quantity'], 4);

        if ($quantity <= 0) {
            throw new RuntimeException('Say how much was thrown away.');
        }

        $reason = (string) ($data['reason_code'] ?? 'other');

        if (! array_key_exists($reason, StockWastage::REASONS)) {
            throw new RuntimeException('That is not a reason this system records.');
        }

        /*
         | A made-to-order dish has no count to take away from.
         |
         | Refused rather than quietly ignored: somebody writing off two
         | portions of Butter Naan means the flour and butter that went into
         | them, and silently recording nothing would leave them believing
         | their store had been corrected. They want the ingredients - which
         | is a different, and honest, entry.
         */
        if ($product->is_made_to_order) {
            throw new RuntimeException(sprintf(
                '"%s" is made to order, so there is no stock of it to write off. '
                .'Record the ingredients that went into it instead.',
                $product->name,
            ));
        }

        $shopId = CurrentShop::idForWrite();

        if ($shopId === null) {
            throw new RuntimeException('Choose a single shop first — stock is thrown away in one kitchen.');
        }

        $warehouse = $data['warehouse'] ?? Warehouse::defaultFor($shopId);

        if ($warehouse === null) {
            throw new RuntimeException('This shop has no store to write stock off from.');
        }

        return DB::transaction(function () use ($product, $quantity, $reason, $data, $shopId, $warehouse) {
            /*
             | The cost of the stock actually leaving, read before it goes.
             |
             | The slot's average cost rather than the product's purchase
             | price: this is a record of the past, and what matters is what
             | the shop paid for the specific stock it is throwing away. The
             | recipe screen asks the opposite - a planning question - and
             | uses the purchase price for exactly that reason.
             */
            $unitCost = (float) (ProductStock::allShops()
                ->where('shop_id', $shopId)
                ->where('warehouse_id', $warehouse->id)
                ->where('product_id', $product->id)
                ->value('average_cost') ?? 0);

            if ($unitCost <= 0) {
                $unitCost = (float) $product->purchasePriceFor($shopId);
            }

            $user = Auth::user();

            $wastage = StockWastage::query()->create([
                'shop_id' => $shopId,
                'warehouse_id' => $warehouse->id,
                'product_id' => $product->id,
                'quantity' => $quantity,
                'reason_code' => $reason,
                'note' => $data['note'] ?? null,
                'unit_cost' => $unitCost,
                'cost_value' => round($unitCost * $quantity, 2),
                'recorded_by' => $user?->id,
                'recorded_by_name' => $user?->name ?? 'System',
                'wasted_at' => $data['wasted_at'] ?? now(),
            ]);

            /*
             | The movement carries the wastage row as its reference, so the
             | stock ledger's "why is there 3 kg less than yesterday" leads
             | straight back to the note somebody typed.
             */
            $this->stock->issue(
                $product,
                $quantity,
                $warehouse,
                null,
                StockMovement::WASTAGE,
                $wastage,
                $wastage->reasonLabel().($wastage->note ? ' — '.$wastage->note : ''),
                $shopId,
            );

            ActivityLog::record(
                'wastage.recorded',
                sprintf(
                    'Wrote off %s of %s — %s (₹%s)',
                    $wastage->label(),
                    $product->name,
                    $wastage->reasonLabel(),
                    number_format((float) $wastage->cost_value, 2),
                ),
                $wastage,
            );

            return $wastage->refresh();
        });
    }

    /**
     * Undo a wastage row, putting the stock back.
     *
     * Rare and deliberate: somebody recorded four kilos meaning four hundred
     * grams. The stock returns through a fresh movement rather than by
     * deleting the old one, because a ledger that could lose a row is a ledger
     * nobody can reconcile - "why is there 3 kg less than yesterday" has to
     * stay answerable even when yesterday was a mistake.
     */
    public function reverse(StockWastage $wastage, ?string $reason = null): void
    {
        DB::transaction(function () use ($wastage, $reason) {
            $product = $wastage->product;
            $warehouse = $wastage->warehouse;

            if ($product === null || $warehouse === null) {
                throw new RuntimeException('That entry no longer has a product or a store to return to.');
            }

            $this->stock->receive(
                $product,
                (float) $wastage->quantity,
                $warehouse,
                null,
                (float) $wastage->unit_cost,
                StockMovement::WASTAGE,
                $wastage,
                'Reversed: '.($reason ?: 'recorded in error'),
                $wastage->shop_id,
            );

            ActivityLog::record(
                'wastage.reversed',
                sprintf(
                    'Returned %s of %s to stock — %s',
                    $wastage->label(),
                    $product->name,
                    $reason ?: 'recorded in error',
                ),
                $wastage,
            );

            $wastage->delete();
        });
    }

    /**
     * What was thrown away over a period, and what it was worth.
     *
     * `loss` excludes staff meals and tastings. They are still deducted -
     * the food is gone either way - but they are not the kitchen's failure,
     * and a headline number that included them would have a manager chasing
     * something that is working as intended.
     *
     * @return array<string, mixed>
     */
    public function summary($from, $to): array
    {
        $rows = StockWastage::query()
            ->between($from, $to)
            ->get(['reason_code', 'cost_value', 'quantity']);

        $byReason = $rows
            ->groupBy('reason_code')
            ->map(fn (Collection $group) => [
                'label' => StockWastage::REASONS[$group->first()->reason_code] ?? $group->first()->reason_code,
                'entries' => $group->count(),
                'value' => round((float) $group->sum('cost_value'), 2),
            ])
            ->sortByDesc('value')
            ->values();

        return [
            'entries' => $rows->count(),
            'value' => round((float) $rows->sum('cost_value'), 2),
            'loss' => round(
                (float) $rows
                    ->reject(fn ($row) => in_array($row->reason_code, ['staff', 'sample'], true))
                    ->sum('cost_value'),
                2,
            ),
            'by_reason' => $byReason,
        ];
    }

    /**
     * Things that can actually be written off.
     *
     * Made-to-order dishes are excluded for the reason given in record():
     * there is no count of them to take away from.
     *
     * @return \Illuminate\Database\Eloquent\Builder<Product>
     */
    public function writableOff()
    {
        return Product::query()
            ->where('is_active', true)
            ->where('is_made_to_order', false)
            ->orderBy('name');
    }
}
