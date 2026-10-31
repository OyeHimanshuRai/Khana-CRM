<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\Shop;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function index(Shop $shop): View
    {
        $featured = Product::query()
            ->published()
            ->availableAt($shop->id)
            ->where('is_featured', true)
            ->with(['images', 'unit', 'taxRate', 'shops'])
            ->orderBy('sort_order')
            ->limit(12)
            ->get();

        $latest = Product::query()
            ->published()
            ->availableAt($shop->id)
            ->with(['images', 'unit', 'taxRate', 'shops'])
            ->latest()
            ->limit(12)
            ->get();

        // The cards print stock; one query per list rather than per card.
        Product::preloadStock($featured, $shop->id);
        Product::preloadStock($latest, $shop->id);

        $categories = Category::query()
            ->active()
            ->orderBy('sort_order')
            ->limit(12)
            ->get();

        $slides = $shop->storefrontSlides()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->limit(8)
            ->get();

        return view('shop.home', compact('featured', 'latest', 'categories', 'slides'));
    }
}
