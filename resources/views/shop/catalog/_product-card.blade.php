@php
    $block = $product->storefrontBlock($shop->id);
    $mrp = $product->mrpFor($shop->id);
    $price = $product->counterPriceFor($shop->id);
    $image = $product->images->first()?->url();
@endphp

<div class="shop-card">
    <a href="{{ route('shop.product', [$shop, $product]) }}" class="shop-card-media">
        @if ($image)
            <img src="{{ $image }}" alt="{{ $product->name }}">
        @else
            <span class="shop-card-placeholder">
                <span class="shop-card-placeholder-mark" aria-hidden="true"></span>
                <span>Image coming soon</span>
            </span>
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

        @if ($block)
            <div class="shop-stock-out">{{ $block }}</div>
        @elseif ($product->isLowStock($shop->id))
            <div class="shop-stock-low">Only a few left</div>
        @endif

        <form action="{{ route('shop.cart.add', $shop) }}" method="POST" data-ajax data-toast-success="false">
            @csrf
            <input type="hidden" name="product_id" value="{{ $product->id }}">
            <input type="hidden" name="quantity" value="1">
            <button type="submit" class="shop-btn shop-btn-block shop-btn-sm" @disabled($block !== null)>
                @if ($block)
                    {{ $block }}
                @else
                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <path d="M3 4h2l2.1 10.1a2 2 0 0 0 2 1.6h8.8a2 2 0 0 0 1.95-1.55L21 8H6M10 20a1 1 0 1 0 0-2 1 1 0 0 0 0 2Zm8 0a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z"/>
                    </svg>
                    Add to cart
                @endif
            </button>
        </form>
    </div>
</div>
