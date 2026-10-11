<?php

namespace App\Http\Controllers\Api\Machine;

use App\Events\Outbound\SalePayload;
use App\Models\Invoice;
use App\Models\Shop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Khana\Kit\Support\Ulid;

/**
 * GET /api/v1/sales/{id} - one sale, for anything the nightly check finds
 * missing (operationId sale).
 *
 * The sale exactly as crm.sale.recorded carried it (the same SalePayload),
 * plus whose it is and where it stands now: "cancelled" once cancelled,
 * "issued" for every other state a real bill can be in - paid, part paid,
 * on account or partly returned are all still a sale.
 */
final class SaleController
{
    public function __invoke(Request $request, string $id, ServedOutlets $outlets, SalePayload $sales): JsonResponse
    {
        $invoice = Ulid::isValid($id) ? Invoice::allShops()->where('ulid', $id)->first() : null;

        // A draft never became a sale, so it was never announced either.
        if ($invoice === null || $invoice->status === Invoice::DRAFT) {
            ServedOutlets::refuse(404, 'No such sale.');
        }

        $shop = Shop::withTrashed()->findOrFail($invoice->shop_id);
        $outlets->authorise($request, $shop);

        return response()->json($sales->for($invoice) + [
            'tenant_id' => DB::table('tenants')->where('id', $shop->tenant_id)->value('ulid'),
            'outlet_id' => $shop->ulid,
            'status' => $invoice->isCancelled() ? 'cancelled' : 'issued',
        ]);
    }
}
