@extends('admin.layouts.app')

@section('title', 'Purchase Returns')

@section('content')
    <x-page-header
        title="Purchase Returns"
        subtitle="Goods going back to a supplier — partial or in full, against the receipt they arrived on."
        :crumbs="['Purchasing' => null, 'Purchase Returns' => null]"
    >
        <x-slot:actions>
            @allows('purchasing.returns.create')
                <a class="btn btn-primary btn-sm" href="{{ route('admin.purchase-returns.create') }}">
                    <x-icon name="plus" :size="15" /> Take a Return
                </a>
            @endallows
        </x-slot:actions>
    </x-page-header>

    <div class="stat-grid">
        <div class="stat">
            <div class="stat-icon"><x-icon name="inbox" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Accepted</div>
                <div class="stat-value">{{ number_format($stats['count']) }}</div>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon" style="background: var(--danger-soft); color: var(--danger)">
                <x-icon name="trend-down" :size="21" />
            </div>
            <div class="stat-body">
                <div class="stat-label">Value Returned</div>
                <div class="stat-value">₹{{ number_format($stats['value'], 0) }}</div>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon" style="background: var(--warning-soft); color: var(--warning)">
                <x-icon name="clock" :size="21" />
            </div>
            <div class="stat-body">
                <div class="stat-label">Awaiting Approval</div>
                <div class="stat-value">{{ number_format($stats['pending']) }}</div>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon is-info"><x-icon name="wallet" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Refunded</div>
                <div class="stat-value">₹{{ number_format($stats['refunded'], 0) }}</div>
            </div>
        </div>
    </div>

    <div class="card" data-ajax-list="{{ route('admin.purchase-returns.index') }}">
        <form method="GET" class="list-toolbar">
            <div class="list-search">
                <x-icon name="search" :size="15" />
                <label for="pr-search" class="sr-only">Search returns</label>
                <input id="pr-search" type="search" name="q" value="{{ $search }}"
                       placeholder="Search reference, receipt, supplier or reason…"
                       autocomplete="off" data-ajax-filter>
            </div>

            <div class="list-filters">
                <label for="f-status" class="sr-only">Status</label>
                <select id="f-status" name="status" data-ajax-filter>
                    <option value="">Any status</option>
                    @foreach ($statuses as $key => $meta)
                        <option value="{{ $key }}" @selected($status === $key)>{{ $meta['label'] }}</option>
                    @endforeach
                </select>

                <label for="f-from" class="sr-only">From date</label>
                <input id="f-from" type="date" name="from" value="{{ $from }}" data-ajax-filter>

                <label for="f-to" class="sr-only">To date</label>
                <input id="f-to" type="date" name="to" value="{{ $to }}" data-ajax-filter>

                <noscript><button type="submit" class="btn btn-sm">Filter</button></noscript>

                <a class="btn btn-sm btn-ghost" href="{{ route('admin.purchase-returns.index') }}"
                   data-ajax-reset>Reset</a>
            </div>
        </form>

        <div data-ajax-list-content>
            @include('admin.purchase-returns._list')
        </div>
    </div>
@endsection
