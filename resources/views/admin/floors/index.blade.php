@extends('admin.layouts.app')

@section('title', 'Dining Areas')

@section('content')
    <x-page-header
        title="Dining Areas"
        subtitle="The parts of the restaurant guests sit in — Ground Floor, Rooftop, AC Hall, Garden."
        :crumbs="['Floor' => null, 'Dining Areas' => null]"
    >
        <x-slot:actions>
            @allows('dining.tables.view')
                <a class="btn btn-sm" href="{{ route('admin.tables.index') }}">
                    <x-icon name="list" :size="15" /> Tables
                </a>
            @endallows

            @allows('dining.floors.create')
                <a class="btn btn-primary btn-sm" href="{{ route('admin.floors.create') }}"
                   data-modal="{{ route('admin.floors.create') }}"
                   data-modal-title="Add Dining Area"
                   data-modal-sub="A floor, hall or section guests sit in">
                    <x-icon name="plus" :size="15" /> Add Area
                </a>
            @endallows
        </x-slot:actions>
    </x-page-header>

    <div class="stat-grid">
        <div class="stat">
            <div class="stat-icon"><x-icon name="building" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Areas</div>
                <div class="stat-value">{{ number_format($stats['total']) }}</div>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon is-success"><x-icon name="user-check" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Open</div>
                <div class="stat-value">{{ number_format($stats['active']) }}</div>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon is-info"><x-icon name="grid" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Tables</div>
                <div class="stat-value">{{ number_format($stats['tables']) }}</div>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon is-warning"><x-icon name="users" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Seats</div>
                <div class="stat-value">{{ number_format($stats['seats']) }}</div>
                <span class="text-xs text-muted">across tables in service</span>
            </div>
        </div>
    </div>

    <div class="card" data-ajax-list="{{ route('admin.floors.index') }}">
        <form method="GET" class="list-toolbar">
            <div class="list-search">
                <x-icon name="search" :size="15" />
                <label for="fl-search" class="sr-only">Search dining areas</label>
                <input id="fl-search" type="search" name="q" value="{{ $search }}"
                       placeholder="Search name, code or description…" autocomplete="off" data-ajax-filter>
            </div>

            <div class="list-filters">
                <label for="fl-status" class="sr-only">Status</label>
                <select id="fl-status" name="status" data-ajax-filter>
                    <option value="">Any status</option>
                    <option value="active" @selected($status === 'active')>Open</option>
                    <option value="inactive" @selected($status === 'inactive')>Closed</option>
                </select>

                <noscript><button type="submit" class="btn btn-sm">Filter</button></noscript>

                <a class="btn btn-sm btn-ghost" href="{{ route('admin.floors.index') }}" data-ajax-reset>Reset</a>
            </div>
        </form>

        <div data-ajax-list-content>
            @include('admin.floors._list')
        </div>
    </div>
@endsection
