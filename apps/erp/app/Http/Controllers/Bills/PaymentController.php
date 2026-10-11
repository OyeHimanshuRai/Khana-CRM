<?php

namespace App\Http\Controllers\Bills;

use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\SupplierPayment;
use App\Rules\Amount;
use App\Services\Purchasing;
use App\Support\Here;
use App\Support\Units;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/**
 * Payments to suppliers.
 *
 * One form, used from three places - a bill's row, a supplier's page and
 * this list. Money goes to the bill named first, then to that supplier's
 * oldest open bills, and anything left is kept as an advance.
 */
final class PaymentController
{
    public function index(): View
    {
        return view('payments.index', [
            'suppliers' => Supplier::forTenant(Here::tenant())->active()->orderBy('name')->get(),
            'methods' => SupplierPayment::METHODS,
            'today' => Here::businessDate(),
            'payments' => SupplierPayment::forOutlet(Here::outlet())
                ->with(['supplier', 'bill'])
                ->latest('paid_on')->latest('id')
                ->paginate(40),
        ]);
    }

    public function store(Request $request, Purchasing $purchasing): RedirectResponse
    {
        $outlet = Here::outlet();

        $data = $request->validate([
            'supplier_id' => ['required', 'integer'],
            'supplier_bill_id' => ['nullable', 'integer'],
            'amount' => ['required', new Amount],
            'method' => ['required', Rule::in(array_keys(SupplierPayment::METHODS))],
            'paid_on' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $supplier = Supplier::forTenant(Here::tenant())->findOrFail($data['supplier_id']);

        try {
            $payments = $purchasing->pay(
                $outlet, $supplier, Units::paise($data['amount']), $data['method'], date('Y-m-d', strtotime($data['paid_on'])),
                filled($data['supplier_bill_id'] ?? null) ? SupplierBill::forOutlet($outlet)->findOrFail($data['supplier_bill_id']) : null,
                $data['reference'] ?? null, $data['notes'] ?? null,
            );
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $advance = (int) $payments->whereNull('supplier_bill_id')->sum('amount_paise');

        return back()->with('status', '₹'.Units::rupees(Units::paise($data['amount'])).' paid to '.$supplier->name.'.'
            .($advance > 0 ? ' ₹'.Units::rupees($advance).' of it is kept as an advance.' : ''));
    }
}
