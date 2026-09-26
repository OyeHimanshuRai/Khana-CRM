<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Support\CurrentShop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The menu, for an aggregator or a captain's app (§2, §21).
 *
 * ---------------------------------------------------------------------------
 * What a caller is NOT given
 * ---------------------------------------------------------------------------
 *
 * No cost price, no margin, no stock on hand, no supplier. An aggregator
 * needs to show a dish and take money for it; everything else on the product
 * row is the restaurant's own business, and an API that returns it because it
 * was convenient is a leak nobody notices until a competitor is reading it.
 *
 * The shop comes from the token's own user through CurrentShop, never from
 * the request. An id a caller can edit is an id a caller will edit.
 */
class MenuController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $products = Product::query()
            ->sellable()
            ->with(['category:id,name', 'variants', 'unit:id,code'])
            ->when($request->filled('since'), function ($query) use ($request) {
                /*
                 | Incremental sync. An aggregator holding a thousand dishes
                 | should not pull all of them every five minutes, and this is
                 | the difference between a polite integration and a rude one.
                 */
                $query->where('updated_at', '>=', $request->date('since'));
            })
            ->orderBy('name')
            ->paginate(min(200, (int) $request->integer('per_page', 100)));

        return response()->json([
            'data' => $products->getCollection()->map(fn (Product $p) => $this->row($p)),
            'meta' => [
                'page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'total' => $products->total(),
            ],
        ]);
    }

    public function show(Product $product): JsonResponse
    {
        abort_unless($product->is_active && ! $product->is_ingredient, 404);

        return response()->json(['data' => $this->row(
            $product->load(['category:id,name', 'variants', 'unit:id,code'])
        )]);
    }

    /**
     * Mark a dish sold out, or bring it back (§8).
     *
     * Its own ability. An aggregator that can close a dish is useful; one
     * that could do it while only holding read access is a way to shut a
     * restaurant's menu from outside.
     */
    public function availability(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate([
            'is_sold_out' => ['required', 'boolean'],
        ]);

        $product->forceFill(['is_sold_out' => $data['is_sold_out']])->save();

        return response()->json([
            'data' => ['id' => $product->id, 'is_sold_out' => (bool) $product->is_sold_out],
        ]);
    }

    /**
     * One dish, as an integrator sees it.
     *
     * @return array<string, mixed>
     */
    private function row(Product $product): array
    {
        $shopId = CurrentShop::id();

        return [
            'id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'description' => $product->description,
            'category' => $product->category?->name,
            'price' => (float) $product->counterPriceFor($shopId),
            // The three channel prices §8 asks for, where they differ.
            'dine_in_price' => $product->dine_in_price !== null ? (float) $product->dine_in_price : null,
            'takeaway_price' => $product->takeaway_price !== null ? (float) $product->takeaway_price : null,
            'is_sold_out' => (bool) $product->is_sold_out,
            'is_available' => $product->is_active && ! $product->is_sold_out,
            'food_tags' => $product->food_tags ?? [],
            'image' => $product->imageUrl(),
            'variants' => $product->variants->map(fn ($variant) => [
                'id' => $variant->id,
                'name' => $variant->name,
                'price' => (float) ($variant->selling_price ?? $product->counterPriceFor($shopId)),
            ])->values(),
            'updated_at' => $product->updated_at?->toIso8601String(),
        ];
    }
}
