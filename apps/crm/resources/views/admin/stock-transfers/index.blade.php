@extends('admin.layouts.app')

@section('title', 'Stock Transfers')

@section('content')
    <x-page-header
        title="Stock Transfers"
        subtitle="Moving stock between warehouses, and between shops. Dispatch takes it out; receipt books it in — what is between the two is in transit."
        :crumbs="['Inventory' => null, 'Stock Transfers' => null]"
    >
        <x-slot:actions>
            @allows('inventory.transfers.create')
                <a class="btn btn-primary btn-sm" href="{{ route('admin.stock-transfers.create') }}">
                    <x-icon name="plus" :size="15" /> New Transfer
                </a>
            @endallows
        </x-slot:actions>
    </x-page-header>

    <div class="stat-grid">
        <div class="stat">
            <div class="stat-icon"><x-icon name="truck" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Transfers</div>
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
                <div class="stat-label">Sent, In Transit</div>
                <div class="stat-value">{{ number_format($stats['in_transit']) }}</div>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon is-success"><x-icon name="inbox" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Coming To You</div>
                <div class="stat-value">{{ number_format($stats['incoming']) }}</div>
            </div>
        </div>
    </div>

    <div class="card" data-ajax-list="{{ route('admin.stock-transfers.index') }}">
        <form method="GET" class="list-toolbar">
            <div class="list-search">
                <x-icon name="search" :size="15" />
                <label for="trf-search" class="sr-only">Search transfers</label>
                <input id="trf-search" type="search" name="q" value="{{ $search }}"
                       placeholder="Search reference, note or who raised it…"
                       autocomplete="off" data-ajax-filter>
            </div>

            <div class="list-filters">
                {{--
                    Direction is the important control here: incoming rows
                    belong to the sending shop, so they are fetched outside
                    the usual tenant scope and would otherwise be invisible.
                --}}
                <label for="f-direction" class="sr-only">Direction</label>
                <select id="f-direction" name="direction" data-ajax-filter>
                    <option value="outgoing" @selected($direction === 'outgoing')>Sent by us</option>
                    <option value="incoming" @selected($direction === 'incoming')>Coming to us</option>
                </select>

                <label for="f-status" class="sr-only">Status</label>
                <select id="f-status" name="status" data-ajax-filter>
                    <option value="">Any status</option>
                    @foreach ($statuses as $key => $meta)
                        <option value="{{ $key }}" @selected($status === $key)>{{ $meta['label'] }}</option>
                    @endforeach
                </select>

                <noscript><button type="submit" class="btn btn-sm">Filter</button></noscript>

                <a class="btn btn-sm btn-ghost" href="{{ route('admin.stock-transfers.index') }}"
                   data-ajax-reset>Reset</a>
            </div>
        </form>

        <div data-ajax-list-content>
            @include('admin.stock-transfers._list')
        </div>
    </div>
@endsection
