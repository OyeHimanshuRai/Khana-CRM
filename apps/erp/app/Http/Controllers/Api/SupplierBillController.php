<?php

namespace App\Http\Controllers\Api;

use App\Models\SupplierBill;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * An outlet's supplier bills, for the paid-out screen in CRM
 * (contracts/api/erp.yaml): the cashier picks the bill the cash is for.
 */
final class SupplierBillController
{
    use ServesOutlet;

    public function __invoke(Request $request, string $id): JsonResponse
    {
        $this->tenantOf($request, $id);

        $status = in_array($request->query('status'), ['open', 'paid', 'all'], true) ? $request->query('status') : 'open';

        $bills = SupplierBill::allOutlets()
            ->with('supplier')
            ->where('outlet_id', $id)
            ->when($status === 'all',
                fn ($q) => $q->where('status', '!=', SupplierBill::CANCELLED),
                fn ($q) => $q->where('status', $status))
            // Oldest due first; a bill with no due date after the dated ones.
            ->orderByRaw('due_on IS NULL')->orderBy('due_on')->orderBy('id')
            ->limit(200)
            ->get();

        return response()->json(['bills' => $bills->map(fn (SupplierBill $bill) => [
            'bill_id' => $bill->ulid,
            'supplier_name' => $bill->supplier?->name ?? 'Unknown supplier',
            'bill_no' => $bill->bill_no,
            'billed_on' => $bill->billed_on->toDateString(),
            'due_on' => $bill->due_on?->toDateString(),
            'total_paise' => $bill->total_paise,
            'due_paise' => $bill->duePaise(),
        ])->all()]);
    }
}
