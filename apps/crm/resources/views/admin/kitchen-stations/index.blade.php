@extends('admin.layouts.app')

@section('title', 'Kitchen Stations')

@section('content')
    <x-page-header
        title="Kitchen Stations"
        subtitle="Where each dish is cooked, and how long that station is given before a ticket runs late."
        :crumbs="['Kitchen' => null, 'Stations' => null]"
    >
        <x-slot:actions>
            @allows('kitchen.tickets.view')
                <a class="btn btn-sm" href="{{ route('admin.kitchen.index') }}">
                    <x-icon name="zap" :size="15" /> Kitchen Display
                </a>
            @endallows

            @allows('kitchen.stations.create')
                <a class="btn btn-primary btn-sm" href="{{ route('admin.kitchen-stations.create') }}"
                   data-modal="{{ route('admin.kitchen-stations.create') }}"
                   data-modal-title="Add Kitchen Station"
                   data-modal-sub="Bar, Tandoor, Bakery — wherever food is made">
                    <x-icon name="plus" :size="15" /> Add Station
                </a>
            @endallows
        </x-slot:actions>
    </x-page-header>

    <div class="stat-grid">
        <div class="stat">
            <div class="stat-icon is-info"><x-icon name="tool" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Stations</div>
                <div class="stat-value">{{ number_format($stats['total']) }}</div>
                <span class="text-xs text-muted">{{ number_format($stats['active']) }} in service</span>
            </div>
        </div>

        {{--
            Only shown when there is something to act on. Unrouted dishes are
            not broken - they fall to the default station - but they are the
            rows somebody meant to file, and a permanent zero is a tile that
            teaches people to stop reading the row.
        --}}
        @if ($stats['unrouted'] > 0)
            <div class="stat">
                <div class="stat-icon is-warning"><x-icon name="help" :size="21" /></div>
                <div class="stat-body">
                    <div class="stat-label">Unrouted dishes</div>
                    <div class="stat-value">{{ number_format($stats['unrouted']) }}</div>
                    <span class="text-xs text-muted">Cooked at the default station</span>
                </div>
            </div>
        @endif
    </div>

    <div class="card" data-ajax-list="{{ route('admin.kitchen-stations.index') }}">
        <form method="GET" class="list-toolbar">
            <div class="list-search">
                <x-icon name="search" :size="15" />
                <label for="ks-search" class="sr-only">Search stations</label>
                <input id="ks-search" type="search" name="q" value="{{ $search }}"
                       placeholder="Search station or code…" autocomplete="off" data-ajax-filter>
            </div>

            <div class="list-filters">
                <label for="ks-status" class="sr-only">Status</label>
                <select id="ks-status" name="status" data-ajax-filter>
                    <option value="">Any status</option>
                    <option value="active" @selected($status === 'active')>In service</option>
                    <option value="inactive" @selected($status === 'inactive')>Closed</option>
                </select>

                <noscript><button type="submit" class="btn btn-sm">Filter</button></noscript>

                <a class="btn btn-sm btn-ghost" href="{{ route('admin.kitchen-stations.index') }}" data-ajax-reset>Reset</a>
            </div>
        </form>

        <div data-ajax-list-content>
            @include('admin.kitchen-stations._list')
        </div>
    </div>
@endsection
