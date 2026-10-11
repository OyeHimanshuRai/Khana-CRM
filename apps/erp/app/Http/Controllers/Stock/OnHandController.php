<?php

namespace App\Http\Controllers\Stock;

use App\Models\StockItem;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Support\Here;
use App\Support\Units;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * What is on the shelf at this outlet, item by item.
 *
 * The first screen of the Stock hub, and the one a storekeeper opens most:
 * how much, what it is worth, and - above everything else - what is short
 * or has gone negative.
 */
final class OnHandController
{
    public function index(Request $request): View
    {
        $outlet = Here::outlet();
        $stores = Here::stores();
        $storeId = $stores->contains('id', (int) $request->query('store')) ? (int) $request->query('store') : null;

        $levels = StockLevel::forOutlet($outlet)
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->get(['stock_item_id', 'qty', 'cost_rate'])
            ->groupBy('stock_item_id')
            ->map(fn ($slots) => [
                'qty' => (int) $slots->sum('qty'),
                'value' => (int) $slots->sum(fn (StockLevel $slot) => Units::value($slot->qty, $slot->cost_rate)),
            ]);

        $show = $request->query('show', 'all');
        $search = trim((string) $request->query('q'));

        $rows = Here::items()
            ->active()
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$search}%")
                ->orWhere('code', 'like', "%{$search}%")
                ->orWhere('category', 'like', "%{$search}%")))
            ->orderBy('category')->orderBy('name')
            ->get()
            ->map(fn (StockItem $item) => [
                'item' => $item,
                'qty' => $levels[$item->id]['qty'] ?? 0,
                'value' => $levels[$item->id]['value'] ?? 0,
            ]);

        $negative = $rows->filter(fn ($row) => $row['qty'] < 0)->count();
        $low = $rows->filter(fn ($row) => $row['qty'] >= 0 && $row['item']->reorder_qty > 0 && $row['qty'] < $row['item']->reorder_qty)->count();

        $rows = match ($show) {
            'negative' => $rows->filter(fn ($row) => $row['qty'] < 0),
            'low' => $rows->filter(fn ($row) => $row['qty'] >= 0 && $row['item']->reorder_qty > 0 && $row['qty'] < $row['item']->reorder_qty),
            'held' => $rows->filter(fn ($row) => $row['qty'] !== 0),
            default => $rows,
        };

        return view('stock.index', [
            'rows' => $rows->values(),
            'stores' => $stores,
            'storeId' => $storeId,
            'show' => $show,
            'search' => $search,
            'negative' => $negative,
            'low' => $low,
            'worth' => (int) $levels->sum('value'),
        ]);
    }

    public function show(int $item): View
    {
        $outlet = Here::outlet();
        $item = Here::items()->with('packs')->findOrFail($item);

        return view('stock.show', [
            'item' => $item,
            'levels' => StockLevel::forOutlet($outlet)
                ->with(['store', 'batch'])
                ->where('stock_item_id', $item->id)
                ->where('qty', '!=', 0)
                ->orderBy('store_id')->orderBy('batch_id')
                ->get(),
            'movements' => StockMovement::forOutlet($outlet)
                ->with(['store', 'batch', 'user'])
                ->where('stock_item_id', $item->id)
                ->latest('id')
                ->limit(50)
                ->get(),
        ]);
    }
}
