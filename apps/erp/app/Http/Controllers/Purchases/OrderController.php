<?php

namespace App\Http\Controllers\Purchases;

use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Services\Purchasing;
use App\Support\Here;
use App\Support\Lines;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * Purchase orders: what was asked of a supplier.
 *
 * Optional. Many kitchens phone the dairy and receive what turns up; for
 * the ones that write it down, the order is what the delivery is checked
 * against.
 */
final class OrderController
{
    public function index(): View
    {
        return view('orders.index', [
            'orders' => PurchaseOrder::forOutlet(Here::outlet())
                ->with('supplier')
                ->withCount('lines')
                ->latest('id')
                ->paginate(30),
        ]);
    }

    public function create(): View
    {
        return view('orders.create', [
            'suppliers' => Supplier::forTenant(Here::tenant())->active()->orderBy('name')->get(),
            'today' => Here::businessDate(),
            'catalogue' => Lines::catalogue(),
        ]);
    }

    public function store(Request $request, Purchasing $purchasing): RedirectResponse
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'integer'],
            'ordered_on' => ['required', 'date'],
            'expected_on' => ['nullable', 'date', 'after_or_equal:ordered_on'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $order = $purchasing->order(
            Here::outlet(),
            Supplier::forTenant(Here::tenant())->findOrFail($data['supplier_id']),
            date('Y-m-d', strtotime($data['ordered_on'])),
            filled($data['expected_on'] ?? null) ? date('Y-m-d', strtotime($data['expected_on'])) : null,
            Lines::from($request),
            $data['notes'] ?? null,
        );

        return redirect()->route('orders.show', $order->id)->with('status', 'Order '.$order->number.' saved.');
    }

    public function show(int $order): View
    {
        return view('orders.show', [
            'order' => PurchaseOrder::forOutlet(Here::outlet())
                ->with(['supplier', 'lines.item', 'receipts'])
                ->findOrFail($order),
        ]);
    }

    public function cancel(int $order, Purchasing $purchasing): RedirectResponse
    {
        $order = PurchaseOrder::forOutlet(Here::outlet())->findOrFail($order);

        try {
            $purchasing->cancelOrder($order);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('orders.index')->with('status', 'Order '.$order->number.' cancelled.');
    }
}
