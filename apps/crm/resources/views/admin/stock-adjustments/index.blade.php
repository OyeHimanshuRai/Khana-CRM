@extends('admin.layouts.app')

@section('title', 'Stock Adjustments')

@section('content')
    <x-page-header
        title="Stock Adjustments"
        subtitle="Corrections to what the system thinks is on the shelf. Each one carries a reason and, once applied, cannot be undone — only corrected by another."
        :crumbs="['Inventory' => null, 'Stock Adjustments' => null]"
    >
        <x-slot:actions>
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
                <div class="stat-label">Adjustments</div>
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
            <div class="stat-icon is-success"><x-icon name="user-check" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Applied</div>
                <div class="stat-value">{{ number_format($stats['applied']) }}</div>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon" style="background: {{ $stats['value'] < 0 ? 'var(--danger-soft)' : 'var(--success-soft)' }}; color: {{ $stats['value'] < 0 ? 'var(--danger)' : 'var(--success)' }}">
                <x-icon name="wallet" :size="21" />
            </div>
            <div class="stat-body">
                <div class="stat-label">Net Value Change</div>
                <div class="stat-value">₹{{ number_format($stats['value'], 0) }}</div>
            </div>
        </div>
    </div>

    <div class="card" data-ajax-list="{{ route('admin.stock-adjustments.index') }}">
        <form method="GET" class="list-toolbar">
            <div class="list-search">
                <x-icon name="search" :size="15" />
                <label for="adj-search" class="sr-only">Search adjustments</label>
                <input id="adj-search" type="search" name="q" value="{{ $search }}"
                       placeholder="Search reference, reason or who raised it…"
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

                <a class="btn btn-sm btn-ghost" href="{{ route('admin.stock-adjustments.index') }}"
                   data-ajax-reset>Reset</a>
            </div>
        </form>

        <div data-ajax-list-content>
            @include('admin.stock-adjustments._list')
        </div>
    </div>
@endsection
