@extends('shop.layouts.app')

@section('title', $product->name)

@section('content')
    @php
        $stock = $product->availableStock($shop->id);
        $mrp = $product->mrpFor($shop->id);
        $price = $product->counterPriceFor($shop->id);
        $images = $product->images;
    @endphp

    <div class="shop-crumbs">
        <a href="{{ route('shop.home', $shop) }}">Home</a> &rsaquo;
        @if ($product->category)
            <a href="{{ route('shop.category', [$shop, $product->category]) }}">{{ $product->category->name }}</a> &rsaquo;
        @endif
        {{ $product->name }}
    </div>

    <div class="shop-pdp">
        <div class="shop-pdp-media">
            @if ($images->isNotEmpty())
                <img src="{{ $images->first()->url() }}" alt="{{ $product->name }}">
            @else
                <span style="color:var(--shop-muted);">No image</span>
            @endif
        </div>

        <div>
            <h1 class="shop-h1">{{ $product->name }}</h1>

            @if ($product->brand)
                <div style="color:var(--shop-muted);margin-bottom:10px;">{{ $product->brand->name }}</div>
            @endif

            <div style="margin-bottom:14px;">
                <span class="shop-price" style="font-size:22px;">₹{{ number_format($price, 2) }}</span>
                @if ($mrp > $price)
                    <span class="shop-price-mrp">₹{{ number_format($mrp, 2) }}</span>
                @endif
            </div>

            @if ($stock <= 0)
                <div class="shop-badge shop-badge-danger" style="margin-bottom:16px;">Out of stock</div>
            @elseif ($product->isLowStock($shop->id))
                <div class="shop-badge shop-badge-warning" style="margin-bottom:16px;">Only {{ rtrim(rtrim(number_format($stock, 2), '0'), '.') }} {{ $product->unit?->code }} left</div>
            @else
                <div class="shop-badge shop-badge-success" style="margin-bottom:16px;">In stock</div>
            @endif

            <form action="{{ route('shop.cart.add', $shop) }}" method="POST" data-ajax style="display:flex;gap:10px;align-items:center;margin-bottom:22px;">
                @csrf
                <input type="hidden" name="product_id" value="{{ $product->id }}">
                <div class="shop-qty">
                    <button type="button" onclick="this.nextElementSibling.stepDown()">&minus;</button>
                    <input type="number" name="quantity" value="1" min="1" step="1">
                    <button type="button" onclick="this.previousElementSibling.stepUp()">+</button>
                </div>
                <button type="submit" class="shop-btn" @disabled($stock <= 0)>Add to cart</button>

                @auth('customer')
                    <button type="button" class="shop-btn shop-btn-outline"
                        onclick="fetch('{{ route('shop.wishlist.toggle', [$shop, $product]) }}', {method:'POST', headers:{'X-CSRF-TOKEN':'{{ csrf_token() }}','Accept':'application/json'}}).then(r=>r.json()).then(d=>Toast.success(d.message))">
                        ♥ Wishlist
                    </button>
                @endauth
            </form>

            @if ($product->short_description)
                <p>{{ $product->short_description }}</p>
            @endif

            <table style="width:100%;border-collapse:collapse;margin-top:20px;font-size:14px;">
                @if ($product->sku)
                    <tr><td style="padding:6px 0;color:var(--shop-muted);width:160px;">SKU</td><td>{{ $product->sku }}</td></tr>
                @endif
                @if ($product->manufacturer)
                    <tr><td style="padding:6px 0;color:var(--shop-muted);">Manufacturer</td><td>{{ $product->manufacturer }}</td></tr>
                @endif
                @if ($product->foodTypeLabel())
                    <tr>
                        <td style="padding:6px 0;color:var(--shop-muted);">Food type</td>
                        <td>
                            {{-- The mark and the words together: colour alone
                                 is not a label anybody can rely on. --}}
                            <span style="display:inline-block;width:9px;height:9px;border-radius:2px;
                                         background:{{ $product->foodTypeDot() }};margin-right:5px"
                                  aria-hidden="true"></span>
                            {{ $product->foodTypeLabel() }}
                        </td>
                    </tr>
                @endif
                @if ($product->spiceLabel())
                    <tr><td style="padding:6px 0;color:var(--shop-muted);">Spice</td><td>{{ $product->spiceLabel() }}</td></tr>
                @endif
                @if ($product->serves)
                    <tr><td style="padding:6px 0;color:var(--shop-muted);">Serves</td><td>{{ $product->serves }}</td></tr>
                @endif
            </table>

            @if ($product->description)
                <div style="margin-top:20px;">
                    <h2 class="shop-h2">Description</h2>
                    <div>{!! nl2br(e($product->description)) !!}</div>
                </div>
            @endif

            @if (filled($product->food_tags))
                <div style="margin-top:20px;">
                    <h2 class="shop-h2">Good to know</h2>
                    <div style="display:flex;flex-wrap:wrap;gap:6px;">
                        @foreach ($product->food_tags as $tag)
                            <span class="shop-badge">{{ $tag }}</span>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </div>

    @if ($related->isNotEmpty())
        <h2 class="shop-h2" style="margin-top:40px;">You may also like</h2>
        <div class="shop-grid">
            @foreach ($related as $item)
                @include('shop.catalog._product-card', ['product' => $item])
            @endforeach
        </div>
    @endif
@endsection
