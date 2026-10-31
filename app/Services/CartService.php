<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Shop;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The storefront cart, for a guest or a signed-in customer alike.
 *
 * A guest's cart lives in the session, keyed per shop so browsing two shops
 * in one browser cannot mix their lines. A signed-in customer's cart lives
 * in the database (Cart/CartItem). Every method here picks the right one by
 * whether a Customer was passed in, so callers never have to branch on it
 * themselves.
 *
 * Prices are never stored on a cart line - every read here re-prices live
 * from Product::counterPriceFor(), so a shelf price change is reflected the
 * next time the cart is viewed rather than going stale.
 */
class CartService
{
    /**
     * The most of one item a single cart line may hold.
     *
     * A restaurant's website is not a wholesale counter; anything bigger is
     * a phone call. Without a ceiling the cart took 1,000,000 biryanis.
     */
    public const MAX_LINE_QUANTITY = 100;

    /**
     * A requested quantity, counted the way the product's unit counts.
     *
     * Whole units round to a whole number (the POS does the same), so 0.001
     * of a pizza is not something a guest can order. Refused, with the
     * reason on the `quantity` field, when it comes to less than the
     * smallest amount the unit sells or more than the line ceiling.
     *
     * @throws ValidationException
     */
    private function counted(Product $product, float $quantity): float
    {
        $unit = $product->unit;
        $counted = $unit ? $unit->normalise($quantity) : round($quantity);
        $smallest = $unit?->allow_decimal ? 10 ** -max(0, (int) $unit->precision) : 1;

        if ($counted < $smallest) {
            throw ValidationException::withMessages([
                'quantity' => $smallest >= 1 ? 'Choose at least 1.' : 'Choose a larger quantity.',
            ]);
        }

        if ($counted > self::MAX_LINE_QUANTITY) {
            throw ValidationException::withMessages([
                'quantity' => sprintf('You can order up to %d of one item online. For more, please call the restaurant.', self::MAX_LINE_QUANTITY),
            ]);
        }

        return $counted;
    }

    /**
     * The cart's contents, with everything a view needs to render it.
     *
     * @return array{items: Collection<int, array<string, mixed>>, subtotal: float, count: float}
     */
    public function contents(Shop $shop, ?Customer $customer, Request $request): array
    {
        $lines = $customer
            ? $this->dbLines($shop, $customer)
            : $this->sessionLines($shop, $request);

        $items = $lines
            ->map(function (array $line) use ($shop) {
                /** @var Product $product */
                $product = $line['product'];
                $quantity = $line['quantity'];
                $price = $product->counterPriceFor($shop->id);

                return [
                    'product' => $product,
                    'quantity' => $quantity,
                    'unit_price' => $price,
                    'line_total' => round($price * $quantity, 2),
                    // A dish has no shelf, so there is no cap to exceed (null).
                    'available_stock' => $product->tracksStock() ? $product->availableStock($shop->id) : null,
                ];
            })
            ->values();

        return [
            'items' => $items,
            'subtotal' => round((float) $items->sum('line_total'), 2),
            'count' => (float) $items->sum('quantity'),
        ];
    }

    public function count(Shop $shop, ?Customer $customer, Request $request): float
    {
        return (float) $this->contents($shop, $customer, $request)['count'];
    }

    public function add(Shop $shop, ?Customer $customer, Request $request, Product $product, float $quantity): void
    {
        $quantity = $this->counted($product, $quantity);

        if ($customer) {
            $cart = $this->cartFor($shop, $customer);
            $item = $cart->items()->where('product_id', $product->id)->first();

            // The line as it will stand, so adding a little at a time cannot pass the ceiling.
            $total = $this->counted($product, (float) ($item?->quantity ?? 0) + $quantity);

            $item
                ? $item->update(['quantity' => $total])
                : $cart->items()->create(['product_id' => $product->id, 'quantity' => $total]);

            return;
        }

        $session = $this->sessionCart($shop, $request);
        $session[$product->id] = $this->counted($product, (float) ($session[$product->id] ?? 0) + $quantity);
        $this->putSessionCart($shop, $request, $session);
    }

