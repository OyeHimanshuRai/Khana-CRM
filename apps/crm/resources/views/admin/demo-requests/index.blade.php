@extends('admin.layouts.app')

@section('title', 'Demo Requests')

@section('content')
    {{--
        The demo-request inbox (§19).

        Opens on "New" rather than on everything — see the controller. A page of
        leads with the answered ones mixed into the unanswered is a page where
        the one that came in an hour ago sits under thirty that were closed last
        month.
    --}}
    <x-page-header
        title="Demo Requests"
        :subtitle="$stats['new'] > 0
            ? $stats['new'].' waiting for a call'
            : 'Nobody is waiting'"
        :crumbs="['Content' => null, 'Landing Page' => null, 'Demo Requests' => null]"
    >
        <x-slot:actions>
            @allows('content.demo_requests.export')
                <a class="btn btn-ghost btn-sm" href="{{ route('admin.demo-requests.export', request()->query()) }}">
                    <x-icon name="download" :size="15" /> Export
                </a>
            @endallows
        </x-slot:actions>
    </x-page-header>

    <div class="stat-grid">
        <div class="stat">
            <div class="stat-icon" style="background: var(--warning-soft); color: var(--warning)">
                <x-icon name="inbox" :size="21" />
            </div>
            <div class="stat-body">
                <div class="stat-label">New</div>
                <div class="stat-value">{{ number_format($stats['new']) }}</div>
                <span class="text-xs text-muted">nobody has rung them</span>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon @if (($stats['oldest_hours'] ?? 0) > 24) is-danger @endif"
                 @if (($stats['oldest_hours'] ?? 0) > 24)
                     style="background: var(--danger-soft); color: var(--danger)"
                 @endif>
                <x-icon name="clock" :size="21" />
            </div>
            <div class="stat-body">
                <div class="stat-label">Longest wait</div>
                <div class="stat-value">
                    {{-- A count says how much there is; this says how bad it
                         has got. --}}
                    @if ($stats['oldest_hours'] === null)
                        —
                    @elseif ($stats['oldest_hours'] < 24)
                        {{ $stats['oldest_hours'] }}<span class="text-sm">h</span>
                    @else
                        {{ intdiv($stats['oldest_hours'], 24) }}<span class="text-sm">d</span>
                    @endif
                </div>
                <span class="text-xs text-muted">oldest unanswered</span>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon is-success"><x-icon name="user-check" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Converted</div>
                <div class="stat-value">{{ number_format($stats['converted']) }}</div>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon"><x-icon name="users" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">All time</div>
                <div class="stat-value">{{ number_format($stats['total']) }}</div>
            </div>
        </div>
    </div>

    <div class="card" data-ajax-list="{{ route('admin.demo-requests.index') }}">
        <form method="GET" class="list-toolbar">
            <div class="list-search">
                <x-icon name="search" :size="15" />
                <label for="dr-search" class="sr-only">Search demo requests</label>
                <input id="dr-search" type="search" name="q" value="{{ $search }}"
                       placeholder="Search name, business, city, email or phone…"
                       autocomplete="off" data-ajax-filter>
            </div>

            <div class="list-filters">
                <label for="dr-status" class="sr-only">Status</label>
                <select id="dr-status" name="status" data-ajax-filter>
                    {{-- An explicit empty option, so "all" is reachable: the
                         screen defaults to New only when no status was asked
                         for at all. --}}
                    <option value="" @selected($status === '')>All</option>
                    @foreach ($statuses as $key => $label)
                        <option value="{{ $key }}" @selected($status === $key)>{{ $label }}</option>
                    @endforeach
                </select>

                <noscript><button type="submit" class="btn btn-sm">Filter</button></noscript>

                <a class="btn btn-sm btn-ghost" href="{{ route('admin.demo-requests.index') }}" data-ajax-reset>Reset</a>
            </div>
        </form>

        <div data-ajax-list-content>
            @include('admin.demo-requests._list')
        </div>
    </div>
@endsection
