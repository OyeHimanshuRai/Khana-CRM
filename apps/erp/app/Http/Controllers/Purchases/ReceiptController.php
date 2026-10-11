<?php

namespace App\Http\Controllers\Purchases;

use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\Store;
use App\Models\Supplier;
use App\Services\Purchasing;
use App\Support\Here;
use App\Support\Lines;
use App\Support\Units;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * Receiving goods: the delivery at the back door.
 *
 * One form - who from, the items and what each cost - and saving it books
 * the stock, makes the batches and raises the supplier bill. Opened from an
 * order, the form arrives filled with what is still outstanding on it.
 */
final class ReceiptController
{
    public function index(): View
    {
        return view('receipts.index', [
            'receipts' => GoodsReceipt::forOutlet(Here::outlet())
                ->with(['supplier', 'store', 'user'])
                ->withCount('lines')
                ->latest('id')
                ->paginate(30),
        ]);
    }

    public function create(Request $request): View
    {
        $order = $request->filled('order')
            ? PurchaseOrder::forOutlet(Here::outlet())->with('lines.item')->find((int) $request->query('order'))
            : null;

        /*
         | What is still to come on the order. A line nothing has arrived for
         | is shown as it was written - "2 bags" - with the price it was
         | ordered at; a part-received one is restated in the base unit,
         | because "the rest of 2 bags" is not something anybody can type.
         */
        $rows = $order?->isOpen()
            ? $order->lines->filter(fn ($line) => $line->outstanding() > 0)->map(function ($line) {
                $untouched = $line->received_qty === 0;
                $entered = Units::parse($line->entered_qty);

                return [
                    'item' => $line->stock_item_id,
                    'qty' => $untouched ? $line->entered_qty : Units::plain($line->outstanding()),
                    'unit' => $untouched ? $line->entered_unit : $line->item->base_unit,
                    'price' => $untouched && $entered > 0 && $line->amount_paise > 0
                        ? Units::plain((int) round($line->amount_paise * Units::SCALE / $entered * 100)) : '',
                ];
            })->values()->all()
            : [];

        return view('receipts.create', [
            'order' => $order?->isOpen() ? $order : null,
            'suppliers' => Supplier::forTenant(Here::tenant())->active()->orderBy('name')->get(),
            'stores' => Here::stores(),
            'mainStore' => Here::mainStores()['main'],
            'today' => Here::businessDate(),
            'catalogue' => Lines::catalogue(),
            'rows' => $rows,
        ]);
    }

    public function store(Request $request, Purchasing $purchasing): RedirectResponse
    {
        $outlet = Here::outlet();

        $data = $request->validate([
            'supplier_id' => ['nullable', 'integer'],
            'store_id' => ['required', 'integer'],
            'order_id' => ['nullable', 'integer'],
            'received_on' => ['required', 'date'],
            'bill_no' => ['nullable', 'string', 'max:60'],
            'due_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $lines = Lines::from($request);

        try {
            $receipt = $purchasing->receive($outlet, [
                'store' => Store::forOutlet($outlet)->findOrFail($data['store_id']),
                'supplier' => filled($data['supplier_id'] ?? null) ? Supplier::forTenant(Here::tenant())->findOrFail($data['supplier_id']) : null,
                'order' => filled($data['order_id'] ?? null) ? PurchaseOrder::forOutlet($outlet)->findOrFail($data['order_id']) : null,
                'received_on' => date('Y-m-d', strtotime($data['received_on'])),
                'bill_no' => $data['bill_no'] ?? null,
                'due_on' => filled($data['due_on'] ?? null) ? date('Y-m-d', strtotime($data['due_on'])) : null,
                'notes' => $data['notes'] ?? null,
            ], $lines);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('receipts.show', $receipt->id)
            ->with('status', $receipt->number.' received: '.count($lines).' item'.(count($lines) === 1 ? '' : 's').' into stock.');
    }

    public function show(int $receipt): View
    {
        return view('receipts.show', [
            'receipt' => GoodsReceipt::forOutlet(Here::outlet())
                ->with(['supplier', 'store', 'user', 'order', 'bill', 'lines.item', 'lines.batch'])
                ->findOrFail($receipt),
        ]);
    }
}
