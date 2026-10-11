<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Shop;
use App\Services\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class CartController extends Controller
{
    public function __construct(private readonly CartService $cart) {}

    public function index(Request $request, Shop $shop): View
    {
        $cart = $this->cart->contents($shop, $this->customer(), $request);

        return view('shop.cart.index', compact('cart'));
    }

    public function add(Request $request, Shop $shop): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'quantity' => ['nullable', 'numeric', 'min:0.001'],
        ]);

        $product = Product::query()->availableAt($shop->id)->published()->findOrFail($data['product_id']);

        $this->cart->add($shop, $this->customer(), $request, $product, (float) ($data['quantity'] ?? 1));

        if ($request->expectsJson()) {
            return json_success('Added to cart.', [
                'count' => $this->cart->count($shop, $this->customer(), $request),
            ]);
        }

        return back()->with('status', 'Added to cart.');
    }

    public function update(Request $request, Shop $shop, Product $product): RedirectResponse|JsonResponse
    {
        $data = $request->validate(['quantity' => ['required', 'numeric', 'min:0']]);

        $this->cart->update($shop, $this->customer(), $request, $product, (float) $data['quantity']);

        if ($request->expectsJson()) {
            return json_success('Cart updated.');
        }

        return back()->with('status', 'Cart updated.');
    }

    public function remove(Request $request, Shop $shop, Product $product): RedirectResponse|JsonResponse
    {
        $this->cart->remove($shop, $this->customer(), $request, $product);

        if ($request->expectsJson()) {
            return json_success('Removed from cart.');
        }

        return back()->with('status', 'Removed from cart.');
    }

    private function customer()
    {
        return Auth::guard('customer')->user();
    }
}
