@extends('admin.layouts.app')

@section('title', 'Suppliers')

@section('content')
    <x-page-header
        title="Suppliers"
        :subtitle="$stats['total'].' supplier'.($stats['total'] === 1 ? '' : 's')"
        :crumbs="['Purchasing' => null, 'Suppliers' => null]"
    >
        <x-slot:actions>
            @allows('purchasing.suppliers.export')
                <a class="btn btn-sm" href="{{ route('admin.suppliers.export', request()->query()) }}">
                    <x-icon name="download" :size="15" /> Export
                </a>
            @endallows

            @allows('purchasing.suppliers.create')
                <a class="btn btn-primary btn-sm" href="{{ route('admin.suppliers.create') }}"
                   data-modal="{{ route('admin.suppliers.create') }}"
                   data-modal-title="Add Supplier"
                   data-modal-sub="Create a supplier account"
                   data-modal-size="lg">
                    <x-icon name="plus" :size="15" /> Add Supplier
                </a>
            @endallows
        </x-slot:actions>
    </x-page-header>

    <div class="stat-grid">
        <div class="stat">
            <div class="stat-icon"><x-icon name="truck" :size="21" /></div>
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
            <div class="stat-icon" style="background: var(--warning-soft); color: var(--warning)">
                <x-icon name="clock" :size="21" />
            </div>
            <div class="stat-body">
                <div class="stat-label">Unsettled</div>
                <div class="stat-value">{{ number_format($stats['with_payables']) }}</div>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon" style="background: var(--danger-soft); color: var(--danger)">
                <x-icon name="wallet" :size="21" />
            </div>
            <div class="stat-body">
                <div class="stat-label">Payable</div>
                <div class="stat-value">₹{{ number_format($stats['payable'], 0) }}</div>
            </div>
        </div>
    </div>

    <div class="card" data-ajax-list="{{ route('admin.suppliers.index') }}">
        <form method="GET" class="list-toolbar">
            <div class="list-search">
                <x-icon name="search" :size="15" />
                <label for="sup-search" class="sr-only">Search suppliers</label>
                <input id="sup-search" type="search" name="q" value="{{ $search }}"
                       placeholder="Search name, company, mobile, code or GSTIN…"
                       autocomplete="off" data-ajax-filter>
            </div>

            <div class="list-filters">
                <label for="f-payables" class="sr-only">Balance</label>
                <select id="f-payables" name="payables" data-ajax-filter>
                    <option value="">Any balance</option>
                    <option value="outstanding" @selected($payables === 'outstanding')>We owe them</option>
                    <option value="clear" @selected($payables === 'clear')>Settled</option>
                </select>

                <label for="f-status" class="sr-only">Status</label>
                <select id="f-status" name="status" data-ajax-filter>
                    <option value="">Any status</option>
                    <option value="active" @selected($status === 'active')>Active</option>
                    <option value="inactive" @selected($status === 'inactive')>Inactive</option>
                </select>

                <label for="f-sort" class="sr-only">Sort by</label>
                <select id="f-sort" name="sort" data-ajax-filter>
                    <option value="name_asc" @selected($sort === 'name_asc')>Name A–Z</option>
                    <option value="name_desc" @selected($sort === 'name_desc')>Name Z–A</option>
                    <option value="balance_desc" @selected($sort === 'balance_desc')>Highest payable first</option>
                    <option value="balance_asc" @selected($sort === 'balance_asc')>Lowest payable first</option>
                    <option value="newest" @selected($sort === 'newest')>Newest first</option>
                    <option value="oldest" @selected($sort === 'oldest')>Oldest first</option>
                </select>

                <noscript><button type="submit" class="btn btn-sm">Filter</button></noscript>

                <a class="btn btn-sm btn-ghost" href="{{ route('admin.suppliers.index') }}" data-ajax-reset>Reset</a>
            </div>
        </form>

        <div data-ajax-list-content>
            @include('admin.suppliers._list')
        </div>
    </div>
@endsection
