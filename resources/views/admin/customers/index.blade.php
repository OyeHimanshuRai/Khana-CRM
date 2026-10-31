@extends('admin.layouts.app')

@section('title', 'Customers')

@section('content')
    <x-page-header
        title="Customers"
        :subtitle="$stats['total'].' customer'.($stats['total'] === 1 ? '' : 's')"
        :crumbs="['Customers' => null, 'Customer Master' => null]"
    >
        <x-slot:actions>
            @allows('crm.customers.export')
                <a class="btn btn-sm" href="{{ route('admin.customers.export', request()->query()) }}">
                    <x-icon name="download" :size="15" /> Export
                </a>
            @endallows

            @allows('crm.customers.create')
                <a class="btn btn-primary btn-sm" href="{{ route('admin.customers.create') }}"
                   data-modal="{{ route('admin.customers.create') }}"
                   data-modal-title="Add Customer"
                   data-modal-sub="Create a customer account"
                   data-modal-size="lg">
                    <x-icon name="plus" :size="15" /> Add Customer
                </a>
            @endallows
        </x-slot:actions>
    </x-page-header>

    <div class="stat-grid">
        <div class="stat">
            <div class="stat-icon"><x-icon name="users" :size="21" /></div>
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
                <div class="stat-label">With Dues</div>
                <div class="stat-value">{{ number_format($stats['with_dues']) }}</div>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon" style="background: var(--danger-soft); color: var(--danger)">
                <x-icon name="wallet" :size="21" />
            </div>
            <div class="stat-body">
                <div class="stat-label">Outstanding</div>
                <div class="stat-value">₹{{ number_format($stats['outstanding'], 0) }}</div>
            </div>
        </div>
    </div>

    <div class="card" data-ajax-list="{{ route('admin.customers.index') }}">
        <form method="GET" class="list-toolbar">
            <div class="list-search">
                <x-icon name="search" :size="15" />
                <label for="cust-search" class="sr-only">Search customers</label>
                <input id="cust-search" type="search" name="q" value="{{ $search }}"
                       placeholder="Search name, mobile, code, village or GSTIN…"
                       autocomplete="off" data-ajax-filter>
            </div>

            <div class="list-filters">
                <label for="f-type" class="sr-only">Customer type</label>
                <select id="f-type" name="type" data-ajax-filter>
                    <option value="">Any type</option>
                    @foreach ($types as $key => $label)
                        <option value="{{ $key }}" @selected($type === $key)>{{ $label }}</option>
                    @endforeach
                </select>

                <label for="f-dues" class="sr-only">Credit position</label>
                <select id="f-dues" name="dues" data-ajax-filter>
                    <option value="">Any balance</option>
                    <option value="outstanding" @selected($dues === 'outstanding')>Owes money</option>
                    <option value="over_limit" @selected($dues === 'over_limit')>Over credit limit</option>
                    <option value="clear" @selected($dues === 'clear')>Nothing owed</option>
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
                    <option value="balance_desc" @selected($sort === 'balance_desc')>Highest due first</option>
                    <option value="balance_asc" @selected($sort === 'balance_asc')>Lowest due first</option>
                    <option value="newest" @selected($sort === 'newest')>Newest first</option>
                    <option value="oldest" @selected($sort === 'oldest')>Oldest first</option>
                </select>

                <noscript><button type="submit" class="btn btn-sm">Filter</button></noscript>

                <a class="btn btn-sm btn-ghost" href="{{ route('admin.customers.index') }}" data-ajax-reset>Reset</a>
            </div>
        </form>

        <div data-ajax-list-content>
            @include('admin.customers._list')
        </div>
    </div>
@endsection