    public function update(Shop $shop, ?Customer $customer, Request $request, Product $product, float $quantity): void
    {
        if ($quantity <= 0) {
            $this->remove($shop, $customer, $request, $product);

            return;
        }

        $quantity = $this->counted($product, $quantity);

        if ($customer) {
            $this->cartFor($shop, $customer)->items()->updateOrCreate(
                ['product_id' => $product->id],
                ['quantity' => $quantity],
            );

            return;
        }

        $session = $this->sessionCart($shop, $request);
        $session[$product->id] = $quantity;
        $this->putSessionCart($shop, $request, $session);
    }

    public function remove(Shop $shop, ?Customer $customer, Request $request, Product $product): void
    {
        if ($customer) {
            $this->cartFor($shop, $customer)->items()->where('product_id', $product->id)->delete();

            return;
        }

        $session = $this->sessionCart($shop, $request);
        unset($session[$product->id]);
        $this->putSessionCart($shop, $request, $session);
    }

    public function clear(Shop $shop, ?Customer $customer, Request $request): void
    {
        if ($customer) {
            $this->cartFor($shop, $customer)->items()->delete();

            return;
        }

        $request->session()->forget($this->sessionKey($shop));
    }

    /**
     * Fold a guest's session cart into their new account, right after
     * login/registration - called once, from Shop\Auth\LoginController and
     * RegisterController.
     */
    public function mergeIntoCustomer(Shop $shop, Customer $customer, Request $request): void
    {
        $session = $this->sessionCart($shop, $request);

        if (empty($session)) {
            return;
        }

        $cart = $this->cartFor($shop, $customer);

        foreach ($session as $productId => $quantity) {
            $item = $cart->items()->where('product_id', $productId)->first();

            // Two carts that are each fine can add up to more than a line may
            // hold. Held at the ceiling rather than refused: signing in must
            // never fail over a cart.
            $total = min(self::MAX_LINE_QUANTITY, (float) ($item?->quantity ?? 0) + (float) $quantity);

            $item
                ? $item->update(['quantity' => $total])
                : $cart->items()->create(['product_id' => $productId, 'quantity' => $total]);
        }

        $request->session()->forget($this->sessionKey($shop));
    }

    /* ------------------------------------------------------------ internals */

    private function cartFor(Shop $shop, Customer $customer): Cart
    {
        return Cart::query()->firstOrCreate([
            'shop_id' => $shop->id,
            'customer_id' => $customer->id,
        ]);
    }

    /**
     * @return Collection<int, array{product: Product, quantity: float}>
     */
    private function dbLines(Shop $shop, Customer $customer): Collection
    {
        $cart = $this->cartFor($shop, $customer);

        return $cart->items()
            ->with('product.taxRate')
            ->get()
            ->filter(fn ($item) => $item->product !== null)
            ->map(fn ($item) => ['product' => $item->product, 'quantity' => (float) $item->quantity]);
    }

    /**
     * @return Collection<int, array{product: Product, quantity: float}>
     */
    private function sessionLines(Shop $shop, Request $request): Collection
    {
        $session = $this->sessionCart($shop, $request);

        if (empty($session)) {
            return collect();
        }

        $products = Product::query()->with('taxRate')->whereIn('id', array_keys($session))->get()->keyBy('id');

        return collect($session)
            ->filter(fn ($quantity, $productId) => $products->has($productId))
            ->map(fn ($quantity, $productId) => ['product' => $products[$productId], 'quantity' => (float) $quantity])
            ->values();
    }

    /** @return array<int, float> */
    private function sessionCart(Shop $shop, Request $request): array
    {
        return $request->session()->get($this->sessionKey($shop), []);
    }

    /** @param  array<int, float>  $cart */
    private function putSessionCart(Shop $shop, Request $request, array $cart): void
    {
        $cart = array_filter($cart, fn ($quantity) => $quantity > 0);
        $request->session()->put($this->sessionKey($shop), $cart);
    }

    private function sessionKey(Shop $shop): string
    {
        return "storefront.cart.{$shop->id}";
    }
}
