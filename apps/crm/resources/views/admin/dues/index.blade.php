@extends('admin.layouts.app')

@section('title', 'Credit & Dues')

@section('content')
    <x-page-header
        title="Credit &amp; Dues"
        subtitle="Who owes the shop money, and for how long. Aged on the due date, so a customer given 30 days is not late on day one."
        :crumbs="['Customers' => null, 'Credit & Dues' => null]"
    >
        <x-slot:actions>
            @allows('crm.dues.export')
                <a class="btn btn-sm" href="{{ route('admin.dues.export', request()->query()) }}">
                    <x-icon name="download" :size="15" /> Export
                </a>
            @endallows
        </x-slot:actions>
    </x-page-header>

    <div class="stat-grid">
        <div class="stat">
            <div class="stat-icon"><x-icon name="users" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Customers Owing</div>
                <div class="stat-value">{{ number_format($stats['customers']) }}</div>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon" style="background: var(--danger-soft); color: var(--danger)">
                <x-icon name="wallet" :size="21" />
            </div>
            <div class="stat-body">
                <div class="stat-label">Total Outstanding</div>
                <div class="stat-value">₹{{ number_format($stats['outstanding'], 0) }}</div>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon" style="background: var(--warning-soft); color: var(--warning)">
                <x-icon name="clock" :size="21" />
            </div>
            <div class="stat-body">
                <div class="stat-label">Due Today</div>
                <div class="stat-value">₹{{ number_format($stats['due_today'], 0) }}</div>
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

    {{-- The ageing profile: the shape of the debt, not just its size. --}}
    <div class="card" style="margin-bottom:16px">
        <div class="card-header">
            <div>
                <div class="card-title">Ageing</div>
                <div class="text-xs text-muted">Outstanding by how long past its due date it is</div>
            </div>
        </div>

        <div class="card-body" style="display:flex;flex-wrap:wrap;gap:10px">
            @foreach ($ageing as $band)
                <a class="stat" style="flex:1 1 170px;min-width:170px;text-decoration:none"
                   href="{{ route('admin.dues.index', ['bucket' => $band['label']]) }}">
                    <div class="stat-body">
                        <div class="stat-label">{{ $band['label'] }}</div>
                        <div class="stat-value" style="font-size:17px">
                            ₹{{ number_format($band['total'], 0) }}
                        </div>
                        <div class="text-xs text-muted">{{ number_format($band['count']) }} invoice(s)</div>
                    </div>
                </a>
            @endforeach
        </div>
    </div>

    <div class="card" data-ajax-list="{{ route('admin.dues.index') }}">
        <form method="GET" class="list-toolbar">
            <div class="list-search">
                <x-icon name="search" :size="15" />
                <label for="dues-search" class="sr-only">Search customers</label>
                <input id="dues-search" type="search" name="q" value="{{ $search }}"
                       placeholder="Search name, mobile, code or village…" autocomplete="off"
                       data-ajax-filter>
            </div>

            <div class="list-filters">
                <label for="f-bucket" class="sr-only">Ageing bucket</label>
                <select id="f-bucket" name="bucket" data-ajax-filter>
                    <option value="">Any age</option>
                    @foreach ($buckets as $band)
                        <option value="{{ $band['label'] }}" @selected($bucket === $band['label'])>
                            {{ $band['label'] }}
                        </option>
                    @endforeach
                </select>

                <noscript><button type="submit" class="btn btn-sm">Filter</button></noscript>

                <a class="btn btn-sm btn-ghost" href="{{ route('admin.dues.index') }}" data-ajax-reset>Reset</a>
            </div>
        </form>

        <div data-ajax-list-content>
            @include('admin.dues._list')
        </div>
    </div>
@endsection
