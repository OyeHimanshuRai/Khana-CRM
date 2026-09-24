@extends('admin.layouts.app')

@section('title', 'Collections')

@section('content')
    <x-page-header
        title="Collections"
        :subtitle="$stats['total'].' collection'.($stats['total'] === 1 ? '' : 's')"
        :crumbs="['Content' => null, 'Collections' => null]"
    >
        <x-slot:actions>
            @allows('content.collections.create')
                {{-- Opens the blank form in a modal; the href is the no-JS path. --}}
                <a class="btn btn-primary btn-sm" href="{{ route('admin.collections.create') }}"
                   data-modal="{{ route('admin.collections.create') }}"
                   data-modal-title="Add Collection"
                   data-modal-sub="Create a new collection"
                   data-modal-size="lg">
                    <x-icon name="plus" :size="15" /> Add Collection
                </a>
            @endallows
        </x-slot:actions>
    </x-page-header>

    <div class="stat-grid">
        <div class="stat">
            <div class="stat-icon"><x-icon name="grid" :size="21" /></div>
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
            <div class="stat-icon is-warning"><x-icon name="star" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Featured</div>
                <div class="stat-value">{{ number_format($stats['featured']) }}</div>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon is-info"><x-icon name="package" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Media Files</div>
                <div class="stat-value">{{ number_format($stats['media']) }}</div>
            </div>
        </div>
    </div>

    {{--
        data-ajax-list turns the controls below into no-reload filters:
        ajax-list.js collects every [data-ajax-filter], fetches the fragment
        and swaps [data-ajax-list-content]. The <form> wrapper and the plain
        page links remain as the no-JavaScript fallback.
    --}}
    <div class="card" data-ajax-list="{{ route('admin.collections.index') }}">
        <form method="GET" class="list-toolbar">
            <div class="list-search">
                <x-icon name="search" :size="15" />
                <label for="collection-search" class="sr-only">Search collections</label>
                <input id="collection-search" type="search" name="q" value="{{ $search }}"
                       placeholder="Search name, slug or description…" autocomplete="off" data-ajax-filter>
            </div>

            <div class="list-filters">
                <label for="f-status" class="sr-only">Status</label>
                <select id="f-status" name="status" data-ajax-filter>
                    <option value="">Any status</option>
                    <option value="active" @selected($status === 'active')>Active</option>
                    <option value="inactive" @selected($status === 'inactive')>Inactive</option>
                </select>

                <label for="f-featured" class="sr-only">Featured</label>
                <select id="f-featured" name="featured" data-ajax-filter>
                    <option value="">Featured &amp; not</option>
                    <option value="yes" @selected($featured === 'yes')>Featured only</option>
                    <option value="no" @selected($featured === 'no')>Not featured</option>
                </select>

                <label for="f-sort" class="sr-only">Sort by</label>
                <select id="f-sort" name="sort" data-ajax-filter>
                    <option value="order" @selected($sort === 'order')>Display order</option>
                    <option value="name_asc" @selected($sort === 'name_asc')>Name A–Z</option>
                    <option value="name_desc" @selected($sort === 'name_desc')>Name Z–A</option>
                    <option value="newest" @selected($sort === 'newest')>Newest first</option>
                    <option value="oldest" @selected($sort === 'oldest')>Oldest first</option>
                </select>

                <noscript><button type="submit" class="btn btn-sm">Filter</button></noscript>

                <a class="btn btn-sm btn-ghost" href="{{ route('admin.collections.index') }}" data-ajax-reset>Reset</a>
            </div>
        </form>

        <div data-ajax-list-content>
            @include('admin.collections._list')
        </div>
    </div>
@endsection
