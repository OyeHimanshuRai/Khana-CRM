<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\Shop;
use App\Support\StorefrontSeo;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function index(Shop $shop): View
    {
        $featured = Product::query()
            ->published()
            ->availableAt($shop->id)
            ->where('is_featured', true)
            ->with(['images', 'unit'])
            ->orderBy('sort_order')
            ->limit(12)
            ->get();

        $latest = Product::query()
            ->published()
            ->availableAt($shop->id)
            ->with(['images', 'unit'])
            ->latest()
            ->limit(12)
            ->get();

        // The shop's own company: a storefront is served to a guest, so the
        // tenant scope does not apply here.
        $categories = Category::forTenant((int) $shop->tenant_id)
            ->active()
            ->orderBy('sort_order')
            ->limit(12)
            ->get();

        // The front door is the page that gets shared and the one a search
        // for the restaurant's name should land on, so this is where the
        // outlet describes itself rather than a product.
        $seo = ['jsonLd' => StorefrontSeo::shopGraph($shop)];

        return view('shop.home', compact('featured', 'latest', 'categories', 'seo'));
    }
}
