@extends('admin.layouts.app')

@section('title', 'Activity Logs')

@section('content')
    <x-page-header
        title="Activity Logs"
        :subtitle="number_format($logs->total()).' recorded event(s).'"
        :crumbs="['Settings' => null, 'Activity Logs' => null]"
    >
        <x-slot:actions>
            @allows('settings.activity_logs.export')
                <a class="btn btn-sm" href="{{ route('admin.activity.export', request()->query()) }}"
                   data-export-link>
                    <x-icon name="download" :size="15" /> Export CSV
                </a>
            @endallows

            @allows('settings.activity_logs.delete')
                <form method="POST" action="{{ route('admin.activity.destroy') }}"
                      onsubmit="return confirm('Delete all log entries older than 90 days?')">
                    @csrf
                    @method('DELETE')
                    <input type="hidden" name="older_than_days" value="90">
                    <button type="submit" class="btn btn-sm btn-danger">Prune 90+ days</button>
                </form>
            @endallows
        </x-slot:actions>
    </x-page-header>

    <div class="card" data-ajax-list="{{ route('admin.activity.index') }}">
        <form method="GET" class="list-toolbar">
            <div class="list-search">
                <x-icon name="search" :size="15" />
                <label for="log-search" class="sr-only">Search logs</label>
                <input id="log-search" type="search" name="q" value="{{ $search }}"
                       placeholder="Search description, user or event…" autocomplete="off" data-ajax-filter>
            </div>

            <div class="list-filters">
                <label for="event-filter" class="sr-only">Filter by event</label>
                <select id="event-filter" name="event" data-ajax-filter>
                    <option value="">All events</option>
                    @foreach ($events as $name)
                        <option value="{{ $name }}" @selected($event === $name)>{{ $name }}</option>
                    @endforeach
                </select>

                <noscript><button type="submit" class="btn btn-sm">Filter</button></noscript>

                <a class="btn btn-sm btn-ghost" href="{{ route('admin.activity.index') }}" data-ajax-reset>Reset</a>
            </div>
        </form>

        <div data-ajax-list-content>
            @include('admin.activity._list')
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('ajaxlist:loaded', function (event) {
            var link = document.querySelector('[data-export-link]');
            if (!link) { return; }

            var query = event.detail.url.split('?')[1] || '';
            link.href = @json(route('admin.activity.export')) + (query ? '?' + query : '');
        });
    </script>
@endpush
