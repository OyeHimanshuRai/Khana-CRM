<?php

namespace App\Http\Controllers\Api\Machine;

use App\Events\Outbound\OutletClock;
use App\Events\Outbound\PublicIds;
use App\Events\Outbound\Wire;
use App\Models\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * GET /api/v1/outlets/{id}/sales-digest?date=YYYY-MM-DD - one business day's
 * sales, for ERP's nightly check (operationId salesDigest).
 *
 * The day is drawn by the same OutletClock the events' business_date comes
 * from, so a 01:30 bill is on the same day here as in crm.sale.recorded.
 *
 * The two lists do not overlap. sale_ids, count and grand_paise are the
 * sales that stand; cancelled_sale_ids are the day's sales that were
 * cancelled since, so ERP can check it heard crm.sale.cancelled for each.
 * A sale ERP has in neither list is one it should ask GET /sales/{id} about.
 */
final class SalesDigestController
{
    public function __invoke(Request $request, string $id, ServedOutlets $outlets): JsonResponse
    {
        $shop = $outlets->resolve($request, $id);

        $date = (string) $request->query('date', '');

        try {
            [$from, $until] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1
                ? OutletClock::window($shop, $date)
                : throw new InvalidArgumentException();
        } catch (InvalidArgumentException) {
            ServedOutlets::refuse(422, 'Send the business day as ?date=YYYY-MM-DD.');
        }

        $sales = Invoice::allShops()
            ->where('shop_id', $shop->id)
            ->where('status', '!=', Invoice::DRAFT)
            ->where('invoiced_at', '>=', $from)
            ->where('invoiced_at', '<', $until)
            ->orderBy('id')
            ->get(['id', 'ulid', 'status', 'grand_total']);

        [$cancelled, $standing] = $sales->partition(fn (Invoice $sale) => $sale->isCancelled());

        return response()->json([
            'outlet_id' => $shop->ulid,
            'business_date' => $date,
            'count' => $standing->count(),
            'grand_paise' => (int) $standing->sum(fn (Invoice $sale) => Wire::nonNegativePaise($sale->grand_total)),
            'sale_ids' => PublicIds::map(Invoice::class, $standing->pluck('id'))->values(),
            'cancelled_sale_ids' => PublicIds::map(Invoice::class, $cancelled->pluck('id'))->values(),
        ]);
    }
}
