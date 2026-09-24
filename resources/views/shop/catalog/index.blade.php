@extends('shop.layouts.app')

@section('title', $category?->name ?? 'All products')

@section('content')
    <div class="shop-crumbs">
        <a href="{{ route('shop.home', $shop) }}">Home</a>
        @if ($category) &rsaquo; {{ $category->name }} @endif
    </div>

    <h1 class="shop-h1">{{ $category?->name ?? 'All products' }}</h1>

    <div class="shop-layout">
        <aside class="shop-panel shop-panel-pad shop-filters">
            <form method="GET">
                @if (request('q'))
                    <input type="hidden" name="q" value="{{ request('q') }}">
                @endif

                <div class="shop-filter-group">
                    <label>Category</label>
                    <div class="shop-filter-links">
                        <a href="{{ route('shop.catalog', $shop) }}" class="{{ ! $category ? 'active' : '' }}">All</a>
                        @foreach ($categories as $cat)
                            <a href="{{ route('shop.category', [$shop, $cat]) }}" class="{{ $category?->id === $cat->id ? 'active' : '' }}">{{ $cat->name }}</a>
                        @endforeach
                    </div>
                </div>

                <div class="shop-filter-group">
                    <label for="brand_id">Brand</label>
                    <select name="brand_id" id="brand_id" onchange="this.form.submit()">
                        <option value="">All brands</option>
                        @foreach ($brands as $brand)
                            <option value="{{ $brand->id }}" @selected(request('brand_id') == $brand->id)>{{ $brand->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="shop-filter-group">
                    <label>Price</label>
                    <div style="display:flex;gap:8px;">
                        <input type="number" name="min_price" placeholder="Min" value="{{ request('min_price') }}" style="width:100%;padding:6px;border:1px solid var(--shop-border);border-radius:6px;">
                        <input type="number" name="max_price" placeholder="Max" value="{{ request('max_price') }}" style="width:100%;padding:6px;border:1px solid var(--shop-border);border-radius:6px;">
                    </div>
                </div>

                <div class="shop-filter-group">
                    <label for="sort">Sort by</label>
                    <select name="sort" id="sort" onchange="this.form.submit()">
                        <option value="">Relevance</option>
                        <option value="price_asc" @selected(request('sort') === 'price_asc')>Price: Low to High</option>
                        <option value="price_desc" @selected(request('sort') === 'price_desc')>Price: High to Low</option>
                    </select>
                </div>

                <button type="submit" class="shop-btn shop-btn-outline shop-btn-block shop-btn-sm">Apply</button>
            </form>
        </aside>

        <div>
            @if ($products->isEmpty())
                <div class="shop-empty">No products match your search.</div>
            @else
                <div class="shop-grid">
                    @foreach ($products as $product)
                        @include('shop.catalog._product-card', ['product' => $product])
                    @endforeach
                </div>

                <div style="margin-top:24px;">
                    {{ $products->links() }}
                </div>
            @endif
        </div>
    </div>
@endsection
