<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', $shop->name) &middot; {{ $shop->name }}</title>

    @php $shopCss = public_path('assets/css/shop.css'); @endphp
    <link rel="stylesheet" href="{{ asset('assets/css/app.css') }}?v={{ filemtime(public_path('assets/css/app.css')) }}">
    <link rel="stylesheet" href="{{ asset('assets/css/shop.css') }}?v={{ file_exists($shopCss) ? filemtime($shopCss) : 1 }}">
    @stack('styles')
</head>
<body class="shop-body">

<header class="shop-header">
    <div class="shop-container shop-header-inner">
        <div class="shop-header-main">
            <a href="{{ route('shop.home', $shop) }}" class="shop-logo" aria-label="{{ $shop->name }} home">
                @if ($shop->storefrontLogoUrl())
                    <img src="{{ $shop->storefrontLogoUrl() }}" alt="{{ $shop->name }}" class="shop-header-logo">
                @else
                    {{ $shop->name }}
                @endif
            </a>

            <div class="shop-header-actions">
                @if (filled($shop->phone))
                    <a href="tel:{{ preg_replace('/[^0-9+]/', '', $shop->phone) }}" class="shop-phone-link"
                       aria-label="Call {{ $shop->name }} at {{ $shop->phone }}">
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="M6.6 10.8a15.5 15.5 0 0 0 6.6 6.6l2.2-2.2a1 1 0 0 1 1.02-.24c1.12.37 2.32.57 3.58.57a1 1 0 0 1 1 1V20a1 1 0 0 1-1 1C10.6 21 3 13.4 3 4a1 1 0 0 1 1-1h3.5a1 1 0 0 1 1 1c0 1.26.2 2.46.57 3.58a1 1 0 0 1-.25 1.02z"/>
                        </svg>
                        <span>{{ $shop->phone }}</span>
                    </a>
                @else
                    <span class="shop-phone-unavailable" title="Phone number not configured" aria-label="Phone number not configured">
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="M6.6 10.8a15.5 15.5 0 0 0 6.6 6.6l2.2-2.2a1 1 0 0 1 1.02-.24c1.12.37 2.32.57 3.58.57a1 1 0 0 1 1 1V20a1 1 0 0 1-1 1C10.6 21 3 13.4 3 4a1 1 0 0 1 1-1h3.5a1 1 0 0 1 1 1c0 1.26.2 2.46.57 3.58a1 1 0 0 1-.25 1.02z"/>
                        </svg>
                    </span>
                @endif

                <a href="{{ route('shop.cart', $shop) }}" class="shop-cart-badge">
                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <path d="M3 4h2l2.1 10.1a2 2 0 0 0 2 1.6h8.8a2 2 0 0 0 1.95-1.55L21 8H6M10 20a1 1 0 1 0 0-2 1 1 0 0 0 0 2Zm8 0a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z"/>
                    </svg>
                    <span>Cart</span>
                    @php
                        $cartCount = app(\App\Services\CartService::class)->count($shop, auth('customer')->user(), request());
                    @endphp
                    @if ($cartCount > 0)
                        <span class="shop-cart-count">{{ (int) $cartCount }}</span>
                    @endif
                </a>

                @auth('customer')
                    <a href="{{ route('shop.wishlist.index', $shop) }}" class="shop-header-secondary">Wishlist</a>
                    <a href="{{ route('shop.orders.index', $shop) }}" class="shop-header-secondary">Orders</a>
                    <a href="{{ route('shop.account.edit', $shop) }}" class="shop-header-secondary">{{ auth('customer')->user()->name }}</a>
                    <form method="POST" action="{{ route('shop.logout', $shop) }}" class="shop-header-logout">
                        @csrf
                        <button type="submit" class="shop-btn shop-btn-outline shop-btn-sm">Sign out</button>
                    </form>
                @else
                    <a href="{{ route('shop.login', $shop) }}" class="shop-btn shop-btn-outline shop-btn-sm">Sign in</a>
                @endauth
            </div>
        </div>

        <nav class="shop-nav" aria-label="Menu categories">
            <a href="{{ route('shop.catalog', $shop) }}" class="shop-nav-all {{ request()->routeIs('shop.catalog') ? 'active' : '' }}">Full menu</a>
            @foreach ($shopHeaderCategories as $category)
                <a href="{{ route('shop.category', [$shop, $category]) }}"
               data-shop-category
               class="{{ request()->route('category')?->id === $category->id ? 'active' : '' }}">
                    {{ $category->name }}
                </a>
            @endforeach
        </nav>
    </div>
</header>

<main class="shop-page @yield('page-class')">
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
    <div class="shop-container shop-footer-grid">
        <div class="shop-footer-brand">
            @if ($shop->storefrontFooterLogoUrl() || $shop->storefrontLogoUrl())
                <img src="{{ $shop->storefrontFooterLogoUrl() ?? $shop->storefrontLogoUrl() }}"
                     alt="{{ $shop->name }}" class="shop-footer-logo">
            @else
                <strong>{{ $shop->name }}</strong>
            @endif
            <span>&copy; {{ now()->year }} {{ $shop->name }}.</span>
        </div>

        <div class="shop-footer-contact">
            <strong>Contact</strong>
            @if (filled($shop->phone))
                <a href="tel:{{ preg_replace('/[^0-9+]/', '', $shop->phone) }}">{{ $shop->phone }}</a>
            @endif
            @if (filled($shop->email))
                <a href="mailto:{{ $shop->email }}">{{ $shop->email }}</a>
            @endif
            @if (filled($shop->addressLine()))
                <span>{{ $shop->addressLine() }}</span>
            @endif
        </div>

        <nav class="shop-footer-links" aria-label="Shop">
            <strong>Explore</strong>
            <a href="{{ route('shop.home', $shop) }}">Home</a>
            <a href="{{ route('shop.catalog', $shop) }}">Full menu</a>
            <a href="{{ route('shop.cart', $shop) }}">Cart</a>
        </nav>
    </div>
</footer>

<script src="{{ asset('assets/js/app.js') }}?v={{ filemtime(public_path('assets/js/app.js')) }}"></script>
@stack('scripts')
</body>
</html>
