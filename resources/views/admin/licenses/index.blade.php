@extends('admin.layouts.app')

@section('title', 'Licenses')

@push('scripts')
    <script src="{{ asset('assets/js/license.js') }}?v={{ filemtime(public_path('assets/js/license.js')) }}" defer></script>
@endpush

@section('content')
    <x-page-header
        title="Licenses"
        subtitle="Generate keys, approve activations and control every customer's access"
        :crumbs="['Settings' => null, 'Licenses' => null]"
    >
        <x-slot:actions>
            <a class="btn btn-sm" href="{{ route('admin.licenses.history') }}">
                <x-icon name="clock" :size="15" /> History
            </a>
            @if ($canEdit)
                <a class="btn btn-primary btn-sm" href="{{ route('admin.licenses.create') }}"
                   data-modal="{{ route('admin.licenses.create') }}"
                   data-modal-title="Generate License"
                   data-modal-sub="Create a key and hand it to a customer">
                    <x-icon name="plus" :size="15" /> Generate License
                </a>
            @endif
        </x-slot:actions>
    </x-page-header>

    <div class="stat-grid">
        <div class="stat">
            <div class="stat-icon"><x-icon name="shield" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Licenses</div>
                <div class="stat-value">{{ number_format($stats['total']) }}</div>
            </div>
        </div>
        <div class="stat">
            <div class="stat-icon" style="background: var(--warning-soft); color: var(--warning)"><x-icon name="clock" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Pending approval</div>
                <div class="stat-value">{{ number_format($stats['pending']) }}</div>
                <a class="text-xs" href="{{ route('admin.licenses.index', ['status' => 'pending']) }}" data-license-filter="pending">review</a>
            </div>
        </div>
        <div class="stat">
            <div class="stat-icon is-success"><x-icon name="check" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Active</div>
                <div class="stat-value">{{ number_format($stats['active']) }}</div>
            </div>
        </div>
        <div class="stat">
            <div class="stat-icon is-info"><x-icon name="calendar" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Expiring soon</div>
                <div class="stat-value">{{ number_format($stats['expiring']) }}</div>
                <span class="text-xs text-muted">in the next {{ config('license.warn_days') }} days</span>
            </div>
        </div>
        <div class="stat">
            <div class="stat-icon" style="background: var(--danger-soft); color: var(--danger)"><x-icon name="lock" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Expired / blocked</div>
                <div class="stat-value">{{ number_format($stats['expired'] + $stats['blocked']) }}</div>
            </div>
        </div>
    </div>

    <div class="card" data-ajax-list="{{ route('admin.licenses.index') }}">
        <form method="GET" class="list-toolbar">
            <div class="list-search">
                <x-icon name="search" :size="15" />
                <label for="lic-search" class="sr-only">Search licenses</label>
                <input id="lic-search" type="search" name="q" value="{{ $search }}"
                       placeholder="Search customer, code, email or key hint…" autocomplete="off" data-ajax-filter>
            </div>

            <div class="list-filters">
                <label for="lic-plan" class="sr-only">Plan</label>
                <select id="lic-plan" name="plan_id" data-ajax-filter>
                    <option value="">Any plan</option>
                    @foreach ($plans as $plan)
                        <option value="{{ $plan->id }}" @selected($planId === $plan->id)>{{ $plan->name }}</option>
                    @endforeach
                </select>

                <label for="lic-status" class="sr-only">Status</label>
                <select id="lic-status" name="status" data-ajax-filter>
                    <option value="">Any status</option>
                    @foreach ($statuses as $value => $meta)
                        <option value="{{ $value }}" @selected($status === $value)>{{ $meta['label'] }}</option>
                    @endforeach
                </select>

                <label for="lic-expiry" class="sr-only">Expiry</label>
                <select id="lic-expiry" name="expiry" data-ajax-filter>
                    <option value="">Any expiry</option>
                    <option value="within_30" @selected($expiry === 'within_30')>Within 30 days</option>
                    <option value="past" @selected($expiry === 'past')>Already passed</option>
                    <option value="none" @selected($expiry === 'none')>No date</option>
                </select>

                <noscript><button type="submit" class="btn btn-sm">Filter</button></noscript>
                <a class="btn btn-sm btn-ghost" href="{{ route('admin.licenses.index') }}" data-ajax-reset>Reset</a>
            </div>
        </form>

        <div data-ajax-list-content>
            @include('admin.licenses._list')
        </div>
    </div>
@endsection
