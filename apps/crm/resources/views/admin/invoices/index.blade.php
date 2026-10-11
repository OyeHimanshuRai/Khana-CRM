@extends('admin.layouts.app')

@section('title', 'Invoices')

@section('content')
    <x-page-header
        title="Invoices"
        subtitle="Every sale, counter and manual. Invoices are never edited — a mistake is cancelled and re-raised."
        :crumbs="['Sales' => null, 'Invoices' => null]"
    >
        <x-slot:actions>
            @allows('sales.invoices.export')
                <a class="btn btn-sm" href="{{ route('admin.invoices.export', request()->query()) }}">
                    <x-icon name="download" :size="15" /> Export
                </a>
            @endallows

            @allows('sales.invoices.create')
                <a class="btn btn-primary btn-sm" href="{{ route('admin.pos.manual') }}">
                    <x-icon name="plus" :size="15" /> New Invoice
                </a>
            @endallows
        </x-slot:actions>
    </x-page-header>

    <div class="stat-grid">
        <div class="stat">
            <div class="stat-icon"><x-icon name="file" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Invoices</div>
                <div class="stat-value">{{ number_format($stats['count']) }}</div>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon is-success"><x-icon name="trend-up" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Sales</div>
                <div class="stat-value">₹{{ number_format($stats['sales'], 0) }}</div>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon is-info"><x-icon name="wallet" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Collected</div>
                <div class="stat-value">₹{{ number_format($stats['collected'], 0) }}</div>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon" style="background: var(--danger-soft); color: var(--danger)">
                <x-icon name="clock" :size="21" />
            </div>
            <div class="stat-body">
                <div class="stat-label">Outstanding</div>
                <div class="stat-value">₹{{ number_format($stats['outstanding'], 0) }}</div>
            </div>
        </div>
    </div>

    <div class="card" data-ajax-list="{{ route('admin.invoices.index') }}">
        <form method="GET" class="list-toolbar">
            <div class="list-search">
                <x-icon name="search" :size="15" />
                <label for="inv-search" class="sr-only">Search invoices</label>
                <input id="inv-search" type="search" name="q" value="{{ $search }}"
                       placeholder="Search number, customer or mobile…" autocomplete="off" data-ajax-filter>
            </div>

            <div class="list-filters">
                <label for="f-settlement" class="sr-only">Settlement</label>
                <select id="f-settlement" name="settlement" data-ajax-filter>
                    <option value="">Any settlement</option>
                    <option value="outstanding" @selected($settlement === 'outstanding')>Unpaid</option>
                    <option value="overdue" @selected($settlement === 'overdue')>Overdue</option>
                    <option value="credit" @selected($settlement === 'credit')>Credit sales</option>
                    <option value="settled" @selected($settlement === 'settled')>Settled</option>
                </select>

                <label for="f-status" class="sr-only">Status</label>
                <select id="f-status" name="status" data-ajax-filter>
                    <option value="">Any status</option>
                    @foreach ($statuses as $key => $meta)
                        <option value="{{ $key }}" @selected($status === $key)>{{ $meta['label'] }}</option>
                    @endforeach
                </select>

                <label for="f-channel" class="sr-only">Channel</label>
                <select id="f-channel" name="channel" data-ajax-filter>
                    <option value="">Any channel</option>
                    @foreach ($channels as $key => $label)
                        <option value="{{ $key }}" @selected($channel === $key)>{{ $label }}</option>
                    @endforeach
                </select>

                <label for="f-from" class="sr-only">From date</label>
                <input id="f-from" type="date" name="from" value="{{ $from }}" data-ajax-filter>

                <label for="f-to" class="sr-only">To date</label>
                <input id="f-to" type="date" name="to" value="{{ $to }}" data-ajax-filter>

                <noscript><button type="submit" class="btn btn-sm">Filter</button></noscript>

                <a class="btn btn-sm btn-ghost" href="{{ route('admin.invoices.index') }}" data-ajax-reset>Reset</a>
            </div>
        </form>

        <div data-ajax-list-content>
            @include('admin.invoices._list')
        </div>
    </div>
@endsection
