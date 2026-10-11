<?php

namespace App\Http\Controllers\Api;

use App\Models\SalesItem;
use App\Services\Availability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Khana\Kit\Support\Decimal;

/**
 * Stock on hand and recipe cost for one menu item, for the panel on CRM's
 * menu screen (contracts/api/erp.yaml).
 */
final class ItemStockController
{
    use ServesOutlet;

    public function __invoke(Request $request, Availability $availability, string $id, string $item): JsonResponse
    {
        $this->tenantOf($request, $id);

        $salesItem = SalesItem::allOutlets()
            ->where('outlet_id', $id)->where('source', SalesItem::CRM)->where('ref', $item)->first();

        if ($salesItem === null) {
            return response()->json(['message' => 'ERP has not heard of this item.'], 404);
        }

        $sizes = $availability->snapshot($salesItem);

        return response()->json([
            'item_id' => $salesItem->ref,
            'has_recipe' => collect($sizes)->contains('has_recipe', true),
            'sizes' => array_map(fn (array $size) => [
                'variant_id' => $size['variant_ref'],
                'portions_available' => $size['portions'] === null ? null : Decimal::format($size['portions'], 4),
                'cost_paise' => $size['cost_paise'],
                'food_cost_percent' => Availability::percent($size['cost_paise'], $size['price_paise']),
                'available' => $size['available'],
            ], $sizes),
        ]);
    }
}
