<?php

namespace App\Http\Controllers\Api;

use App\Services\Reports;
use App\Support\Outlets;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * One trading day's food cost at an outlet, for the tile on CRM's home
 * screen (contracts/api/erp.yaml, operationId foodCost).
 *
 * The same figures as ERP's own food cost report for that one day - worked
 * out by the same Reports, so the tile and the report cannot disagree. A
 * dish sold with no recipe has no cost and is counted in `uncosted`, which
 * is how the reader knows how far to trust the percentage.
 */
final class FoodCostController
{
    use ServesOutlet;

    public function __invoke(Request $request, Reports $reports, string $id): JsonResponse
    {
        $this->tenantOf($request, $id);

        $date = $request->query('date') ?? Outlets::businessDate($id);

        if (! self::isDay($date)) {
            return response()->json(['message' => 'Send the business day as ?date=YYYY-MM-DD.'], 422);
        }

        $figures = $reports->foodCost($id, $date, $date);

        return response()->json([
            'outlet_id' => $id,
            'business_date' => $date,
            'sales_paise' => $figures['revenue'],
            'cost_paise' => $figures['cost'],
            'food_cost_percent' => $figures['percent'],
            'uncosted' => $figures['uncosted'],
        ]);
    }

    /** A real day written YYYY-MM-DD: 2026-02-30 is not one. */
    private static function isDay(mixed $date): bool
    {
        return is_string($date)
            && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) === 1
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }
}
