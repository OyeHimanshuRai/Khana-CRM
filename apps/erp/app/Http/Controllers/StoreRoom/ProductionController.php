<?php

namespace App\Http\Controllers\StoreRoom;

use App\Models\Recipe;
use App\Models\StockDocument;
use App\Models\Store;
use App\Services\StoreRoom;
use App\Support\Here;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;
use RuntimeException;

/**
 * Production: making a batch of something prepared in-house - a gravy
 * base, a dough - from its recipe.
 */
final class ProductionController
{
    public function index(): View
    {
        return view('storeroom.index', [
            'kind' => StockDocument::PRODUCTION,
            'title' => 'Production',
            'subtitle' => 'Batches of prepared items made in-house',
            'createRoute' => 'production.create',
            'createLabel' => 'Make a batch',
            'documents' => StockDocument::forOutlet(Here::outlet())
                ->with(['store', 'user', 'lines.item'])
                ->where('kind', StockDocument::PRODUCTION)
                ->latest('id')
                ->paginate(30),
        ]);
    }

    public function create(): View
    {
        $outlet = Here::outlet();
        $withRecipe = Recipe::forOutlet($outlet)->where('stock_item_id', '>', 0)->pluck('yield_qty', 'stock_item_id');

        return view('storeroom.production', [
            'items' => Here::items()->active()->with('packs')->where('is_prepared', true)->orderBy('name')->get(),
            'withRecipe' => $withRecipe,
            'stores' => Here::stores(),
            'kitchen' => Here::mainStores()['kitchen'],
            'today' => Here::businessDate(),
        ]);
    }

    public function store(Request $request, StoreRoom $room): RedirectResponse
    {
        $outlet = Here::outlet();

        $data = $request->validate([
            'stock_item_id' => ['required', 'integer'],
            'store_id' => ['required', 'integer'],
            'qty' => ['required', 'numeric', 'gt:0'],
            'unit' => ['required', 'string', 'max:20'],
            'on_date' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $item = Here::items()->with('packs')->where('is_prepared', true)->findOrFail($data['stock_item_id']);

        try {
            $batch = $room->produce(
                $outlet,
                Store::forOutlet($outlet)->findOrFail($data['store_id']),
                $item,
                $item->toBase($data['qty'], $data['unit']),
                date('Y-m-d', strtotime($data['on_date'])),
                $data['note'] ?? null,
            );
        } catch (RuntimeException|InvalidArgumentException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('production.index')
            ->with('status', $batch->number.': '.$item->show($item->toBase($data['qty'], $data['unit'])).' of '.$item->name.' made.');
    }
}
