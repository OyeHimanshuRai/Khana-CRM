<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Batch;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\StockService;
use App\Support\ApiResponse;
use App\Support\CurrentShop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * "Restock", from the item itself.
 *
 * For the everyday job - twelve slices baked this morning, a crate delivered -
 * that does not warrant a purchase order and is not a correction of a count.
 *
 * It is a separate controller from StockController on purpose: that screen is
 * read-only by design ("every change belongs to a document"), and this is the
 * one deliberate exception. It does not skip the explanation, it keeps it
 * somewhere else: every top-up is its own movement type (Restock) with who did
 * it, when and why, and an activity-log entry, and it goes through StockService
 * like every other stock change - so the ledger, the weighted-average cost and
 * the batch cannot disagree with it.
 *
 * Its own right (inventory.stock.restock), because it adds stock with no
 * approval step: somebody who may look at stock does not thereby get it.
 */
class StockRestockController extends Controller
{
    /**
     * The "Restock" dialog, opened from an item's row.
     *
     * A dish is made to order and has no shelf to add to; the dialog says so and
     * points at what does work, rather than offering a form that cannot succeed.
     */
    public function create(Product $product): View
    {
        $product->loadMissing('unit');

        return view('admin.stock._restock', [
            'product' => $product,
            'shop' => CurrentShop::get(),
            'warehouses' => Warehouse::active()->orderByDesc('is_default')->orderBy('name')->get(),
            'onHand' => $product->tracksStock() ? $product->availableStock() : null,
        ]);
    }

    public function store(Request $request, Product $product, StockService $stock): JsonResponse
    {
        $shop = CurrentShop::get();

        if ($shop === null) {
            return ApiResponse::error("Choose a single shop first - stock is added to one shop's shelf.");
        }

        if (! $product->tracksStock()) {
            return ApiResponse::error(sprintf(
                '"%s" is made to order, so there is no stock to add. Mark it sold out to take it off the menu, or restock what it is made from.',
                $product->name,
            ));
        }

        $data = $request->validate([
            'warehouse_id' => ['required', 'integer'],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'unit_cost' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'batch_no' => [$product->track_batches ? 'required' : 'nullable', 'string', 'max:60'],
            'expiry_date' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:250'],
        ], [
            'quantity.gt' => 'Enter how many to add - more than zero.',
            'batch_no.required' => 'This item is tracked by batch. Give the batch number on the pack or delivery note.',
        ]);

        $warehouse = Warehouse::active()->whereKey($data['warehouse_id'])->first();

        if ($warehouse === null) {
            return ApiResponse::error('That warehouse is not available in this shop.');
        }

        $quantity = (float) $data['quantity'];

        // A cost left blank is the item's own cost, not zero: zero would drag the
        // weighted average down and make every later margin look better than it is.
        $unitCost = isset($data['unit_cost']) && $data['unit_cost'] !== ''
            ? (float) $data['unit_cost']
            : (float) $product->purchase_price;

        $batch = null;

        if ($product->track_batches) {
            $batch = Batch::allShops()->firstOrNew([
                'shop_id' => $shop->id,
                'product_id' => $product->id,
                'batch_no' => $data['batch_no'],
            ]);

            // A batch seen before keeps its dates unless this top-up supplies them.
            $batch->fill([
                'mfg_date' => $batch->mfg_date,
                'expiry_date' => $data['expiry_date'] ?? $batch->expiry_date,
                'purchase_price' => $unitCost ?: (float) ($batch->purchase_price ?? 0),
                'mrp' => (float) ($batch->mrp ?: $product->mrp),
                'selling_price' => (float) ($batch->selling_price ?: $product->selling_price),
                'is_active' => true,
            ]);
            $batch->shop_id = $shop->id;
            $batch->save();
        }

        $note = filled($data['note'] ?? null) ? $data['note'] : 'Restocked from the item';

        $slot = $stock->receive(
            $product, $quantity, $warehouse, $batch, $unitCost,
            StockMovement::RESTOCK, null, $note, $shop->id,
        );

        ActivityLog::record(
            'stock.restocked',
            sprintf('Restocked %s: +%s (%s)', $product->name, $this->plain($quantity), $note),
            $product,
            ['quantity' => $quantity, 'warehouse_id' => $warehouse->id, 'batch_id' => $batch?->id, 'unit_cost' => $unitCost],
        );

        return ApiResponse::success(sprintf(
            'Added %s %s of %s. %s now on hand.',
            $this->plain($quantity),
            $product->unit?->code ?? 'units',
            $product->name,
            $this->plain((float) $product->availableStock($shop->id)),
        ), ['on_hand' => (float) $slot->quantity]);
    }

    private function plain(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.') ?: '0';
    }
}
