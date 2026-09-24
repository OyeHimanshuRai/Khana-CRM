@extends('admin.layouts.app')

@section('title', 'Payments')

@section('content')
    <x-page-header
        title="Payments"
        subtitle="Money in and out. Nothing here is ever edited — a correction is a reversing entry, and both stay visible."
        :crumbs="['Finance' => null, 'Payments' => null]"
    >
        <x-slot:actions>
            @allows('finance.payments.export')
                <a class="btn btn-sm" href="{{ route('admin.payments.export', request()->query()) }}">
                    <x-icon name="download" :size="15" /> Export
                </a>
            @endallows

            @allows('finance.payments.create')
                <a class="btn btn-primary btn-sm" href="{{ route('admin.payments.create') }}"
                   data-modal="{{ route('admin.payments.create') }}"
                   data-modal-title="Record a Payment"
                   data-modal-sub="Collect against a customer's account"
                   data-modal-size="lg">
                    <x-icon name="plus" :size="15" /> Record Payment
                </a>
            @endallows
        </x-slot:actions>
    </x-page-header>

    <div class="stat-grid">
        <div class="stat">
            <div class="stat-icon is-success"><x-icon name="trend-up" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Collected</div>
                <div class="stat-value">₹{{ number_format($stats['in'], 0) }}</div>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon" style="background: var(--danger-soft); color: var(--danger)">
                <x-icon name="trend-down" :size="21" />
            </div>
            <div class="stat-body">
                <div class="stat-label">Paid Out</div>
                <div class="stat-value">₹{{ number_format($stats['out'], 0) }}</div>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon" style="background: var(--warning-soft); color: var(--warning)">
                <x-icon name="clock" :size="21" />
            </div>
            <div class="stat-body">
                <div class="stat-label">Pending clearance</div>
                <div class="stat-value">₹{{ number_format($stats['pending'], 0) }}</div>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon" style="background: var(--danger-soft); color: var(--danger)">
                <x-icon name="x" :size="21" />
            </div>
            <div class="stat-body">
                <div class="stat-label">Bounced</div>
                <div class="stat-value">₹{{ number_format($stats['bounced'], 0) }}</div>
            </div>
        </div>
    </div>

    @if ($byMethod->isNotEmpty())
        {{-- The payment-method breakdown, where the people who ask for it
             already are rather than behind a separate report. --}}
        <div class="card" style="margin-bottom:16px">
            <div class="card-header">
                <div>
                    <div class="card-title">Collected by method</div>
                    <div class="text-xs text-muted">For the filters currently applied</div>
                </div>
            </div>

            <div class="card-body" style="display:flex;flex-wrap:wrap;gap:10px">
                @foreach ($byMethod as $row)
                    <div class="stat" style="flex:1 1 150px;min-width:150px">
                        <div class="stat-body">
                            <div class="stat-label">{{ $methods[$row->method]['label'] ?? $row->method }}</div>
                            <div class="stat-value" style="font-size:17px">
                                ₹{{ number_format((float) $row->total, 0) }}
                            </div>
                            <div class="text-xs text-muted">{{ number_format($row->entries) }} entries</div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <div class="card" data-ajax-list="{{ route('admin.payments.index') }}">
        <form method="GET" class="list-toolbar">
            <div class="list-search">
                <x-icon name="search" :size="15" />
                <label for="pay-search" class="sr-only">Search payments</label>
                <input id="pay-search" type="search" name="q" value="{{ $search }}"
                       placeholder="Search receipt, party or reference…" autocomplete="off" data-ajax-filter>
            </div>

            <div class="list-filters">
                <label for="f-direction" class="sr-only">Direction</label>
                <select id="f-direction" name="direction" data-ajax-filter>
                    <option value="">In and out</option>
                    <option value="in" @selected($direction === 'in')>Received</option>
                    <option value="out" @selected($direction === 'out')>Paid out</option>
                </select>

                <label for="f-method" class="sr-only">Method</label>
                <select id="f-method" name="method" data-ajax-filter>
                    <option value="">Any method</option>
                    @foreach ($methods as $key => $meta)
                        <option value="{{ $key }}" @selected($method === $key)>{{ $meta['label'] }}</option>
                    @endforeach
                </select>

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

                <a class="btn btn-sm btn-ghost" href="{{ route('admin.payments.index') }}" data-ajax-reset>Reset</a>
            </div>
        </form>

        <div data-ajax-list-content>
            @include('admin.payments._list')
        </div>
    </div>
@endsection
