<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Support\CurrentShop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Which outlets the person may work at, and switching between them; the choice is the same one the web switcher makes.
class OutletController extends Controller
{
    public function index(): JsonResponse
    {
        $current = CurrentShop::id();

        return response()->json([
            'data' => CurrentShop::accessible()->map(fn (Shop $shop) => [
                'id' => $shop->id,
                'name' => $shop->name,
                'code' => $shop->code,
                'current' => $shop->id === $current,
            ])->values(),
        ]);
    }

    public function switch(Request $request): JsonResponse
    {
        $data = $request->validate(['shop_id' => ['required', 'integer']]);

        if (! CurrentShop::set((int) $data['shop_id'])) {
            return response()->json(['message' => 'You do not work at that outlet.'], 403);
        }

        $shop = CurrentShop::get();

        return response()->json(['shop' => ['id' => $shop->id, 'name' => $shop->name]]);
    }
}
