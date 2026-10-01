<?php

namespace App\Http\Controllers\Stock;

use App\Models\StockMovement;
use App\Support\Here;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The stock ledger: every change to a level and why. Read-only - a wrong
 * movement is corrected by another movement, never edited.
 */
final class MovementController
{
    public function __invoke(Request $request): View
    {
        $type = array_key_exists((string) $request->query('type'), StockMovement::TYPES) ? $request->query('type') : null;
        $from = $request->date('from')?->toDateString();
        $to = $request->date('to')?->toDateString();

        return view('movements.index', [
            'type' => $type,
            'from' => $from,
            'to' => $to,
            'types' => StockMovement::TYPES,
            'movements' => StockMovement::forOutlet(Here::outlet())
                ->with(['item', 'store', 'batch', 'user'])
                ->when($type, fn ($q) => $q->where('type', $type))
                ->when($from, fn ($q) => $q->where('business_date', '>=', $from))
                ->when($to, fn ($q) => $q->where('business_date', '<=', $to))
                ->latest('id')
                ->paginate(50)
                ->withQueryString(),
        ]);
    }
}
