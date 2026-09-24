@extends('admin.layouts.app')

@section('title', 'Live Orders')

@section('content')
    <x-page-header
        title="Live Orders"
        subtitle="Everything somebody is still waiting for, oldest first."
        :crumbs="['Operations' => null, 'Live Orders' => null]"
    >
        <x-slot:actions>
            @allows('kitchen.tickets.view')
                <a class="btn btn-sm" href="{{ route('admin.kitchen.index') }}">
                    <x-icon name="zap" :size="15" /> Kitchen Display
                </a>
            @endallows

            @allows('pos.tables.view')
                <a class="btn btn-sm" href="{{ route('admin.table-bills.index') }}">
                    <x-icon name="wallet" :size="15" /> Table Bills
                </a>
            @endallows

            <a class="btn btn-sm" href="{{ route('admin.orders.index') }}">
                <x-icon name="list" :size="15" /> Order history
            </a>
        </x-slot:actions>
    </x-page-header>

    {{-- §5's live order count. Stages with nothing in them are left out: a row
         of permanent zeroes teaches people to stop reading it. --}}
    <div class="stat-grid">
        <div class="stat">
            <div class="stat-icon is-info"><x-icon name="inbox" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">In flight</div>
                <div class="stat-value">{{ number_format($counts['total']) }}</div>
            </div>
        </div>

        @foreach ($statuses as $key => $label)
            @continue (($counts[$key] ?? 0) === 0)
            <div class="stat">
                <div class="stat-body">
                    <div class="stat-label">{{ $label }}</div>
                    <div class="stat-value">{{ number_format($counts[$key]) }}</div>
                </div>
            </div>
        @endforeach

        @if ($counts['late'] > 0)
            <div class="stat">
                <div class="stat-icon" style="background: var(--danger-soft); color: var(--danger)">
                    <x-icon name="clock" :size="21" />
                </div>
                <div class="stat-body">
                    <div class="stat-label">Over {{ $late }} minutes</div>
                    <div class="stat-value" style="color:var(--danger)">{{ number_format($counts['late']) }}</div>
                </div>
            </div>
        @endif

        @if ($counts['cancelled'] > 0)
            <div class="stat">
                <div class="stat-icon is-warning"><x-icon name="x" :size="21" /></div>
                <div class="stat-body">
                    <div class="stat-label">Cancelled today</div>
                    <div class="stat-value">{{ number_format($counts['cancelled']) }}</div>
                </div>
            </div>
        @endif
    </div>

    {{--
        data-kds is the polling and timer contract, not the kitchen board -
        public/assets/js/kds.js re-fetches the fragment, ticks the elapsed
        times between fetches and leaves the page alone while somebody is
        typing in it. This screen wants all three and none of the rest; the
        bell and the full-screen button are simply not on it.
    --}}
    {{-- data-kds-shop lets realtime.js subscribe to this branch. Absent in
         all-shops mode, and absent means "poll only", which is correct:
         a socket is per branch and a consolidated view spans several. --}}
    <div class="card" data-ajax-list="{{ route('admin.live-orders.index') }}" data-kds
         @if (App\Support\CurrentShop::id()) data-kds-shop="{{ App\Support\CurrentShop::id() }}" @endif
         data-kds-every="10">
        <form method="GET" class="list-toolbar">
            <div class="list-search">
                <x-icon name="search" :size="15" />
                <label for="lo-search" class="sr-only">Search live orders</label>
                <input id="lo-search" type="search" name="q" value="{{ request('q') }}"
                       placeholder="Search order #, customer or mobile…" autocomplete="off" data-ajax-filter>
            </div>

            <div class="list-filters">
                <label for="lo-type" class="sr-only">Channel</label>
                <select id="lo-type" name="type" data-ajax-filter>
                    <option value="">Every channel</option>
                    @foreach ($types as $key => $label)
                        <option value="{{ $key }}" @selected($type === $key)>{{ $label }}</option>
                    @endforeach
                </select>

                <label for="lo-status" class="sr-only">Stage</label>
                <select id="lo-status" name="status" data-ajax-filter>
                    <option value="">Any stage</option>
                    @foreach ($statuses as $key => $label)
                        <option value="{{ $key }}" @selected($status === $key)>{{ $label }}</option>
                    @endforeach
                </select>

                <noscript><button type="submit" class="btn btn-sm">Filter</button></noscript>
                <noscript><span class="text-xs text-muted">Reload to see new orders.</span></noscript>

                <a class="btn btn-sm btn-ghost" href="{{ route('admin.live-orders.index') }}" data-ajax-reset>Reset</a>
            </div>
        </form>

        <div data-ajax-list-content>
            @include('admin.live-orders._list')
        </div>
    </div>
@endsection
