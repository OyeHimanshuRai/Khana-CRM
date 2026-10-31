@extends('admin.layouts.app')

@section('title', 'Reservations')

@section('content')
    <x-page-header
        title="Reservations"
        :subtitle="$day->isToday() ? 'Tonight' : $day->format('l, j F Y')"
        :crumbs="['Tables & QR' => null, 'Reservations' => null]"
    >
        <x-slot:actions>
            @allows('dining.reservations.create')
                <a class="btn btn-primary btn-sm"
                   href="{{ route('admin.reservations.create', ['day' => $day->toDateString()]) }}"
                   data-modal="{{ route('admin.reservations.create', ['day' => $day->toDateString()]) }}"
                   data-modal-title="New Booking"
                   data-modal-sub="Take a table reservation"
                   data-modal-size="lg">
                    <x-icon name="plus" :size="15" /> New Booking
                </a>
            @endallows
        </x-slot:actions>
    </x-page-header>

    <div class="stat-grid" data-reservation-stats>
        @include('admin.reservations._stats')
    </div>

    <div class="card" data-ajax-list="{{ route('admin.reservations.index') }}">
        <form method="GET" class="list-toolbar">
            <div class="list-search">
                <x-icon name="search" :size="15" />
                <label for="res-search" class="sr-only">Search bookings</label>
                <input id="res-search" type="search" name="q" value="{{ $search }}"
                       placeholder="Search name, mobile or note…" autocomplete="off" data-ajax-filter>
            </div>

            <div class="list-filters">
                {{--
                    The day, not a page number. A host asks "what is booked
                    tonight", and tomorrow is one tap away rather than a
                    filter buried in a date range.
                --}}
                <label for="f-day" class="sr-only">Day</label>
                <input id="f-day" type="date" name="day" value="{{ $day->toDateString() }}" data-ajax-filter>

                <label for="f-status" class="sr-only">Status</label>
                <select id="f-status" name="status" data-ajax-filter>
                    <option value="">Any status</option>
                    @foreach ($statuses as $value => $meta)
                        <option value="{{ $value }}" @selected($status === $value)>{{ $meta['label'] }}</option>
                    @endforeach
                </select>

                <noscript><button type="submit" class="btn btn-sm">Filter</button></noscript>

                <a class="btn btn-sm btn-ghost" href="{{ route('admin.reservations.index') }}" data-ajax-reset>Today</a>
            </div>
        </form>

        <div data-ajax-list-content>
            @include('admin.reservations._list')
        </div>
    </div>
@endsection
