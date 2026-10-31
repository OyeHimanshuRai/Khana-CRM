<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Shop;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function show(Shop $shop, Product $product): View
    {
        abort_unless(
            $product->is_active && $product->is_published
                && Product::query()->availableAt($shop->id)->whereKey($product->id)->exists(),
            404
        );

        $product->load(['images', 'unit', 'brand', 'category', 'taxRate']);

        $related = Product::query()
            ->published()
            ->availableAt($shop->id)
            ->where('category_id', $product->category_id)
            ->whereKeyNot($product->id)
            ->with(['images', 'unit', 'taxRate', 'shops'])
            ->limit(8)
            ->get();

        Product::preloadStock($related, $shop->id);

        return view('shop.products.show', compact('product', 'related'));
    }
}
