<?php

namespace App\Http\Controllers\Bills;

use App\Models\SupplierBill;
use App\Models\SupplierPayment;
use App\Support\Here;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Supplier bills: what is owed, oldest due first, with paying one a form
 * on its own row. Bills are raised by receiving goods, not typed here.
 */
final class BillController
{
    public function index(Request $request): View
    {
        $outlet = Here::outlet();
        $show = in_array($request->query('show'), ['open', 'paid', 'all'], true) ? $request->query('show') : 'open';

        $open = SupplierBill::forOutlet($outlet)->open();

        return view('bills.index', [
            'show' => $show,
            'today' => Here::businessDate(),
            'methods' => SupplierPayment::METHODS,
            'owed' => (int) (clone $open)->selectRaw('COALESCE(SUM(total_paise - paid_paise), 0) as due')->value('due'),
            'overdue' => (int) (clone $open)->whereDate('due_on', '<', Here::businessDate())
                ->selectRaw('COALESCE(SUM(total_paise - paid_paise), 0) as due')->value('due'),
            'bills' => SupplierBill::forOutlet($outlet)
                ->with(['supplier', 'receipt'])
                ->when($show !== 'all', fn ($q) => $q->where('status', $show))
                ->orderByRaw('due_on IS NULL')->orderBy('due_on')->orderBy('id')
                ->paginate(40)
                ->withQueryString(),
        ]);
    }
}
