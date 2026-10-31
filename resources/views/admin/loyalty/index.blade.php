@extends('admin.layouts.app')

@section('title', 'Loyalty Points')

@section('content')
    <x-page-header
        title="Loyalty Points"
        :subtitle="$program?->is_active ? $program->name.' — running' : 'Not running'"
        :crumbs="['Customers' => null, 'Loyalty' => null]"
    >
        <x-slot:actions>
            @allows('crm.loyalty.edit')
                <a class="btn btn-primary btn-sm" href="{{ route('admin.loyalty.settings') }}"
                   data-modal="{{ route('admin.loyalty.settings') }}"
                   data-modal-title="Loyalty Programme"
                   data-modal-sub="What a point is worth"
                   data-modal-size="lg">
                    <x-icon name="settings" :size="15" /> Programme
                </a>
            @endallows
        </x-slot:actions>
    </x-page-header>

    @unless ($program?->is_active)
        <div class="alert alert-info" style="margin-bottom:14px">
            <strong>No loyalty programme is running here.</strong>
            Nothing earns points and nothing can be spent. Set the rates and switch it on when
            you are ready — existing customers start earning from their next bill.
        </div>
    @endunless

    <div class="stat-grid">
        <div class="stat">
            <div class="stat-icon"><x-icon name="users" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Members</div>
                <div class="stat-value">{{ number_format($stats['members']) }}</div>
                <span class="text-xs text-muted">customers holding points</span>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon is-info"><x-icon name="star" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Points held</div>
                <div class="stat-value">{{ number_format($stats['outstanding']) }}</div>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon" style="background: var(--warning-soft); color: var(--warning)">
                <x-icon name="wallet" :size="21" />
            </div>
            <div class="stat-body">
                <div class="stat-label">If everyone spent today</div>
                <div class="stat-value">{{ number_format($stats['liability'], 0) }}</div>
                {{-- The number a loyalty programme is actually judged on, and
                     the one nobody computes until they are asked for it. --}}
                <span class="text-xs text-muted">what you would owe</span>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon" style="background: var(--danger-soft); color: var(--danger)">
                <x-icon name="clock" :size="21" />
            </div>
            <div class="stat-body">
                <div class="stat-label">Expiring in 30 days</div>
                <div class="stat-value">{{ number_format($stats['expiring']) }}</div>
            </div>
        </div>
    </div>

    <div class="card" data-ajax-list="{{ route('admin.loyalty.index') }}">
        <form method="GET" class="list-toolbar">
            <div class="list-search">
                <x-icon name="search" :size="15" />
                <label for="l-search" class="sr-only">Search members</label>
                <input id="l-search" type="search" name="q" value="{{ $search }}"
                       placeholder="Search name, mobile or code…" autocomplete="off" data-ajax-filter>
            </div>

            <div class="list-filters">
                <a class="btn btn-sm btn-ghost" href="{{ route('admin.loyalty.index') }}" data-ajax-reset>Reset</a>
            </div>
        </form>

        <div data-ajax-list-content>
            @include('admin.loyalty._list')
        </div>
    </div>
@endsection
