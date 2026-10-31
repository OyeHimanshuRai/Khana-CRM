@extends('table.layout')

@section('title', 'Menu · Table '.($table?->name ?? ''))

@section('content')
    <header class="t-head">
        <div class="t-head-brand">{{ $shop?->name ?: $company }}</div>

        <h1 class="t-head-table">Table {{ $table?->name }}</h1>

        <p class="t-head-area">
            @if ($floor){{ $floor->name }} · @endif
            Seated {{ $session->opened_at?->format('g:i a') }}
        </p>
    </header>

    @include('table._flash')

    @if ($sections->isEmpty())
        <section class="t-card t-notice">
            <h2>The menu is not up yet</h2>
            <p>Please ask a member of staff — they can take your order.</p>
        </section>
    @else
        {{--
            Category chips, sticky under the header. A card of ninety dishes
            is unusable on a phone without a way to jump, and a <select> is
            not it - a guest scanning for "Desserts" wants to see the sections
            exist before they tap anything.
        --}}
        <nav class="t-nav" aria-label="Menu sections">
            @foreach ($sections as $section)
                <a class="t-chip" href="#sec-{{ $section['category']->id }}">
                    {{ $section['category']->name }}
                </a>
            @endforeach
        </nav>

        @foreach ($sections as $section)
            @php
                $category = $section['category'];
                // Sub-headings within a section, in card order. Null keys are
                // dishes filed directly under the parent.
                $groups = $section['items']->groupBy('category_id');
            @endphp

            <section class="t-section" id="sec-{{ $category->id }}">
                <h2 class="t-section-title">{{ $category->name }}</h2>

                @foreach ($groups as $categoryId => $items)
                    @if ((int) $categoryId !== (int) $category->id)
                        <h3 class="t-subsection">{{ $items->first()->category?->name }}</h3>
                    @endif

                    @foreach ($items as $item)
                        @php
                            // Asked once per row rather than three times in
                            // the markup below.
                            $reason = $item->unavailableReason();
                        @endphp

                        <article class="t-dish @if ($reason) is-off @endif">
                            <div class="t-dish-main">
                                <div class="t-dish-head">
                                    @if ($item->foodTypeDot())
                                        {{-- The mark carries its own label: a
                                             square nobody can read is worse
                                             than no square. --}}
                                        <span class="t-mark" role="img"
                                              aria-label="{{ $item->foodTypeLabel() }}"
                                              title="{{ $item->foodTypeLabel() }}"
                                              style="--mark: {{ $item->foodTypeDot() }}"></span>
                                    @endif

                                    <h4 class="t-dish-name">{{ $item->name }}</h4>
                                </div>

                                <div class="t-dish-price">
                                    {{ $menu->priceLabel($item, 'dine_in', $shop?->id) }}
                                </div>

                                @if ($item->short_description)
                                    <p class="t-dish-note">{{ $item->short_description }}</p>
                                @endif

                                <div class="t-dish-tags">
                                    @if ($item->spiceLabel())
                                        <span class="t-tag">{{ $item->spiceLabel() }}</span>
                                    @endif

                                    @if ($item->serves)
                                        <span class="t-tag">Serves {{ $item->serves }}</span>
                                    @endif

                                    @if ($item->prep_minutes)
                                        <span class="t-tag">{{ $item->prep_minutes }} min</span>
                                    @endif

                                    @foreach ($item->food_tags ?? [] as $tag)
                                        <span class="t-tag">{{ $tag }}</span>
                                    @endforeach
                                </div>

                                @if ($reason)
                                    <p class="t-dish-off">{{ $reason }}</p>
                                @else
                                    @include('table._add', ['item' => $item])
                                @endif
                            </div>

                            @if ($item->imageUrl())
                                <img class="t-dish-img" src="{{ $item->imageUrl() }}"
                                     alt="" loading="lazy" width="88" height="88">
                            @endif
                        </article>
                    @endforeach
                @endforeach
            </section>
        @endforeach
    @endif

    <footer class="t-foot">
        <p>Keep this page open — it is your table for this visit.</p>
    </footer>

    @include('table._cartbar')
@endsection
