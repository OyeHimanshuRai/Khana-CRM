@extends('admin.layouts.app')

@section('title', 'Purchase Invoices')

@section('content')
    <x-page-header
        title="Purchase Invoices"
        subtitle="The supplier's bills, sorted by what is owed and when it falls due."
        :crumbs="['Purchasing' => null, 'Purchase Invoices' => null]"
    >
        <x-slot:actions>
            @allows('purchasing.bills.export')
                <a class="btn btn-sm" href="{{ route('admin.purchase-invoices.export', request()->query()) }}">
                    <x-icon name="download" :size="15" /> Export
                </a>
            @endallows

            @allows('purchasing.receipts.create')
                <a class="btn btn-primary btn-sm" href="{{ route('admin.receipts.create') }}">
                    <x-icon name="plus" :size="15" /> Receive Goods
                </a>
            @endallows
        </x-slot:actions>
    </x-page-header>

    <div class="stat-grid">
        <div class="stat">
            <div class="stat-icon"><x-icon name="file" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Bills</div>
                <div class="stat-value">{{ number_format($stats['count']) }}</div>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon is-info"><x-icon name="wallet" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Total Billed</div>
                <div class="stat-value">₹{{ number_format($stats['billed'], 0) }}</div>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon" style="background: var(--warning-soft); color: var(--warning)">
                <x-icon name="clock" :size="21" />
            </div>
            <div class="stat-body">
                <div class="stat-label">Outstanding</div>
                <div class="stat-value">₹{{ number_format($stats['outstanding'], 0) }}</div>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon" style="background: var(--danger-soft); color: var(--danger)">
                <x-icon name="trend-down" :size="21" />
            </div>
            <div class="stat-body">
                <div class="stat-label">Overdue</div>
                <div class="stat-value">₹{{ number_format($stats['overdue'], 0) }}</div>
            </div>
        </div>
    </div>

    <div class="card" data-ajax-list="{{ route('admin.purchase-invoices.index') }}">
        <form method="GET" class="list-toolbar">
            <div class="list-search">
                <x-icon name="search" :size="15" />
                <label for="pi-search" class="sr-only">Search bills</label>
                <input id="pi-search" type="search" name="q" value="{{ $search }}"
                       placeholder="Search reference, bill number or supplier…"
                       autocomplete="off" data-ajax-filter>
            </div>

            <div class="list-filters">
                <label for="f-settlement" class="sr-only">Payment status</label>
                <select id="f-settlement" name="settlement" data-ajax-filter>
                    <option value="">Any status</option>
                    <option value="unpaid" @selected($settlement === 'unpaid')>Still to pay</option>
                    <option value="overdue" @selected($settlement === 'overdue')>Overdue</option>
                    <option value="paid" @selected($settlement === 'paid')>Paid</option>
                </select>

                <label for="f-from" class="sr-only">From date</label>
                <input id="f-from" type="date" name="from" value="{{ $from }}" data-ajax-filter>

                <label for="f-to" class="sr-only">To date</label>
                <input id="f-to" type="date" name="to" value="{{ $to }}" data-ajax-filter>

                <noscript><button type="submit" class="btn btn-sm">Filter</button></noscript>

                <a class="btn btn-sm btn-ghost" href="{{ route('admin.purchase-invoices.index') }}"
                   data-ajax-reset>Reset</a>
            </div>
        </form>

        <div data-ajax-list-content>
            @include('admin.purchase-invoices._list')
        </div>
    </div>
@endsection
