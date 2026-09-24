@extends('admin.layouts.app')

@section('title', 'Stock on Hand')

@section('content')
    <x-page-header
        title="Stock on Hand"
        subtitle="What is on the shelf right now, by warehouse and batch. Read-only — every change comes from a document."
        :crumbs="['Inventory' => null, 'Stock on Hand' => null]"
    >
        <x-slot:actions>
            @allows('inventory.stock.export')
                <a class="btn btn-sm" href="{{ route('admin.stock.export', request()->query()) }}">
                    <x-icon name="download" :size="15" /> Export
                </a>
            @endallows

            @allows('inventory.adjustments.create')
                <a class="btn btn-primary btn-sm" href="{{ route('admin.stock-adjustments.create') }}">
                    <x-icon name="plus" :size="15" /> New Adjustment
                </a>
            @endallows
        </x-slot:actions>
    </x-page-header>

    <div class="stat-grid">
        <div class="stat">
            <div class="stat-icon"><x-icon name="list" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Stocked Lines</div>
                <div class="stat-value">{{ number_format($stats['lines']) }}</div>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon is-info"><x-icon name="wallet" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Stock Value</div>
                <div class="stat-value">₹{{ number_format($stats['value'], 0) }}</div>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon" style="background: var(--warning-soft); color: var(--warning)">
                <x-icon name="trend-down" :size="21" />
            </div>
            <div class="stat-body">
                <div class="stat-label">At Reorder Level</div>
                <div class="stat-value">{{ number_format($stats['low']) }}</div>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon"><x-icon name="clock" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Reserved</div>
                <div class="stat-value">{{ number_format($stats['reserved'], 0) }}</div>
            </div>
        </div>
    </div>

    <div class="card" data-ajax-list="{{ route('admin.stock.index') }}">
        <form method="GET" class="list-toolbar">
            <div class="list-search">
                <x-icon name="search" :size="15" />
                <label for="stock-search" class="sr-only">Search stock</label>
                <input id="stock-search" type="search" name="q" value="{{ $search }}"
                       placeholder="Search product, SKU or barcode…" autocomplete="off" data-ajax-filter>
            </div>

            <div class="list-filters">
                <label for="f-warehouse" class="sr-only">Warehouse</label>
                <select id="f-warehouse" name="warehouse" data-ajax-filter>
                    <option value="">All warehouses</option>
                    @foreach ($warehouses as $warehouse)
                        <option value="{{ $warehouse->id }}" @selected($warehouseId === $warehouse->id)>
                            {{ $warehouse->name }}
                        </option>
                    @endforeach
                </select>

                <label for="f-level" class="sr-only">Level</label>
                <select id="f-level" name="level" data-ajax-filter>
                    <option value="">Any level</option>
                    <option value="in" @selected($level === 'in')>In stock</option>
                    <option value="zero" @selected($level === 'zero')>Exhausted</option>
                    <option value="negative" @selected($level === 'negative')>Negative</option>
                    <option value="reserved" @selected($level === 'reserved')>Has reservations</option>
                </select>

                <label for="f-expiry" class="sr-only">Expiry</label>
                <select id="f-expiry" name="expiry" data-ajax-filter>
                    <option value="">Any batch</option>
                    <option value="batched" @selected($expiry === 'batched')>Batch tracked</option>
                    <option value="near" @selected($expiry === 'near')>Near expiry</option>
                    <option value="expired" @selected($expiry === 'expired')>Expired</option>
                </select>

                <label for="f-sort" class="sr-only">Sort by</label>
                <select id="f-sort" name="sort" data-ajax-filter>
                    <option value="product" @selected($sort === 'product')>Product A–Z</option>
                    <option value="quantity_desc" @selected($sort === 'quantity_desc')>Most stock first</option>
                    <option value="quantity_asc" @selected($sort === 'quantity_asc')>Least stock first</option>
                    <option value="value_desc" @selected($sort === 'value_desc')>Highest value first</option>
                    <option value="expiry" @selected($sort === 'expiry')>Expiring soonest</option>
                </select>

                <noscript><button type="submit" class="btn btn-sm">Filter</button></noscript>

                <a class="btn btn-sm btn-ghost" href="{{ route('admin.stock.index') }}" data-ajax-reset>Reset</a>
            </div>
        </form>

        <div data-ajax-list-content>
            @include('admin.stock._list')
        </div>
    </div>
@endsection
