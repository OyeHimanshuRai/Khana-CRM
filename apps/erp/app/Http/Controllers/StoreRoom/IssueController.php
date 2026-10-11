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
 * Issuing to the kitchen: stock moved from one store to another.
 */
final class IssueController
{
    public function index(): View
    {
        return view('storeroom.index', [
            'kind' => StockDocument::ISSUE,
            'title' => 'Issue to kitchen',
            'subtitle' => 'Stock moved from one store to another',
            'createRoute' => 'issues.create',
            'createLabel' => 'Issue stock',
            'documents' => StockDocument::forOutlet(Here::outlet())
                ->with(['store', 'toStore', 'user', 'lines.item'])
                ->where('kind', StockDocument::ISSUE)
                ->latest('id')
                ->paginate(30),
        ]);
    }

    public function create(): View
    {
        $stores = Here::mainStores();

        return view('storeroom.issue', [
            'stores' => Here::stores(),
            'from' => $stores['main'],
            'to' => $stores['kitchen'],
            'today' => Here::businessDate(),
            'catalogue' => Lines::catalogue(),
        ]);
    }

    public function store(Request $request, StoreRoom $room): RedirectResponse
    {
        $outlet = Here::outlet();

        $data = $request->validate([
            'from_store_id' => ['required', 'integer'],
            'to_store_id' => ['required', 'integer', 'different:from_store_id'],
            'on_date' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
        ], ['to_store_id.different' => 'Stock moves between two different stores.']);

        $lines = Lines::from($request);

        try {
            $issue = $room->issue(
                $outlet,
                Store::forOutlet($outlet)->findOrFail($data['from_store_id']),
                Store::forOutlet($outlet)->findOrFail($data['to_store_id']),
                date('Y-m-d', strtotime($data['on_date'])),
                $lines,
                $data['note'] ?? null,
            );
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('issues.index')->with('status', $issue->number.': '.count($lines).' item'.(count($lines) === 1 ? '' : 's').' moved.');
    }
}
