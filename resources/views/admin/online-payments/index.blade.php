@extends('admin.layouts.app')

@section('title', 'Online Payments')

@section('content')
    <x-page-header
        title="Online Payments"
        subtitle="What guests paid from their phones"
        :crumbs="['Finance' => null, 'Online Payments' => null]"
    >
        <x-slot:actions>
            <a class="btn btn-sm" href="{{ route('admin.online-payments.refunds') }}">
                <x-icon name="trend-down" :size="15" /> Refunds
            </a>
            <a class="btn btn-sm" href="{{ route('admin.online-payments.settlements') }}">
                <x-icon name="chart" :size="15" /> Settlement
            </a>
        </x-slot:actions>
    </x-page-header>

    @unless ($live)
        <div class="alert alert-info" style="margin-bottom:14px">
            <strong>No payment provider is switched on.</strong>
            Guests are told to pay at the counter. Anything already listed here was taken while a
            provider was configured, and refunds are not possible until one is set up again.
        </div>
    @endunless

    <div class="stat-grid">
        <div class="stat">
            <div class="stat-icon is-success"><x-icon name="wallet" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Taken today</div>
                <div class="stat-value">{{ number_format($stats['paid_today'], 0) }}</div>
                <span class="text-xs text-muted">{{ number_format($stats['count_today']) }} payments</span>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon" style="background: var(--warning-soft); color: var(--warning)">
                <x-icon name="clock" :size="21" />
            </div>
            <div class="stat-body">
                <div class="stat-label">Started, not finished</div>
                <div class="stat-value">{{ number_format($stats['abandoned_today']) }}</div>
                {{-- A handful is normal — people change their mind at the
                     payment page. A lot means the checkout is broken. --}}
                <span class="text-xs text-muted">a few is normal; many is a fault</span>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon" style="background: var(--danger-soft); color: var(--danger)">
                <x-icon name="trend-down" :size="21" />
            </div>
            <div class="stat-body">
                <div class="stat-label">Refunded today</div>
                <div class="stat-value">{{ number_format($stats['refunded_today'], 0) }}</div>
            </div>
        </div>
    </div>

    <div class="card" data-ajax-list="{{ route('admin.online-payments.index') }}">
        <form method="GET" class="list-toolbar">
            <div class="list-search">
                <x-icon name="search" :size="15" />
                <label for="op-search" class="sr-only">Search payments</label>
                <input id="op-search" type="search" name="q" value="{{ $search }}"
                       placeholder="Search our reference or the provider's id…" autocomplete="off" data-ajax-filter>
            </div>

            <div class="list-filters">
                <label for="f-status" class="sr-only">Status</label>
                <select id="f-status" name="status" data-ajax-filter>
                    <option value="">Any status</option>
                    @foreach ($statuses as $value => $meta)
                        <option value="{{ $value }}" @selected($status === $value)>{{ $meta['label'] }}</option>
                    @endforeach
                </select>

                <noscript><button type="submit" class="btn btn-sm">Filter</button></noscript>

                <a class="btn btn-sm btn-ghost" href="{{ route('admin.online-payments.index') }}" data-ajax-reset>Reset</a>
            </div>
        </form>

        <div data-ajax-list-content>
            @include('admin.online-payments._list')
        </div>
    </div>
@endsection
