@extends('shop.layouts.app')

@section('content')
    <div class="shop-panel shop-panel-pad" style="margin-bottom:28px;background:var(--shop-brand-soft);border:none;">
        <h1 class="shop-h1" style="margin-bottom:6px;">{{ $shop->name }}</h1>
        <p style="color:var(--shop-text);margin:0;">Fresh stock, straight from the shop to your farm.</p>
    </div>

    @if ($categories->isNotEmpty())
        <h2 class="shop-h2">Shop by category</h2>
        <div style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:32px;">
            @foreach ($categories as $category)
                <a href="{{ route('shop.category', [$shop, $category]) }}" class="shop-badge shop-badge-muted">{{ $category->name }}</a>
            @endforeach
        </div>
    @endif

    @if ($featured->isNotEmpty())
        <h2 class="shop-h2">Featured products</h2>
        <div class="shop-grid" style="margin-bottom:36px;">
            @foreach ($featured as $product)
                @include('shop.catalog._product-card', ['product' => $product])
            @endforeach
        </div>
    @endif

    <h2 class="shop-h2">New arrivals</h2>
    @if ($latest->isEmpty())
        <div class="shop-empty">No products published yet.</div>
    @else
        <div class="shop-grid">
            @foreach ($latest as $product)
                @include('shop.catalog._product-card', ['product' => $product])
            @endforeach
        </div>
    @endif
@endsection
