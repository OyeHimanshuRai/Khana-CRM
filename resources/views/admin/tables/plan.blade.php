@extends('admin.layouts.app')

@section('title', 'Floor Plan')

@section('content')
    <x-page-header
        title="Floor Plan"
        subtitle="What is free, who is seated, and what is waiting to be cleared."
        :crumbs="['Floor' => null, 'Floor Plan' => null]"
    >
        <x-slot:actions>
            @allows('dining.tables.view')
                <a class="btn btn-sm" href="{{ route('admin.tables.index') }}">
                    <x-icon name="list" :size="15" /> Table list
                </a>
            @endallows

            @allows('dining.tables.create')
                <a class="btn btn-primary btn-sm"
                   href="{{ route('admin.tables.create', ['floor_id' => $floor?->id]) }}"
                   data-modal="{{ route('admin.tables.create', ['floor_id' => $floor?->id]) }}"
                   data-modal-title="Add Table"
                   data-modal-sub="A QR code is issued with it">
                    <x-icon name="plus" :size="15" /> Add Table
                </a>
            @endallows
        </x-slot:actions>
    </x-page-header>

    @include('admin.tables._stats')

    <div class="card" data-ajax-list="{{ route('admin.tables.plan') }}">
        <form method="GET" class="list-toolbar">
            <div class="list-filters">
                <label for="pl-floor" class="sr-only">Dining area</label>
                <select id="pl-floor" name="floor_id" data-ajax-filter>
                    @foreach ($floors as $option)
                        <option value="{{ $option->id }}" @selected($floor?->id === $option->id)>
                            {{ $option->name }}
                        </option>
                    @endforeach
                </select>

                <noscript><button type="submit" class="btn btn-sm">Show</button></noscript>
            </div>

            <div class="list-filters">
                {{--
                    A legend, not a filter. The plan is read at a glance from
                    across a room, so the colours have to be nameable.
                --}}
                @foreach ($statuses as $key => $meta)
                    <span class="badge {{ $meta['tone'] ? 'badge-'.$meta['tone'] : '' }}">
                        <span class="badge-dot"></span> {{ $meta['label'] }}
                    </span>
                @endforeach
            </div>
        </form>

        <div data-ajax-list-content>
            @include('admin.tables._plan')
        </div>
    </div>
@endsection
