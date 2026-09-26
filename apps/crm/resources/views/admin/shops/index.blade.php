@extends('admin.layouts.app')

@section('title', 'Shops')

@section('content')
    <x-page-header
        title="Shops"
        :subtitle="$stats['total'].' shop'.($stats['total'] === 1 ? '' : 's')"
        :crumbs="['Settings' => null, 'Shops' => null]"
    >
        <x-slot:actions>
            @allows('settings.shops.create')
                <a class="btn btn-primary btn-sm" href="{{ route('admin.shops.create') }}"
                   data-modal="{{ route('admin.shops.create') }}"
                   data-modal-title="Add Shop"
                   data-modal-sub="Create a new shop or branch"
                   data-modal-size="lg">
                    <x-icon name="plus" :size="15" /> Add Shop
                </a>
            @endallows
        </x-slot:actions>
    </x-page-header>

    <div class="stat-grid">
        <div class="stat">
            <div class="stat-icon"><x-icon name="building" :size="21" /></div>
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
            <div class="stat-icon is-info"><x-icon name="users" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Assigned Staff</div>
                <div class="stat-value">{{ number_format($stats['users']) }}</div>
            </div>
        </div>
    </div>

    <div class="card" data-ajax-list="{{ route('admin.shops.index') }}">
        <form method="GET" class="list-toolbar">
            <div class="list-search">
                <x-icon name="search" :size="15" />
                <label for="shop-search" class="sr-only">Search shops</label>
                <input id="shop-search" type="search" name="q" value="{{ $search }}"
                       placeholder="Search name, code, city or GSTIN…" autocomplete="off" data-ajax-filter>
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

                <a class="btn btn-sm btn-ghost" href="{{ route('admin.shops.index') }}" data-ajax-reset>Reset</a>
            </div>
        </form>

        <div data-ajax-list-content>
            @include('admin.shops._list')
        </div>
    </div>
@endsection
