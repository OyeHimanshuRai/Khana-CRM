@extends('admin.layouts.app')

@section('title', 'Sliders')

@section('content')
    <x-page-header
        title="Sliders"
        :subtitle="$stats['total'].' slide'.($stats['total'] === 1 ? '' : 's')"
        :crumbs="['Content' => null, 'Sliders' => null]"
    >
        <x-slot:actions>
            @allows('content.sliders.create')
                {{-- Opens the blank form in a modal; the href is the no-JS path. --}}
                <a class="btn btn-primary btn-sm" href="{{ route('admin.sliders.create') }}"
                   data-modal="{{ route('admin.sliders.create') }}"
                   data-modal-title="Add Slider"
                   data-modal-sub="Create a new slide"
                   data-modal-size="lg">
                    <x-icon name="plus" :size="15" /> Add Slider
                </a>
            @endallows
        </x-slot:actions>
    </x-page-header>

    {{--
        Why the landing page has no banner.

        The hero carousel draws the Main Banner layout and nothing else, and
        when none of those slides is active the whole section is left out of
        the page - correctly, because a carousel with no slides is not a
        thing. But from this screen that is invisible: three Main Banner
        slides sit here looking present while the site shows no banner at
        all and says nothing about why.

        So the count the visitor will actually see is stated here, with the
        filter that leads straight to the slides to switch on.
    --}}
    @if ($bannerLive === 0)
        <div class="pos-warning" style="margin-bottom:16px">
            <strong>The landing page has no banner right now.</strong>
            @if ($bannerTotal === 0)
                Nothing has been added to the <strong>Main Banner</strong> layout yet — that is the
                one the hero carousel draws.
            @else
                All {{ $bannerTotal }} <strong>Main Banner</strong> slide{{ $bannerTotal === 1 ? ' is' : 's are' }}
                switched off, so the carousel is not drawn at all.
                <a href="{{ route('admin.sliders.index', ['layout' => 'main_banner']) }}">Show them</a>
                and switch at least one on.
            @endif
        </div>
    @endif

    <div class="stat-grid">
        <div class="stat">
            <div class="stat-icon"><x-icon name="package" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Total</div>
                <div class="stat-value">{{ number_format($stats['total']) }}</div>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon is-success"><x-icon name="user-check" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Active</div>
                <div class="stat-value">{{ number_format($stats['active']) }}</div>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon" style="background: var(--danger-soft); color: var(--danger)">
                <x-icon name="user-x" :size="21" />
            </div>
            <div class="stat-body">
                <div class="stat-label">Inactive</div>
                <div class="stat-value">{{ number_format($stats['inactive']) }}</div>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon is-info"><x-icon name="grid" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Layouts In Use</div>
                <div class="stat-value">{{ number_format($stats['layouts']) }}</div>
            </div>
        </div>

        {{-- The only count that describes what a visitor sees. --}}
        <div class="stat">
            <div class="stat-icon {{ $bannerLive > 0 ? 'is-success' : '' }}"
                 @if ($bannerLive === 0) style="background: var(--danger-soft); color: var(--danger)" @endif>
                <x-icon name="globe" :size="21" />
            </div>
            <div class="stat-body">
                <div class="stat-label">On The Landing Page</div>
                <div class="stat-value">{{ number_format($bannerLive) }}</div>
                <span class="text-xs text-muted">
                    {{ $bannerLive === 0 ? 'no hero carousel' : 'Main Banner slides live' }}
                </span>
            </div>
        </div>
    </div>

    {{--
        data-ajax-list turns the controls below into no-reload filters:
        ajax-list.js collects every [data-ajax-filter], fetches the fragment
        and swaps [data-ajax-list-content]. The <form> wrapper and the plain
        page links remain as the no-JavaScript fallback.
    --}}
    <div class="card" data-ajax-list="{{ route('admin.sliders.index') }}">
        <form method="GET" class="list-toolbar">
            <div class="list-search">
                <x-icon name="search" :size="15" />
                <label for="slider-search" class="sr-only">Search sliders</label>
                <input id="slider-search" type="search" name="q" value="{{ $search }}"
                       placeholder="Search title, description or URL…" autocomplete="off" data-ajax-filter>
            </div>

            <div class="list-filters">
                <label for="f-layout" class="sr-only">Layout</label>
                <select id="f-layout" name="layout" data-ajax-filter>
                    <option value="">All layouts</option>
                    @foreach ($layouts as $key => $label)
                        <option value="{{ $key }}" @selected($layout === $key)>{{ $label }}</option>
                    @endforeach
                </select>

                <label for="f-status" class="sr-only">Status</label>
                <select id="f-status" name="status" data-ajax-filter>
                    <option value="">Any status</option>
                    <option value="active" @selected($status === 'active')>Active</option>
                    <option value="inactive" @selected($status === 'inactive')>Inactive</option>
                </select>

                <label for="f-sort" class="sr-only">Sort by</label>
                <select id="f-sort" name="sort" data-ajax-filter>
                    <option value="order" @selected($sort === 'order')>Layout &amp; item no</option>
                    <option value="title_asc" @selected($sort === 'title_asc')>Title A–Z</option>
                    <option value="title_desc" @selected($sort === 'title_desc')>Title Z–A</option>
                    <option value="newest" @selected($sort === 'newest')>Newest first</option>
                    <option value="oldest" @selected($sort === 'oldest')>Oldest first</option>
                </select>

                <noscript><button type="submit" class="btn btn-sm">Filter</button></noscript>

                <a class="btn btn-sm btn-ghost" href="{{ route('admin.sliders.index') }}" data-ajax-reset>Reset</a>
            </div>
        </form>

        <div data-ajax-list-content>
            @include('admin.sliders._list')
        </div>
    </div>
@endsection
