<?php

namespace App\Http\Controllers\StoreRoom;

use App\Models\StockDocument;
use App\Models\Store;
use App\Services\StoreRoom;
use App\Support\Here;
use App\Support\Lines;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * Logging wastage: stock written off, with a reason.
 */
final class WastageController
{
    public function index(): View
    {
        return view('storeroom.index', [
            'kind' => StockDocument::WASTAGE,
            'title' => 'Wastage',
            'subtitle' => 'Stock written off, and why',
            'createRoute' => 'wastage.create',
            'createLabel' => 'Log wastage',
            'documents' => StockDocument::forOutlet(Here::outlet())
                ->with(['store', 'user', 'lines.item'])
                ->where('kind', StockDocument::WASTAGE)
                ->latest('id')
                ->paginate(30),
        ]);
    }

    public function create(): View
    {
        return view('storeroom.wastage', [
            'stores' => Here::stores(),
            'kitchen' => Here::mainStores()['kitchen'],
            'today' => Here::businessDate(),
            'reasons' => StockDocument::WASTAGE_REASONS,
            'catalogue' => Lines::catalogue(),
        ]);
    }

    public function store(Request $request, StoreRoom $room): RedirectResponse
    {
        $outlet = Here::outlet();

        $data = $request->validate([
            'store_id' => ['required', 'integer'],
            'on_date' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:200'],
        ]);

        $lines = Lines::from($request);

        try {
            $note = $room->waste(
                $outlet,
                Store::forOutlet($outlet)->findOrFail($data['store_id']),
                date('Y-m-d', strtotime($data['on_date'])),
                $data['reason'],
                $lines,
            );
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('wastage.index')
            ->with('status', $note->number.': ₹'.number_format($note->value_paise / 100, 2).' written off.');
    }
}
