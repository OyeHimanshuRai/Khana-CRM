@extends('shop.layouts.app')

@section('page-class', 'shop-page-home')

@section('content')
    <section class="shop-home-hero {{ $slides->count() > 1 ? 'has-multiple-slides' : '' }}"
             @if ($slides->count() > 1) data-shop-carousel aria-label="{{ $shop->name }} highlights" aria-roledescription="carousel" @endif>
        @if ($slides->isNotEmpty())
            @foreach ($slides as $slide)
                <article class="shop-home-slide {{ $loop->first ? 'is-active' : '' }}"
                         @if ($slides->count() > 1) data-shop-slide role="group" aria-roledescription="slide"
                         aria-label="{{ $loop->iteration }} of {{ $slides->count() }}"
                         aria-hidden="{{ $loop->first ? 'false' : 'true' }}" @endif>
                    <picture class="shop-home-slide-media">
                        @if ($slide->mobile_image_path)
                            <source media="(max-width: 640px)" srcset="{{ $slide->imageUrl('mobile') }}">
                        @endif
                        <img src="{{ $slide->imageUrl() }}" alt="" @if ($loop->first) fetchpriority="high" @else loading="lazy" @endif>
                    </picture>
                    <div class="shop-home-slide-shade" aria-hidden="true"></div>
                    <div class="shop-home-hero-copy">
                        <span class="shop-home-kicker">{{ $shop->storefront_tagline ?: 'A good meal starts here' }}</span>
                        <h1>{{ $slide->title }}</h1>
                        @if (filled($slide->description))
                            <p>{{ $slide->description }}</p>
                        @endif
                        <div class="shop-home-hero-actions">
                            @if (filled($slide->button_label))
                                <a href="{{ $slide->button_url ?: route('shop.catalog', $shop) }}" class="shop-btn shop-home-hero-button">
                                    {{ $slide->button_label }} <span aria-hidden="true">&rarr;</span>
                                </a>
                            @else
                                <a href="{{ route('shop.catalog', $shop) }}" class="shop-btn shop-home-hero-button">
                                    Explore the menu <span aria-hidden="true">&rarr;</span>
                                </a>
                            @endif
                            @if ($categories->isNotEmpty())
                                <a href="#shop-categories" class="shop-home-text-link">See our categories</a>
                            @endif
                        </div>
                        @if (filled($shop->phone) || filled($shop->addressLine()))
                            <div class="shop-home-contact">
                                @if (filled($shop->phone))
                                    <a href="tel:{{ preg_replace('/[^0-9+]/', '', $shop->phone) }}">
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6.6 10.8a15.5 15.5 0 0 0 6.6 6.6l2.2-2.2a1 1 0 0 1 1.02-.24c1.12.37 2.32.57 3.58.57a1 1 0 0 1 1 1V20a1 1 0 0 1-1 1C10.6 21 3 13.4 3 4a1 1 0 0 1 1-1h3.5a1 1 0 0 1 1 1c0 1.26.2 2.46.57 3.58a1 1 0 0 1-.25 1.02z"/></svg>
                                        <span>{{ $shop->phone }}</span>
                                    </a>
                                @endif
                                @if (filled($shop->addressLine()))
                                    <span class="shop-home-address">
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 10c0 5-8 12-8 12S4 15 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/></svg>
                                        <span>{{ $shop->addressLine() }}</span>
                                    </span>
                                @endif
                            </div>
                        @endif
                    </div>
                </article>
            @endforeach
        @else
            <div class="shop-home-slide is-active">
                <img class="shop-home-hero-image" src="{{ asset('assets/img/shop-hero-food.jpg') }}"
                     alt="" aria-hidden="true" fetchpriority="high">
                <div class="shop-home-slide-shade" aria-hidden="true"></div>
                <div class="shop-home-hero-copy">
                    <span class="shop-home-kicker">{{ $shop->storefront_tagline ?: 'A good meal starts here' }}</span>
                    <h1>{{ $shop->name }}</h1>
                    <p>Freshly made favorites, ready when you are. Find your next go-to dish from our kitchen.</p>
                    <div class="shop-home-hero-actions">
                        <a href="{{ route('shop.catalog', $shop) }}" class="shop-btn shop-home-hero-button">Explore the menu <span aria-hidden="true">&rarr;</span></a>
                        @if ($categories->isNotEmpty())
                            <a href="#shop-categories" class="shop-home-text-link">See our categories</a>
                        @endif
                    </div>
                    @if (filled($shop->phone) || filled($shop->addressLine()))
                        <div class="shop-home-contact">
                            @if (filled($shop->phone))
                                <a href="tel:{{ preg_replace('/[^0-9+]/', '', $shop->phone) }}">
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6.6 10.8a15.5 15.5 0 0 0 6.6 6.6l2.2-2.2a1 1 0 0 1 1.02-.24c1.12.37 2.32.57 3.58.57a1 1 0 0 1 1 1V20a1 1 0 0 1-1 1C10.6 21 3 13.4 3 4a1 1 0 0 1 1-1h3.5a1 1 0 0 1 1 1c0 1.26.2 2.46.57 3.58a1 1 0 0 1-.25 1.02z"/></svg>
                                    <span>{{ $shop->phone }}</span>
                                </a>
                            @endif
                            @if (filled($shop->addressLine()))
                                <span class="shop-home-address">
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 10c0 5-8 12-8 12S4 15 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/></svg>
                                    <span>{{ $shop->addressLine() }}</span>
                                </span>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        @endif

        @if ($slides->count() > 1)
            <div class="shop-home-carousel-controls" aria-label="Slider controls">
                <button type="button" data-shop-slide-prev aria-label="Previous slide">
                    <span aria-hidden="true">&larr;</span>
                </button>
                <div class="shop-home-carousel-dots" aria-label="Choose a slide">
                    @foreach ($slides as $slide)
                        <button type="button" data-shop-slide-to="{{ $loop->index }}"
                                aria-label="Show slide {{ $loop->iteration }}"
                                aria-current="{{ $loop->first ? 'true' : 'false' }}"></button>
                    @endforeach
                </div>
                <button type="button" data-shop-slide-next aria-label="Next slide">
                    <span aria-hidden="true">&rarr;</span>
                </button>
            </div>
        @endif
    </section>

    @if ($categories->isNotEmpty())
        <section class="shop-home-section" id="shop-categories">
            <div class="shop-home-section-heading">
                <div>
                    <span class="shop-home-kicker">Something for every craving</span>
                    <h2 class="shop-h2">Explore our menu</h2>
                </div>
                <span class="shop-home-heading-note">Made fresh. Served with love.</span>
            </div>
            <div class="shop-home-categories">
                @foreach ($categories as $category)
                    <a href="{{ route('shop.category', [$shop, $category]) }}" class="shop-home-category">
                        <span class="shop-home-category-mark" aria-hidden="true">✳</span>
                        <span>{{ $category->name }}</span>
                        <span class="shop-home-category-arrow" aria-hidden="true">&rarr;</span>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    @if ($featured->isNotEmpty())
        <section class="shop-home-section">
            <div class="shop-home-section-heading">
                <div>
                    <span class="shop-home-kicker">A few things we love</span>
                    <h2 class="shop-h2">House favorites</h2>
                </div>
                <a href="{{ route('shop.catalog', $shop) }}" class="shop-home-view-all">Full menu <span aria-hidden="true">&rarr;</span></a>
            </div>
            <div class="shop-grid">
                @foreach ($featured as $product)
                    @include('shop.catalog._product-card', ['product' => $product])
                @endforeach
            </div>
        </section>
    @endif

    <section class="shop-home-section">
        <div class="shop-home-section-heading">
            <div>
                <span class="shop-home-kicker">Just out of the kitchen</span>
                <h2 class="shop-h2">Fresh from our kitchen</h2>
            </div>
            <a href="{{ route('shop.catalog', $shop) }}" class="shop-home-view-all">Full menu <span aria-hidden="true">&rarr;</span></a>
        </div>
        @if ($latest->isEmpty())
            <div class="shop-empty">No products published yet.</div>
        @else
            <div class="shop-grid">
                @foreach ($latest as $product)
                    @include('shop.catalog._product-card', ['product' => $product])
                @endforeach
            </div>
        @endif
    </section>
@endsection

@push('scripts')
    <script src="{{ asset('assets/js/shop-hero.js') }}?v={{ filemtime(public_path('assets/js/shop-hero.js')) }}" defer></script>
@endpush
