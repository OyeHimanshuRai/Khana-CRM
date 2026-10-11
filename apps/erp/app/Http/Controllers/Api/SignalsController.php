<?php

namespace App\Http\Controllers\Api;

use App\Models\SalesItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * What ERP last told CRM about each dish - on sale or sold out, and what a
 * portion costs - for CRM's nightly check (contracts/api/erp.yaml,
 * operationId signals).
 *
 * It is read straight from sales_item_states, the table App\Services\
 * Availability keeps so that only a change is sent. So it is exactly what
 * CRM holds if no erp.availability.changed or erp.cost.changed was lost, and
 * CRM puts right whatever differs.
 *
 * Listed the way Availability looks at a dish today: one entry a size for a
 * dish with sizes, one with no size for a dish without. A state left over
 * from before a dish gained sizes, or for a size CRM has since dropped, is
 * about something CRM no longer has and is left out - listing it would have
 * CRM act on a signal ERP will never update.
 */
final class SignalsController
{
    use ServesOutlet;

    public function __invoke(Request $request, string $id): JsonResponse
    {
        $this->tenantOf($request, $id);

        $dishes = SalesItem::allOutlets()
            ->with(['sizes' => fn ($q) => $q->where('is_active', true)->orderBy('id')])
            ->where('outlet_id', $id)
            ->where('source', SalesItem::CRM)
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        $states = DB::table('sales_item_states')
            ->where('outlet_id', $id)
            ->whereIn('sales_item_id', $dishes->pluck('id'))
            ->get()
            ->keyBy(fn ($state) => $state->sales_item_id.':'.$state->sales_item_size_id);

        $items = [];

        foreach ($dishes as $dish) {
            foreach ($dish->sizes->isEmpty() ? [null] : $dish->sizes->all() as $size) {
                $state = $states[$dish->id.':'.($size?->id ?? 0)] ?? null;

                // Nothing said about it yet: on sale, cost unknown, not listed.
                if ($state === null) {
                    continue;
                }

                $items[] = [
                    'item_id' => $dish->ref,
                    'variant_id' => $size?->ref,
                    'available' => (bool) $state->available,
                    'cost_paise' => $state->cost_paise === null ? null : (int) $state->cost_paise,
                ];
            }
        }

        return response()->json([
            'outlet_id' => $id,
            'items' => $items,
            'taken_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        ]);
    }
}
