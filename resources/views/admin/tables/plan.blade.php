@extends('admin.layouts.app')

@section('title', 'Floor Plan')

@section('content')
    <x-page-header
        title="Floor Plan"
        subtitle="What is free, who is seated, and what is waiting to be cleared."
        :crumbs="['Floor' => null, 'Floor Plan' => null]"
    >
        <x-slot:actions>
            {{-- Rearranging is a mode, so a stray drag in service cannot move a table. --}}
            @allows('dining.tables.adjust')
                <button type="button" class="btn btn-sm" data-plan-lock-toggle aria-pressed="false">
                    <x-icon name="lock" :size="15" /> <span data-plan-lock-label>Edit layout</span>
                </button>
            @endallows

            <span class="text-xs text-muted" data-plan-live aria-live="polite"></span>

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

    <div data-plan-stats>
        @include('admin.tables._stats')
    </div>

    <div class="card" data-ajax-list="{{ route('admin.tables.plan') }}"
         data-plan-refresh="{{ (int) config('dining.plan.refresh_seconds') }}">
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
        </form>

        <div data-ajax-list-content>
            @include('admin.tables._plan')
        </div>
    </div>
@endsection
