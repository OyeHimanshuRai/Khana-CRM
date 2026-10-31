@extends('admin.layouts.app')

@section('title', 'Batches & Expiry')

@section('content')
    <x-page-header
        title="Batches &amp; Expiry"
        subtitle="Every lot the shop holds, soonest expiry first. Batches are normally created by a goods receipt."
        :crumbs="['Inventory' => null, 'Batches & Expiry' => null]"
    >
        <x-slot:actions>
            @allows('inventory.batches.export')
                <a class="btn btn-sm" href="{{ route('admin.batches.export', request()->query()) }}">
                    <x-icon name="download" :size="15" /> Export
                </a>
            @endallows

            @allows('inventory.batches.create')
                <a class="btn btn-primary btn-sm" href="{{ route('admin.batches.create') }}"
                   data-modal="{{ route('admin.batches.create') }}"
                   data-modal-title="Add Batch"
                   data-modal-sub="Register a lot by hand">
                    <x-icon name="plus" :size="15" /> Add Batch
                </a>
            @endallows
        </x-slot:actions>
    </x-page-header>

    <div class="stat-grid">
        <div class="stat">
            <div class="stat-icon"><x-icon name="package" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Batches</div>
                <div class="stat-value">{{ number_format($stats['total']) }}</div>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon" style="background: var(--warning-soft); color: var(--warning)">
                <x-icon name="clock" :size="21" />
            </div>
            <div class="stat-body">
                <div class="stat-label">Within {{ $nearDays }} days</div>
                <div class="stat-value">{{ number_format($stats['near']) }}</div>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon" style="background: var(--danger-soft); color: var(--danger)">
                <x-icon name="x" :size="21" />
            </div>
            <div class="stat-body">
                <div class="stat-label">Expired</div>
                <div class="stat-value">{{ number_format($stats['expired']) }}</div>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon is-info"><x-icon name="wallet" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Value at Risk</div>
                <div class="stat-value">₹{{ number_format($stats['value_at_risk'], 0) }}</div>
            </div>
        </div>
    </div>

    <div class="card" data-ajax-list="{{ route('admin.batches.index') }}">
        <form method="GET" class="list-toolbar">
            <div class="list-search">
                <x-icon name="search" :size="15" />
                <label for="batch-search" class="sr-only">Search batches</label>
                <input id="batch-search" type="search" name="q" value="{{ $search }}"
                       placeholder="Search batch number, product or SKU…" autocomplete="off" data-ajax-filter>
            </div>

            <div class="list-filters">
                <label for="f-expiry" class="sr-only">Expiry</label>
                <select id="f-expiry" name="expiry" data-ajax-filter>
                    <option value="">Any expiry</option>
                    <option value="expired" @selected($expiry === 'expired')>Expired</option>
                    <option value="near" @selected($expiry === 'near')>Within {{ $nearDays }} days</option>
                    <option value="ok" @selected($expiry === 'ok')>Comfortably in date</option>
                    <option value="undated" @selected($expiry === 'undated')>No expiry date</option>
                </select>

                <label for="f-stocked" class="sr-only">Holding</label>
                <select id="f-stocked" name="stocked" data-ajax-filter>
                    <option value="">Any holding</option>
                    <option value="held" @selected($stocked === 'held')>Still in stock</option>
                    <option value="empty" @selected($stocked === 'empty')>Exhausted</option>
                </select>

                <label for="f-sort" class="sr-only">Sort by</label>
                <select id="f-sort" name="sort" data-ajax-filter>
                    <option value="expiry" @selected($sort === 'expiry')>Expiring soonest</option>
                    <option value="product" @selected($sort === 'product')>Product A–Z</option>
                    <option value="batch" @selected($sort === 'batch')>Batch number</option>
                    <option value="newest" @selected($sort === 'newest')>Newest first</option>
                </select>

                <noscript><button type="submit" class="btn btn-sm">Filter</button></noscript>

                <a class="btn btn-sm btn-ghost" href="{{ route('admin.batches.index') }}" data-ajax-reset>Reset</a>
            </div>
        </form>

        <div data-ajax-list-content>
            @include('admin.batches._list')
        </div>
    </div>
@endsection
