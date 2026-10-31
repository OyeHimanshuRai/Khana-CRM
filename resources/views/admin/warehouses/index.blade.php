@extends('admin.layouts.app')

@section('title', 'Warehouses')

@section('content')
    <x-page-header
        title="Warehouses"
        subtitle="Where each shop keeps its stock — the counter, the godown, a van."
        :crumbs="['Inventory' => null, 'Warehouses' => null]"
    >
        <x-slot:actions>
            @allows('inventory.warehouses.create')
                <a class="btn btn-primary btn-sm" href="{{ route('admin.warehouses.create') }}"
                   data-modal="{{ route('admin.warehouses.create') }}"
                   data-modal-title="Add Warehouse"
                   data-modal-sub="Create a storage location">
                    <x-icon name="plus" :size="15" /> Add Warehouse
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
            <div class="stat-icon is-info"><x-icon name="package" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Holding Stock</div>
                <div class="stat-value">{{ number_format($stats['stocked']) }}</div>
            </div>
        </div>
    </div>

    <div class="card" data-ajax-list="{{ route('admin.warehouses.index') }}">
        <form method="GET" class="list-toolbar">
            <div class="list-search">
                <x-icon name="search" :size="15" />
                <label for="wh-search" class="sr-only">Search warehouses</label>
                <input id="wh-search" type="search" name="q" value="{{ $search }}"
                       placeholder="Search name, code, city or address…" autocomplete="off" data-ajax-filter>
            </div>

            <div class="list-filters">
                <label for="f-status" class="sr-only">Status</label>
                <select id="f-status" name="status" data-ajax-filter>
                    <option value="">Any status</option>
                    <option value="active" @selected($status === 'active')>Active</option>
                    <option value="inactive" @selected($status === 'inactive')>Inactive</option>
                </select>

                <noscript><button type="submit" class="btn btn-sm">Filter</button></noscript>

                <a class="btn btn-sm btn-ghost" href="{{ route('admin.warehouses.index') }}" data-ajax-reset>Reset</a>
            </div>
        </form>

        <div data-ajax-list-content>
            @include('admin.warehouses._list')
        </div>
    </div>
@endsection
