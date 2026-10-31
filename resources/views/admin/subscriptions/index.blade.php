@extends('admin.layouts.app')

@section('title', 'Subscriptions')

@section('content')
    <x-page-header
        title="Subscriptions"
        subtitle="Who is on what, and what they have paid"
        :crumbs="['Settings' => null, 'Subscriptions' => null]"
    >
        <x-slot:actions>
            @allows('settings.plans.view')
                <a class="btn btn-sm" href="{{ route('admin.plans.index') }}">
                    <x-icon name="tag" :size="15" /> Plans
                </a>
            @endallows
        </x-slot:actions>
    </x-page-header>

    <div class="stat-grid">
        <div class="stat">
            <div class="stat-icon"><x-icon name="building" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Businesses</div>
                <div class="stat-value">{{ number_format($stats['businesses']) }}</div>
                <span class="text-xs text-muted">{{ number_format($stats['subscribed']) }} on a plan</span>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon is-success"><x-icon name="wallet" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Billed monthly</div>
                <div class="stat-value">{{ number_format($stats['monthly'], 0) }}</div>
                <span class="text-xs text-muted">at agreed prices, trials excluded</span>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon is-info"><x-icon name="gift" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">On trial</div>
                <div class="stat-value">{{ number_format($stats['trialing']) }}</div>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon" style="background: var(--warning-soft); color: var(--warning)">
                <x-icon name="clock" :size="21" />
            </div>
            <div class="stat-body">
                <div class="stat-label">Payment due</div>
                <div class="stat-value">{{ number_format($stats['due']) }}</div>
                <span class="text-xs text-muted">still trading, inside grace</span>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon" style="background: var(--danger-soft); color: var(--danger)">
                <x-icon name="lock" :size="21" />
            </div>
            <div class="stat-body">
                <div class="stat-label">Lapsed</div>
                <div class="stat-value">{{ number_format($stats['lapsed']) }}</div>
                <span class="text-xs text-muted">locked out, records kept</span>
            </div>
        </div>
    </div>

    <div class="card" data-ajax-list="{{ route('admin.subscriptions.index') }}">
        <form method="GET" class="list-toolbar">
            <div class="list-search">
                <x-icon name="search" :size="15" />
                <label for="sub-search" class="sr-only">Search businesses</label>
                <input id="sub-search" type="search" name="q" value="{{ $search }}"
                       placeholder="Search company name or code…" autocomplete="off" data-ajax-filter>
            </div>

            <div class="list-filters">
                <label for="f-state" class="sr-only">State</label>
                <select id="f-state" name="state" data-ajax-filter>
                    <option value="">Any state</option>
                    @foreach ($states as $value => $label)
                        <option value="{{ $value }}" @selected($state === $value)>{{ $label }}</option>
                    @endforeach
                </select>

                <noscript><button type="submit" class="btn btn-sm">Filter</button></noscript>

                <a class="btn btn-sm btn-ghost" href="{{ route('admin.subscriptions.index') }}" data-ajax-reset>Reset</a>
            </div>
        </form>

        <div data-ajax-list-content>
            @include('admin.subscriptions._list')
        </div>
    </div>
@endsection
