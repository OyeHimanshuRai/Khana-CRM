<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Shop;
use App\Models\WishlistItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class WishlistController extends Controller
{
    public function index(Shop $shop): View
    {
        $items = WishlistItem::query()
            ->where('shop_id', $shop->id)
            ->where('customer_id', Auth::guard('customer')->id())
            ->with('product.images', 'product.unit')
            ->latest()
            ->get()
            ->filter(fn (WishlistItem $item) => $item->product !== null);

        return view('shop.wishlist.index', compact('items'));
    }

    public function toggle(Request $request, Shop $shop, Product $product): RedirectResponse|JsonResponse
    {
        /*
         | The product has to be one this storefront actually sells.
         |
         | Route-model binding resolves a {product} by id, and a shopper is
         | authenticated on the `customer` guard - so the catalogue's own
         | company scope steps aside, exactly as it does for a guest. Without
         | this line another business's dish could be saved to a wishlist by
         | typing its id, and would then be rendered on this page. The cart
         | and the product page already ask the same question.
         */
        abort_unless(
            Product::query()->availableAt($shop->id)->whereKey($product->id)->exists(),
            404,
        );

        $customerId = Auth::guard('customer')->id();

        $existing = WishlistItem::query()
            ->where('shop_id', $shop->id)
            ->where('customer_id', $customerId)
            ->where('product_id', $product->id)
            ->first();

        if ($existing) {
            $existing->delete();
            $added = false;
        } else {
            WishlistItem::query()->create([
                'shop_id' => $shop->id,
                'customer_id' => $customerId,
                'product_id' => $product->id,
            ]);
            $added = true;
        }

        if ($request->expectsJson()) {
            return json_success($added ? 'Added to wishlist.' : 'Removed from wishlist.', ['added' => $added]);
        }

        return back()->with('status', $added ? 'Added to wishlist.' : 'Removed from wishlist.');
    }
}
