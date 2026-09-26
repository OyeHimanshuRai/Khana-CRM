@extends('admin.layouts.app')

@section('title', 'Goods Receipts')

@section('content')
    <x-page-header
        title="Goods Receipts"
        subtitle="What arrived and what the supplier billed for it. Posting a receipt is what puts stock on the shelf."
        :crumbs="['Purchasing' => null, 'Goods Receipts' => null]"
    >
        <x-slot:actions>
            @allows('purchasing.receipts.view')
                <a class="btn btn-sm" href="{{ route('admin.receipts.export', request()->query()) }}">
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
            <div class="stat-icon"><x-icon name="truck" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Received</div>
                <div class="stat-value">{{ number_format($stats['count']) }}</div>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon is-info"><x-icon name="wallet" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Purchase Value</div>
                <div class="stat-value">₹{{ number_format($stats['value'], 0) }}</div>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon" style="background: var(--danger-soft); color: var(--danger)">
                <x-icon name="clock" :size="21" />
            </div>
            <div class="stat-body">
                <div class="stat-label">Payable</div>
                <div class="stat-value">₹{{ number_format($stats['payable'], 0) }}</div>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon" style="background: var(--warning-soft); color: var(--warning)">
                <x-icon name="file" :size="21" />
            </div>
            <div class="stat-body">
                <div class="stat-label">Unposted Drafts</div>
                <div class="stat-value">{{ number_format($stats['drafts']) }}</div>
            </div>
        </div>
    </div>

    <div class="card" data-ajax-list="{{ route('admin.receipts.index') }}">
        <form method="GET" class="list-toolbar">
            <div class="list-search">
                <x-icon name="search" :size="15" />
                <label for="grn-search" class="sr-only">Search receipts</label>
                <input id="grn-search" type="search" name="q" value="{{ $search }}"
                       placeholder="Search reference, bill number or supplier…"
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

                <label for="f-settlement" class="sr-only">Settlement</label>
                <select id="f-settlement" name="settlement" data-ajax-filter>
                    <option value="">Any settlement</option>
                    <option value="unpaid" @selected($settlement === 'unpaid')>Still to pay</option>
                    <option value="settled" @selected($settlement === 'settled')>Settled</option>
                </select>

                <label for="f-from" class="sr-only">From date</label>
                <input id="f-from" type="date" name="from" value="{{ $from }}" data-ajax-filter>

                <label for="f-to" class="sr-only">To date</label>
                <input id="f-to" type="date" name="to" value="{{ $to }}" data-ajax-filter>

                <noscript><button type="submit" class="btn btn-sm">Filter</button></noscript>

                <a class="btn btn-sm btn-ghost" href="{{ route('admin.receipts.index') }}" data-ajax-reset>Reset</a>
            </div>
        </form>

        <div data-ajax-list-content>
            @include('admin.receipts._list')
        </div>
    </div>
@endsection
