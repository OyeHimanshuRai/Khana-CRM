<?php

namespace App\Http\Controllers\Sales;

use App\Models\KitchenLine;
use App\Models\Sale;
use App\Models\SalesItem;
use App\Support\Here;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The bills ERP has heard about, whichever way they came: from Khana CRM,
 * a CSV import, or other billing software. Read-only - a sale is changed
 * where it was made.
 */
final class SaleController
{
    public function index(Request $request): View
    {
        $outlet = Here::outlet();
        $source = array_key_exists((string) $request->query('source'), SalesItem::SOURCES) ? $request->query('source') : null;
        $from = $request->date('from')?->toDateString();
        $to = $request->date('to')?->toDateString();

        return view('sales.index', [
            'source' => $source,
            'from' => $from,
            'to' => $to,
            'sources' => SalesItem::SOURCES,
            'sales' => Sale::forOutlet($outlet)
                ->withCount('lines')
                ->when($source, fn ($q) => $q->where('source', $source))
                ->when($from, fn ($q) => $q->whereDate('business_date', '>=', $from))
                ->when($to, fn ($q) => $q->whereDate('business_date', '<=', $to))
                ->latest('business_date')->latest('id')
                ->paginate(40)
                ->withQueryString(),
        ]);
    }

    public function show(int $sale): View
    {
        $sale = Sale::forOutlet(Here::outlet())->with('lines.salesItem')->findOrFail($sale);

        // What the kitchen cooked for each line of this bill.
        $kitchen = KitchenLine::forOutlet($sale->outlet_id)
            ->whereIn('sale_line_id', $sale->lines->pluck('id'))
            ->get()
            ->groupBy('sale_line_id');

        return view('sales.show', ['sale' => $sale, 'kitchen' => $kitchen]);
    }
}
