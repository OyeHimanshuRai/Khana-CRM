@extends('admin.layouts.app')

@section('title', 'Purchase Orders')

@section('content')
    <x-page-header
        title="Purchase Orders"
        subtitle="What the shop has asked suppliers to send. An order is an intention — the goods receipt is the event."
        :crumbs="['Purchasing' => null, 'Purchase Orders' => null]"
    >
        <x-slot:actions>
            @allows('purchasing.purchase_orders.create')
                <a class="btn btn-primary btn-sm" href="{{ route('admin.purchase-orders.create') }}">
                    <x-icon name="plus" :size="15" /> New Order
                </a>
            @endallows
        </x-slot:actions>
    </x-page-header>

    <div class="stat-grid">
        <div class="stat">
            <div class="stat-icon"><x-icon name="file" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Orders</div>
                <div class="stat-value">{{ number_format($stats['total']) }}</div>
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
            <div class="stat-icon is-info"><x-icon name="truck" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Still Open</div>
                <div class="stat-value">{{ number_format($stats['open']) }}</div>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon"><x-icon name="wallet" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">On Order</div>
                <div class="stat-value">₹{{ number_format($stats['value'], 0) }}</div>
            </div>
        </div>
    </div>

    <div class="card" data-ajax-list="{{ route('admin.purchase-orders.index') }}">
        <form method="GET" class="list-toolbar">
            <div class="list-search">
                <x-icon name="search" :size="15" />
                <label for="po-search" class="sr-only">Search orders</label>
                <input id="po-search" type="search" name="q" value="{{ $search }}"
                       placeholder="Search reference or supplier…" autocomplete="off" data-ajax-filter>
            </div>

            <div class="list-filters">
                <label for="f-status" class="sr-only">Status</label>
                <select id="f-status" name="status" data-ajax-filter>
                    <option value="">Any status</option>
                    @foreach ($statuses as $key => $meta)
                        <option value="{{ $key }}" @selected($status === $key)>{{ $meta['label'] }}</option>
                    @endforeach
                </select>

                <noscript><button type="submit" class="btn btn-sm">Filter</button></noscript>

                <a class="btn btn-sm btn-ghost" href="{{ route('admin.purchase-orders.index') }}"
                   data-ajax-reset>Reset</a>
            </div>
        </form>

        <div data-ajax-list-content>
            @include('admin.purchase-orders._list')
        </div>
    </div>
@endsection
