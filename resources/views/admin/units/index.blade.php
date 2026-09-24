@extends('admin.layouts.app')

@section('title', 'Units of Measure')

@section('content')
    <x-page-header
        title="Units of Measure"
        :subtitle="$stats['total'].' unit'.($stats['total'] === 1 ? '' : 's')"
        :crumbs="['Inventory' => null, 'Units' => null]"
    >
        <x-slot:actions>
            @allows('inventory.units.create')
                <a class="btn btn-primary btn-sm" href="{{ route('admin.units.create') }}"
                   data-modal="{{ route('admin.units.create') }}"
                   data-modal-title="Add Unit"
                   data-modal-sub="Create a unit of measure">
                    <x-icon name="plus" :size="15" /> Add Unit
                </a>
            @endallows
        </x-slot:actions>
    </x-page-header>

    <div class="stat-grid">
        <div class="stat">
            <div class="stat-icon"><x-icon name="list" :size="21" /></div>
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
            <div class="stat-icon is-info"><x-icon name="chart" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Fractional</div>
                <div class="stat-value">{{ number_format($stats['decimal']) }}</div>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon"><x-icon name="package" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Whole only</div>
                <div class="stat-value">{{ number_format($stats['whole']) }}</div>
            </div>
        </div>
    </div>

    <div class="card" data-ajax-list="{{ route('admin.units.index') }}">
        <form method="GET" class="list-toolbar">
            <div class="list-search">
                <x-icon name="search" :size="15" />
                <label for="unit-search" class="sr-only">Search units</label>
                <input id="unit-search" type="search" name="q" value="{{ $search }}"
                       placeholder="Search name or code…" autocomplete="off" data-ajax-filter>
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
                </select>

                <noscript><button type="submit" class="btn btn-sm">Filter</button></noscript>

                <a class="btn btn-sm btn-ghost" href="{{ route('admin.units.index') }}" data-ajax-reset>Reset</a>
            </div>
        </form>

        <div data-ajax-list-content>
            @include('admin.units._list')
        </div>
    </div>
@endsection
