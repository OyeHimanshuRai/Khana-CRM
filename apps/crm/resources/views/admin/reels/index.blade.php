@extends('admin.layouts.app')

@section('title', 'Instagram Reels')

@section('content')
    <x-page-header
        title="Instagram Reels"
        :subtitle="$stats['total'].' reel'.($stats['total'] === 1 ? '' : 's')"
        :crumbs="['Content' => null, 'Instagram' => null, 'Reels' => null]"
    >
        <x-slot:actions>
            @allows('content.reels.create')
                {{-- Opens the blank form in a modal; the href is the no-JS path. --}}
                <a class="btn btn-primary btn-sm" href="{{ route('admin.reels.create') }}"
                   data-modal="{{ route('admin.reels.create') }}"
                   data-modal-title="Add Reel"
                   data-modal-sub="Paste an Instagram reel link"
                   data-modal-size="lg">
                    <x-icon name="plus" :size="15" /> Add Reel
                </a>
            @endallows
        </x-slot:actions>
    </x-page-header>

    <div class="stat-grid">
        <div class="stat">
            <div class="stat-icon"><x-icon name="heart" :size="21" /></div>
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
            <div class="stat-icon is-info"><x-icon name="package" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">With Thumbnail</div>
                <div class="stat-value">{{ number_format($stats['with_thumb']) }}</div>
            </div>
        </div>
    </div>

    <div class="card" data-ajax-list="{{ route('admin.reels.index') }}">
        <form method="GET" class="list-toolbar">
            <div class="list-search">
                <x-icon name="search" :size="15" />
                <label for="reel-search" class="sr-only">Search reels</label>
                <input id="reel-search" type="search" name="q" value="{{ $search }}"
                       placeholder="Search title, description or link…" autocomplete="off" data-ajax-filter>
            </div>

            <div class="list-filters">
                <label for="f-status" class="sr-only">Status</label>
                <select id="f-status" name="status" data-ajax-filter>
                    <option value="">Any status</option>
                    <option value="active" @selected($status === 'active')>Active</option>
                    <option value="inactive" @selected($status === 'inactive')>Inactive</option>
                </select>

                <label for="f-sort" class="sr-only">Sort by</label>
                <select id="f-sort" name="sort" data-ajax-filter>
                    <option value="order" @selected($sort === 'order')>Display order</option>
                    <option value="published" @selected($sort === 'published')>Publish date</option>
                    <option value="title_asc" @selected($sort === 'title_asc')>Title A–Z</option>
                    <option value="title_desc" @selected($sort === 'title_desc')>Title Z–A</option>
                    <option value="newest" @selected($sort === 'newest')>Newest first</option>
                    <option value="oldest" @selected($sort === 'oldest')>Oldest first</option>
                </select>

                <noscript><button type="submit" class="btn btn-sm">Filter</button></noscript>

                <a class="btn btn-sm btn-ghost" href="{{ route('admin.reels.index') }}" data-ajax-reset>Reset</a>
            </div>
        </form>

        <div data-ajax-list-content>
            @include('admin.reels._list')
        </div>
    </div>
@endsection
