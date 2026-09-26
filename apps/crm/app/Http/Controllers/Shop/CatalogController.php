<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Shop;
use App\Support\StorefrontSeo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CatalogController extends Controller
{
    public function index(Request $request, Shop $shop): View
    {
        return $this->listing($request, $shop, null);
    }

    public function category(Request $request, Shop $shop, Category $category): View
    {
        /*
         | {category:slug} resolves globally. Slugs are only unique per
         | company now, and TenantScope steps aside for a storefront guest,
         | so binding can hand back another company's row that happens to
         | share the slug. Re-ask within this outlet's company rather than
         | refuse: on a collision the binding already picked the wrong row,
         | and a 404 would land on the shop's own category link.
         */
        $category = Category::forTenant((int) $shop->tenant_id)
            ->where('slug', $category->slug)
            ->firstOrFail();

        return $this->listing($request, $shop, $category);
    }

    private function listing(Request $request, Shop $shop, ?Category $category): View
    {
        $products = Product::query()
            ->published()
            ->availableAt($shop->id)
            ->with(['images', 'unit', 'brand'])
            ->when($category, fn (Builder $q) => $q->where('category_id', $category->id))
            ->search($request->string('q')->toString() ?: null)
            ->when($request->filled('brand_id'), fn (Builder $q) => $q->where('brand_id', $request->integer('brand_id')))
            ->when($request->filled('min_price'), fn (Builder $q) => $q->where('selling_price', '>=', $request->float('min_price')))
            ->when($request->filled('max_price'), fn (Builder $q) => $q->where('selling_price', '<=', $request->float('max_price')))
            ->when(
                $request->string('sort')->toString() === 'price_asc',
                fn (Builder $q) => $q->orderBy('selling_price'),
                fn (Builder $q) => $request->string('sort')->toString() === 'price_desc'
                    ? $q->orderByDesc('selling_price')
                    : $q->orderBy('sort_order')->orderBy('name')
            )
            ->paginate(24)
            ->withQueryString();

        // The shop's own company. A storefront is served to a guest, so
        // TenantScope is not applied and these two would otherwise offer
        // every business's categories and brands as filters.
        $categories = Category::forTenant((int) $shop->tenant_id)
            ->active()->orderBy('sort_order')->get();

        $brands = Brand::forTenant((int) $shop->tenant_id)->orderBy('name')->get();

        /*
         | A filtered or paged catalogue is the same catalogue. Left to
         | url()->current() every sort order, price band and page number
         | would be a separate address competing with the one worth ranking,
         | so they all point at the plain list.
         */
        $seo = [
            'canonical' => $category
                ? route('shop.category', [$shop, $category])
                : route('shop.catalog', $shop),
            'description' => $category
                ? StorefrontSeo::trim($category->description)
                : null,
        ];

        return view('shop.catalog.index', compact('products', 'categories', 'brands', 'category', 'seo'));
    }
}
