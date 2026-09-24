<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Shop;
use App\Support\StorefrontSeo;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function show(Shop $shop, Product $product): View
    {
        /*
         | {product:slug} resolves globally, and slugs are only unique per
         | company now. A guest has no session, so TenantScope steps aside
         | and binding can hand back another company's dish that happens to
         | share the slug - which the check below would then 404, hiding the
         | outlet's own product behind a stranger's. Re-ask inside this
         | shop's own list first.
         */
        $product = Product::query()
            ->availableAt($shop->id)
            ->where('slug', $product->slug)
            ->firstOrFail();

        abort_unless($product->is_active && $product->is_published, 404);

        $product->load(['images', 'unit', 'brand', 'category', 'taxRate']);

        $related = Product::query()
            ->published()
            ->availableAt($shop->id)
            ->where('category_id', $product->category_id)
            ->whereKeyNot($product->id)
            ->with(['images', 'unit'])
            ->limit(8)
            ->get();

        /*
         | The SEO tab on the product screen has been collecting a title, a
         | description and keywords since the beginning, and nothing has ever
         | read one: an owner filled the fields in, the app saved them, showed
         | them back on the edit screen, and no page a customer or a crawler
         | reached was any different for it.
         |
         | The fallbacks are the copy that is already on the page, so a
         | product nobody has written SEO for still describes itself.
         */
        $seo = [
            'title' => $product->meta_title ?: null,
            'description' => StorefrontSeo::trim(
                $product->meta_description ?: ($product->short_description ?: $product->description)
            ),
            'image' => $product->imageUrl(),
            'canonical' => route('shop.product', [$shop, $product]),
            'jsonLd' => $this->productGraph($shop, $product),
        ];

        return view('shop.products.show', compact('product', 'related', 'seo'));
    }

    /**
     * The dish, at this outlet's price.
     *
     * counterPriceFor() rather than selling_price: a shop may carry its own
     * price for a product, and a rich result advertising a figure the page
     * does not show is the kind of mismatch Google penalises - and the kind
     * a customer arrives arguing about.
     *
     * @return array<string, mixed>
     */
    private function productGraph(Shop $shop, Product $product): array
    {
        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $product->name,
            'description' => StorefrontSeo::trim(
                $product->meta_description ?: ($product->short_description ?: $product->description)
            ),
            'image' => $product->imageUrl(),
            'sku' => $product->sku ?: null,
            'brand' => $product->brand ? ['@type' => 'Brand', 'name' => $product->brand->name] : null,
            'offers' => [
                '@type' => 'Offer',
                'url' => route('shop.product', [$shop, $product]),
                'price' => number_format($product->counterPriceFor($shop->id), 2, '.', ''),
                'priceCurrency' => $shop->currency ?: 'INR',
                'availability' => 'https://schema.org/InStock',
                'seller' => ['@type' => 'Restaurant', 'name' => $shop->name],
            ],
        ], static fn ($value) => $value !== null && $value !== '');
    }
}
