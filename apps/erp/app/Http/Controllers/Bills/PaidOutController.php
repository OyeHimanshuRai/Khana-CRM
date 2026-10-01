<?php

namespace App\Http\Controllers\Bills;

use App\Models\Expense;
use App\Models\PaidOut;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Services\Purchasing;
use App\Support\Here;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/**
 * Paid-outs: cash that left the till in CRM.
 *
 * The cashier records that money went out; this is where somebody says
 * what it was - a payment to a supplier, or an expense. A paid-out the
 * cashier already tied to a supplier bill is filed by itself and only
 * shows here as done.
 */
final class PaidOutController
{
    public function index(): View
    {
        $outlet = Here::outlet();

        return view('paid-outs.index', [
            'pending' => PaidOut::forOutlet($outlet)->pending()->orderBy('business_date')->orderBy('id')->get(),
            'filed' => PaidOut::forOutlet($outlet)->with(['payment.supplier', 'expense'])
                ->where('status', PaidOut::FILED)->latest('business_date')->latest('id')->limit(30)->get(),
            'suppliers' => Supplier::forTenant(Here::tenant())->active()->orderBy('name')->get(),
            'categories' => Expense::CATEGORIES,
        ]);
    }

    public function file(Request $request, int $paidOut, Purchasing $purchasing): RedirectResponse
    {
        $outlet = Here::outlet();
        $paidOut = PaidOut::forOutlet($outlet)->findOrFail($paidOut);

        $data = $request->validate([
            'as' => ['required', Rule::in(['payment', 'expense'])],
            'supplier_id' => ['required_if:as,payment', 'nullable', 'integer'],
            'category' => ['required_if:as,expense', 'nullable', Rule::in(array_keys(Expense::CATEGORIES))],
        ], [
            'supplier_id.required_if' => 'Choose the supplier it was paid to.',
            'category.required_if' => 'Choose what kind of expense it was.',
        ]);

        try {
            if ($data['as'] === 'payment') {
                $supplier = Supplier::forTenant(Here::tenant())->findOrFail($data['supplier_id']);
                $purchasing->filePaidOutAsPayment($paidOut, $supplier);
            } else {
                $purchasing->filePaidOutAsExpense($paidOut, $data['category']);
            }
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('paid-outs.index')->with('status', 'Filed.');
    }
}
