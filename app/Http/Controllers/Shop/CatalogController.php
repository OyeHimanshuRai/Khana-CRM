<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Shop;
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
        return $this->listing($request, $shop, $category);
    }

    private function listing(Request $request, Shop $shop, ?Category $category): View
    {
        $products = Product::query()
            ->published()
            ->availableAt($shop->id)
            // taxRate and shops feed the card's price; without them every card
            // costs a query per field it prints.
            ->with(['images', 'unit', 'brand', 'taxRate', 'shops'])
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

        // Every card shows stock; read it for the page in one query.
        Product::preloadStock($products->getCollection(), $shop->id);

        $categories = Category::query()->active()->orderBy('sort_order')->get();
        $brands = Brand::query()->orderBy('name')->get();

        return view('shop.catalog.index', compact('products', 'categories', 'brands', 'category'));
    }
}
