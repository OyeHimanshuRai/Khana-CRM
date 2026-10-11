<?php

namespace App\Http\Controllers\Purchases;

use App\Models\GoodsReceipt;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\SupplierPayment;
use App\Services\Purchasing;
use App\Support\Here;
use App\Support\Lines;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * The people the business buys from, and one page for each: what is owed,
 * what was delivered, what was paid, and sending goods back.
 */
final class SupplierController
{
    public function index(Purchasing $purchasing): View
    {
        $outlet = Here::outlet();
        $suppliers = Supplier::forTenant(Here::tenant())->orderByDesc('is_active')->orderBy('name')->get();

        return view('suppliers.index', [
            'suppliers' => $suppliers,
            'owed' => $suppliers->mapWithKeys(fn (Supplier $supplier) => [$supplier->id => $purchasing->owed($outlet, $supplier)]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $supplier = Supplier::forTenant(Here::tenant())->create($this->validated($request) + ['tenant_id' => Here::tenant()]);

        return redirect()->route('suppliers.index')->with('status', $supplier->name.' added.');
    }

    public function show(int $supplier, Purchasing $purchasing): View
    {
        $outlet = Here::outlet();
        $supplier = Supplier::forTenant(Here::tenant())->findOrFail($supplier);

        return view('suppliers.show', [
            'supplier' => $supplier,
            'owed' => $purchasing->owed($outlet, $supplier),
            'bills' => SupplierBill::forOutlet($outlet)->where('supplier_id', $supplier->id)->latest('billed_on')->latest('id')->limit(50)->get(),
            'payments' => SupplierPayment::forOutlet($outlet)->with('bill')->where('supplier_id', $supplier->id)->latest('paid_on')->latest('id')->limit(50)->get(),
            'receipts' => GoodsReceipt::forOutlet($outlet)->where('supplier_id', $supplier->id)->latest('id')->limit(20)->get(),
            'stores' => Here::stores(),
            'catalogue' => Lines::catalogue(),
            'today' => Here::businessDate(),
            'methods' => SupplierPayment::METHODS,
        ]);
    }

    public function update(Request $request, int $supplier): RedirectResponse
    {
        $supplier = Supplier::forTenant(Here::tenant())->findOrFail($supplier);
        $supplier->update($this->validated($request) + ['is_active' => $request->boolean('is_active')]);

        return redirect()->route('suppliers.show', $supplier->id)->with('status', $supplier->name.' saved.');
    }

    /** Send goods back: one item, out of one store, with a reason. */
    public function sendBack(Request $request, int $supplier, Purchasing $purchasing): RedirectResponse
    {
        $outlet = Here::outlet();
        $supplier = Supplier::forTenant(Here::tenant())->findOrFail($supplier);

        $data = $request->validate([
            'store_id' => ['required', 'integer'],
            'returned_on' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:200'],
        ]);

        $line = Lines::from($request)[0];

        try {
            $credit = $purchasing->sendBack(
                $outlet, $supplier, Store::forOutlet($outlet)->findOrFail($data['store_id']),
                $line['item'], $line['batch_id'], $line['qty'], date('Y-m-d', strtotime($data['returned_on'])), $data['reason'],
            );
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('suppliers.show', $supplier->id)
            ->with('status', 'Sent back. '.$supplier->name.' has been credited ₹'.number_format($credit->amount_paise / 100, 2).'.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'contact_name' => ['nullable', 'string', 'max:200'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'gstin' => ['nullable', 'string', 'size:15'],
            'address' => ['nullable', 'string', 'max:255'],
            'terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
        ]);

        // An empty box is "pay on delivery".
        $data['terms_days'] = (int) ($data['terms_days'] ?? 0);

        return $data;
    }
}
