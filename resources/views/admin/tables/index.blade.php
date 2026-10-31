@extends('admin.layouts.app')

@section('title', 'Tables')

@section('content')
    <x-page-header
        title="Tables"
        subtitle="Every table in the restaurant, what it seats and whether anybody is at it."
        :crumbs="['Floor' => null, 'Tables' => null]"
    >
        <x-slot:actions>
            <a class="btn btn-sm" href="{{ route('admin.tables.plan') }}">
                <x-icon name="grid" :size="15" /> Floor Plan
            </a>

            @allows('dining.qr.view')
                <a class="btn btn-sm" href="{{ route('admin.qr.index') }}">
                    <x-icon name="scan" :size="15" /> QR Codes
                </a>
            @endallows

            @allows('dining.tables.create')
                <a class="btn btn-primary btn-sm" href="{{ route('admin.tables.create') }}"
                   data-modal="{{ route('admin.tables.create') }}"
                   data-modal-title="Add Table"
                   data-modal-sub="A QR code is issued with it">
                    <x-icon name="plus" :size="15" /> Add Table
                </a>
            @endallows
        </x-slot:actions>
    </x-page-header>

    @include('admin.tables._stats')

    <div class="card" data-ajax-list="{{ route('admin.tables.index') }}">
        <form method="GET" class="list-toolbar">
            <div class="list-search">
                <x-icon name="search" :size="15" />
                <label for="tb-search" class="sr-only">Search tables</label>
                <input id="tb-search" type="search" name="q" value="{{ $search }}"
                       placeholder="Search table, code, area or note…" autocomplete="off" data-ajax-filter>
            </div>

            <div class="list-filters">
                <label for="tb-floor" class="sr-only">Dining area</label>
                <select id="tb-floor" name="floor_id" data-ajax-filter>
                    <option value="">Any area</option>
                    @foreach ($floors as $floor)
                        <option value="{{ $floor->id }}" @selected($floorId === $floor->id)>
                            {{ $floor->name }}
                        </option>
                    @endforeach
                </select>

                <label for="tb-status" class="sr-only">Status</label>
                <select id="tb-status" name="status" data-ajax-filter>
                    <option value="">Any status</option>
                    @foreach ($statuses as $key => $meta)
                        <option value="{{ $key }}" @selected($status === $key)>{{ $meta['label'] }}</option>
                    @endforeach
                </select>

                <noscript><button type="submit" class="btn btn-sm">Filter</button></noscript>

                <a class="btn btn-sm btn-ghost" href="{{ route('admin.tables.index') }}" data-ajax-reset>Reset</a>
            </div>
        </form>

        <div data-ajax-list-content>
            @include('admin.tables._list')
        </div>
    </div>
@endsection
