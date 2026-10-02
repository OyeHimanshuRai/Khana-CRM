@php
    $mrp = $product->mrpFor($shop->id);
    $price = $product->counterPriceFor($shop->id);
    $image = $product->images->first()?->url();
    // At this outlet: taken off by hand, or out of stock here. A guest is
    // not signed in, so the outlet is named rather than assumed.
    $soldOut = $product->isSoldOut(null, $shop->id);
@endphp

<div class="shop-card">
    <a href="{{ route('shop.product', [$shop, $product]) }}" class="shop-card-media">
        @if ($image)
            <img src="{{ $image }}" alt="{{ $product->name }}">
        @else
            <span style="color:var(--shop-muted);font-size:12px;">No image</span>
        @endif
    </a>

    <div class="shop-card-body">
        <a href="{{ route('shop.product', [$shop, $product]) }}" class="shop-card-name">{{ $product->name }}</a>

        @if ($product->brand)
            <div class="shop-card-meta">{{ $product->brand->name }}</div>
        @endif

        <div>
            <span class="shop-price">₹{{ number_format($price, 2) }}</span>
            @if ($mrp > $price)
                <span class="shop-price-mrp">₹{{ number_format($mrp, 2) }}</span>
            @endif
        </div>

        @if ($soldOut)
            <div class="shop-stock-out">Out of stock</div>
        @endif

        <form action="{{ route('shop.cart.add', $shop) }}" method="POST" data-ajax data-toast-success="false">
            @csrf
            <input type="hidden" name="product_id" value="{{ $product->id }}">
            <input type="hidden" name="quantity" value="1">
            <button type="submit" class="shop-btn shop-btn-block shop-btn-sm" @disabled($soldOut)>
                {{ $soldOut ? 'Out of stock' : 'Add to cart' }}
            </button>
        </form>
    </div>
</div>
