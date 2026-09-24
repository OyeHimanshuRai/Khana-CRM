@extends('admin.layouts.app')

@section('title', 'Recipes')

@section('content')
    <x-page-header
        title="Recipes"
        subtitle="What each dish is made of, what it costs to make, and what leaves the store when it is cooked."
        :crumbs="['Operations' => null, 'Recipes' => null]"
    >
        <x-slot:actions>
            @allows('inventory.recipes.export')
                <a class="btn btn-sm" href="{{ route('admin.recipes.export') }}">
                    <x-icon name="download" :size="15" /> Export CSV
                </a>
            @endallows

            @allows('inventory.products.view')
                <a class="btn btn-sm" href="{{ route('admin.products.index') }}">
                    <x-icon name="package" :size="15" /> Menu items
                </a>
            @endallows
        </x-slot:actions>
    </x-page-header>

    <div class="stat-grid">
        <div class="stat">
            <div class="stat-icon is-info"><x-icon name="package" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Dishes costed</div>
                <div class="stat-value">{{ number_format($stats['costed']) }}</div>
                <span class="text-xs text-muted">of {{ number_format($stats['dishes']) }} made to order</span>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon"><x-icon name="tag" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Ingredients</div>
                <div class="stat-value">{{ number_format($stats['ingredients']) }}</div>
                <span class="text-xs text-muted">stocked, not on the menu</span>
            </div>
        </div>

        {{--
            Not a tile but a statement: when the kitchen is deemed to have used
            its ingredients. It is the single setting that decides whether any
            of this moves stock at all, and burying it in Settings would mean
            nobody looking at this screen knows which way it is set.
        --}}
        <div class="stat">
            <div class="stat-icon {{ $moment === 'off' ? '' : 'is-success' }}">
                <x-icon name="clock" :size="21" />
            </div>
            <div class="stat-body">
                <div class="stat-label">Stock comes off</div>
                <div class="stat-value" style="font-size:15px; line-height:1.35">
                    {{ $moments[$moment] ?? $moment }}
                </div>
                @allows('settings.shops.edit')
                    <span class="text-xs text-muted">Change it on the outlet</span>
                @endallows
            </div>
        </div>
    </div>

    <div class="card" data-ajax-list="{{ route('admin.recipes.index') }}">
        <form method="GET" class="list-toolbar">
            <div class="list-search">
                <x-icon name="search" :size="15" />
                <label for="rc-search" class="sr-only">Search dishes</label>
                <input id="rc-search" type="search" name="q" value="{{ $search }}"
                       placeholder="Search dish, SKU or code…" autocomplete="off" data-ajax-filter>
            </div>

            <div class="list-filters">
                <label for="rc-has" class="sr-only">Has a recipe</label>
                <select id="rc-has" name="has" data-ajax-filter>
                    <option value="">Every dish</option>
                    <option value="yes" @selected($has === 'yes')>Has a recipe</option>
                    <option value="no" @selected($has === 'no')>No recipe yet</option>
                </select>

                <noscript><button type="submit" class="btn btn-sm">Filter</button></noscript>

                <a class="btn btn-sm btn-ghost" href="{{ route('admin.recipes.index') }}" data-ajax-reset>Reset</a>
            </div>
        </form>

        <div data-ajax-list-content>
            @include('admin.recipes._list')
        </div>
    </div>
@endsection
