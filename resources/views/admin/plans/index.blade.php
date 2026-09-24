@extends('admin.layouts.app')

@section('title', 'Subscription Plans')

@section('content')
    <x-page-header
        title="Plans"
        subtitle="What the platform sells"
        :crumbs="['Settings' => null, 'Plans' => null]"
    >
        <x-slot:actions>
            @allows('settings.plans.create')
                <a class="btn btn-primary btn-sm" href="{{ route('admin.plans.create') }}"
                   data-modal="{{ route('admin.plans.create') }}"
                   data-modal-title="New Plan"
                   data-modal-sub="Price, limits and what it includes"
                   data-modal-size="lg">
                    <x-icon name="plus" :size="15" /> New Plan
                </a>
            @endallows
        </x-slot:actions>
    </x-page-header>

    <div class="stat-grid">
        <div class="stat">
            <div class="stat-icon"><x-icon name="tag" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Plans</div>
                <div class="stat-value">{{ number_format($stats['total']) }}</div>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon is-success"><x-icon name="star" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">On sale</div>
                <div class="stat-value">{{ number_format($stats['active']) }}</div>
                <span class="text-xs text-muted">offered to new businesses</span>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon is-info"><x-icon name="building" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Subscribers</div>
                <div class="stat-value">{{ number_format($stats['subscribers']) }}</div>
                <span class="text-xs text-muted">businesses on a plan right now</span>
            </div>
        </div>
    </div>

    <div class="card" data-ajax-list="{{ route('admin.plans.index') }}">
        <form method="GET" class="list-toolbar">
            <div class="list-search">
                <x-icon name="search" :size="15" />
                <label for="plan-search" class="sr-only">Search plans</label>
                <input id="plan-search" type="search" name="q" value="{{ $search }}"
                       placeholder="Search name or code…" autocomplete="off" data-ajax-filter>
            </div>

            <div class="list-filters">
                <label for="f-status" class="sr-only">Status</label>
                <select id="f-status" name="status" data-ajax-filter>
                    <option value="">Any status</option>
                    <option value="active" @selected($status === 'active')>On sale</option>
                    <option value="inactive" @selected($status === 'inactive')>Withdrawn</option>
                </select>

                <noscript><button type="submit" class="btn btn-sm">Filter</button></noscript>

                <a class="btn btn-sm btn-ghost" href="{{ route('admin.plans.index') }}" data-ajax-reset>Reset</a>
            </div>
        </form>

        <div data-ajax-list-content>
            @include('admin.plans._list')
        </div>
    </div>
@endsection
