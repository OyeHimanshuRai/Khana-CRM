@extends('admin.layouts.app')

@section('title', 'Events')

@section('content')
    <x-page-header
        title="Events"
        :subtitle="$stats['total'].' event'.($stats['total'] === 1 ? '' : 's')"
        :crumbs="['Content' => null, 'Events' => null]"
    >
        <x-slot:actions>
            @allows('content.events.create')
                {{-- Opens the blank form in a modal; the href is the no-JS path. --}}
                <a class="btn btn-primary btn-sm" href="{{ route('admin.events.create') }}"
                   data-modal="{{ route('admin.events.create') }}"
                   data-modal-title="Add Event"
                   data-modal-sub="Create a new event"
                   data-modal-size="lg">
                    <x-icon name="plus" :size="15" /> Add Event
                </a>
            @endallows
        </x-slot:actions>
    </x-page-header>

    <div class="stat-grid">
        <div class="stat">
            <div class="stat-icon"><x-icon name="calendar" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Total</div>
                <div class="stat-value">{{ number_format($stats['total']) }}</div>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon is-success"><x-icon name="user-check" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Active</div>
                <div class="stat-value">{{ number_format($stats['active']) }}</div>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon is-info"><x-icon name="clock" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Not Finished</div>
                <div class="stat-value">{{ number_format($stats['upcoming']) }}</div>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon is-warning"><x-icon name="package" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">With Image</div>
                <div class="stat-value">{{ number_format($stats['with_image']) }}</div>
            </div>
        </div>
    </div>

    {{--
        data-ajax-list turns the controls below into no-reload filters:
        ajax-list.js collects every [data-ajax-filter], fetches the fragment
        and swaps [data-ajax-list-content]. The <form> wrapper and the plain
        page links remain as the no-JavaScript fallback.
    --}}
    <div class="card" data-ajax-list="{{ route('admin.events.index') }}">
        <form method="GET" class="list-toolbar">
            <div class="list-search">
                <x-icon name="search" :size="15" />
                <label for="event-search" class="sr-only">Search events</label>
                <input id="event-search" type="search" name="q" value="{{ $search }}"
                       placeholder="Search title, name, booth or timing…" autocomplete="off" data-ajax-filter>
            </div>

            <div class="list-filters">
                <label for="f-phase" class="sr-only">When</label>
                <select id="f-phase" name="phase" data-ajax-filter>
                    <option value="">Any date</option>
                    <option value="running" @selected($phase === 'running')>On now</option>
                    <option value="upcoming" @selected($phase === 'upcoming')>Upcoming</option>
                    <option value="past" @selected($phase === 'past')>Past</option>
                </select>

                <label for="f-status" class="sr-only">Status</label>
                <select id="f-status" name="status" data-ajax-filter>
                    <option value="">Any status</option>
                    <option value="active" @selected($status === 'active')>Active</option>
                    <option value="inactive" @selected($status === 'inactive')>Inactive</option>
                </select>

                <label for="f-sort" class="sr-only">Sort by</label>
                <select id="f-sort" name="sort" data-ajax-filter>
                    <option value="soonest" @selected($sort === 'soonest')>Soonest first</option>
                    <option value="latest_date" @selected($sort === 'latest_date')>Latest first</option>
                    <option value="title_asc" @selected($sort === 'title_asc')>Title A–Z</option>
                    <option value="title_desc" @selected($sort === 'title_desc')>Title Z–A</option>
                    <option value="newest" @selected($sort === 'newest')>Recently added</option>
                    <option value="oldest" @selected($sort === 'oldest')>Oldest added</option>
                </select>

                <noscript><button type="submit" class="btn btn-sm">Filter</button></noscript>

                <a class="btn btn-sm btn-ghost" href="{{ route('admin.events.index') }}" data-ajax-reset>Reset</a>
            </div>
        </form>

        <div data-ajax-list-content>
            @include('admin.events._list')
        </div>
    </div>
@endsection
