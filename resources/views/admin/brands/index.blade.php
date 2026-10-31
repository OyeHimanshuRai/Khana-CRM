@extends('admin.layouts.app')

@section('title', 'Brands')

@section('content')
    <x-page-header
        title="Brands"
        :subtitle="$stats['total'].' brand'.($stats['total'] === 1 ? '' : 's')"
        :crumbs="['Inventory' => null, 'Brands' => null]"
    >
        <x-slot:actions>
            @allows('inventory.brands.create')
                <a class="btn btn-primary btn-sm" href="{{ route('admin.brands.create') }}"
                   data-modal="{{ route('admin.brands.create') }}"
                   data-modal-title="Add Brand"
                   data-modal-sub="Create a new product brand">
                    <x-icon name="plus" :size="15" /> Add Brand
                </a>
            @endallows
        </x-slot:actions>
    </x-page-header>

    <div class="stat-grid">
        <div class="stat">
            <div class="stat-icon"><x-icon name="tag" :size="21" /></div>
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
                <div class="stat-label">With Logo</div>
                <div class="stat-value">{{ number_format($stats['with_logo']) }}</div>
            </div>
        </div>
    </div>

    <div class="card" data-ajax-list="{{ route('admin.brands.index') }}">
        <form method="GET" class="list-toolbar">
            <div class="list-search">
                <x-icon name="search" :size="15" />
                <label for="brand-search" class="sr-only">Search brands</label>
                <input id="brand-search" type="search" name="q" value="{{ $search }}"
                       placeholder="Search name, manufacturer or description…" autocomplete="off" data-ajax-filter>
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
                    <option value="name_asc" @selected($sort === 'name_asc')>Name A–Z</option>
                    <option value="name_desc" @selected($sort === 'name_desc')>Name Z–A</option>
                    <option value="newest" @selected($sort === 'newest')>Newest first</option>
                    <option value="oldest" @selected($sort === 'oldest')>Oldest first</option>
                </select>

                <noscript><button type="submit" class="btn btn-sm">Filter</button></noscript>

                <a class="btn btn-sm btn-ghost" href="{{ route('admin.brands.index') }}" data-ajax-reset>Reset</a>
            </div>
        </form>

        <div data-ajax-list-content>
            @include('admin.brands._list')
        </div>
    </div>
@endsection
