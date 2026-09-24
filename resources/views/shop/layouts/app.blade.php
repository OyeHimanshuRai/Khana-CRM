<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{--
        Composed in one place rather than a block of tags per view: the rules
        are the same on every page of every outlet and only the values differ.
        A view says what it is with @section('title'); a controller that knows
        more - a product's own meta description, a category's canonical, a
        page that must never be indexed - passes $seo.
    --}}
    @php $seo = \App\Support\StorefrontSeo::resolve($shop, $__env->yieldContent('title'), $seo ?? []); @endphp

    <title>{{ $seo['title'] }}</title>
    @if ($seo['description'])
        <meta name="description" content="{{ $seo['description'] }}">
    @endif
    <meta name="robots" content="{{ $seo['robots'] }}">
    <link rel="canonical" href="{{ $seo['canonical'] }}">

    <meta property="og:title" content="{{ $seo['title'] }}">
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ $seo['canonical'] }}">
    <meta property="og:site_name" content="{{ $shop->name }}">
    @if ($seo['description'])
        <meta property="og:description" content="{{ $seo['description'] }}">
    @endif

    {{--
        The picture a link shows in WhatsApp, which is where a shop's address
        is actually sent. Nothing at all when there is no logo: an og:image
        pointing at a missing file renders as a broken box, which is worse
        than a preview with no picture in it.
    --}}
    @if ($seo['image'])
        <meta property="og:image" content="{{ $seo['image'] }}">
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:image" content="{{ $seo['image'] }}">
    @else
        <meta name="twitter:card" content="summary">
    @endif
    <meta name="twitter:title" content="{{ $seo['title'] }}">
    @if ($seo['description'])
        <meta name="twitter:description" content="{{ $seo['description'] }}">
    @endif

    @if ($seo['jsonLd'])
        <script type="application/ld+json">{!! json_encode($seo['jsonLd'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
    @endif

    @php $shopCss = public_path('assets/css/shop.css'); @endphp
    <link rel="stylesheet" href="{{ asset('assets/css/app.css') }}?v={{ filemtime(public_path('assets/css/app.css')) }}">
    <link rel="stylesheet" href="{{ asset('assets/css/shop.css') }}?v={{ file_exists($shopCss) ? filemtime($shopCss) : 1 }}">
    @stack('styles')
</head>
<body class="shop-body">

<header class="shop-header">
    <div class="shop-container shop-header-row">
        <a href="{{ route('shop.home', $shop) }}" class="shop-logo">{{ $shop->name }}</a>

        <nav class="shop-nav">
            <a href="{{ route('shop.catalog', $shop) }}">All products</a>
        </nav>

        <form action="{{ route('shop.catalog', $shop) }}" method="GET" class="shop-search">
            <input type="text" name="q" placeholder="Search products…" value="{{ request('q') }}">
        </form>

        <div class="shop-header-actions">
            <a href="{{ route('shop.cart', $shop) }}" class="shop-cart-badge">
                Cart
                @php
                    $cartCount = app(\App\Services\CartService::class)->count($shop, auth('customer')->user(), request());
                @endphp
                @if ($cartCount > 0)
                    <span class="shop-cart-count">{{ (int) $cartCount }}</span>
                @endif
            </a>

            @auth('customer')
                <a href="{{ route('shop.wishlist.index', $shop) }}">Wishlist</a>
                <a href="{{ route('shop.orders.index', $shop) }}">Orders</a>
                <a href="{{ route('shop.account.edit', $shop) }}">{{ auth('customer')->user()->name }}</a>
                <form method="POST" action="{{ route('shop.logout', $shop) }}" style="display:inline">
                    @csrf
                    <button type="submit" class="shop-btn shop-btn-outline shop-btn-sm">Sign out</button>
                </form>
            @else
                <a href="{{ route('shop.login', $shop) }}" class="shop-btn shop-btn-outline shop-btn-sm">Sign in</a>
            @endauth
        </div>
    </div>
</header>

<main class="shop-page">
    <div class="shop-container">
        @if (session('status'))
            <div class="shop-badge shop-badge-success" style="margin-bottom:16px;display:block;padding:10px 14px;">{{ session('status') }}</div>
        @endif

        @if (session('error'))
            <div class="shop-badge shop-badge-danger" style="margin-bottom:16px;display:block;padding:10px 14px;">{{ session('error') }}</div>
        @endif

        @yield('content')
    </div>
</main>

<footer class="shop-footer">
    <div class="shop-container">
        &copy; {{ now()->year }} {{ $shop->name }}. {{ $shop->addressLine() }}
    </div>
</footer>

<script src="{{ asset('assets/js/app.js') }}?v={{ filemtime(public_path('assets/js/app.js')) }}"></script>
@stack('scripts')
</body>
</html>
