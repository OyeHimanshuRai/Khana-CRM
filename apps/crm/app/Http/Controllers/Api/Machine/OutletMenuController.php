<?php

namespace App\Http\Controllers\Api\Machine;

use App\Events\Outbound\MenuItemPayload;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/outlets/{id}/menu - the outlet's whole menu, for ERP's first
 * sync (contracts/api/crm.yaml, operationId outletMenu).
 *
 * Every item the business has, archived ones included and flagged, each in
 * exactly the shape crm.menu.item_upserted carries - built by the same
 * MenuItemPayload, so a synced copy and an event-fed copy cannot differ.
 * After this, the events keep ERP's copy current.
 *
 * Guarded by `machine:menu:read`; which outlets may be read is ServedOutlets'.
 */
final class OutletMenuController
{
    public function __invoke(Request $request, string $id, ServedOutlets $outlets, MenuItemPayload $menu): JsonResponse
    {
        $shop = $outlets->resolve($request, $id);

        $items = Product::allTenants()
            ->withTrashed()
            ->where('tenant_id', $shop->tenant_id)
            ->orderBy('id')
            ->get()
            ->map(fn (Product $product) => $menu->for($product, $shop) + ['archived' => $product->trashed()])
            ->values();

        return response()->json([
            'outlet_id' => $shop->ulid,
            'items' => $items,
            'taken_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        ]);
    }
}
