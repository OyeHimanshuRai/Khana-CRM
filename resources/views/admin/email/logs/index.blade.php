@extends('admin.layouts.app')

@section('title', 'Email Logs')

@section('content')
    <x-page-header
        title="Email Logs"
        :subtitle="number_format($stats['total']).' message'.($stats['total'] === 1 ? '' : 's').' recorded'"
        :crumbs="['Marketing' => null, 'Email Logs' => null]"
    >
        <x-slot:actions>
            @allows('email.logs.export')
                {{-- Kept in step with the on-screen filters by the script at
                     the bottom of this file, so what downloads is what is
                     visible. Not a modal: it answers with a file. --}}
                <a class="btn btn-sm" href="{{ route('admin.email.logs.export', request()->query()) }}"
                   data-export-link>
                    <x-icon name="download" :size="15" /> Export CSV
                </a>
            @endallows

            @allows('email.logs.delete')
                <form method="POST" action="{{ route('admin.email.logs.destroy') }}"
                      data-ajax data-refresh-list
                      style="display:flex;gap:6px;align-items:center"
                      onsubmit="return confirm('Delete every log entry older than the chosen period? This cannot be undone.')">
                    @csrf
                    @method('DELETE')

                    <label for="prune-days" class="sr-only">Retention</label>
                    <select id="prune-days" name="older_than_days" class="form-control"
                            style="width:auto;padding:5px 8px;font-size:12.5px">
                        @foreach ($pruneChoices as $days)
                            <option value="{{ $days }}" @selected($days === 90)>Older than {{ $days }} days</option>
                        @endforeach
                    </select>

                    <button type="submit" class="btn btn-sm btn-danger">
                        <x-icon name="trash" :size="14" /> Prune
                    </button>
                </form>
            @endallows
        </x-slot:actions>
    </x-page-header>

    <div class="stat-grid">
        <div class="stat">
            <div class="stat-icon"><x-icon name="mail" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Total</div>
                <div class="stat-value">{{ number_format($stats['total']) }}</div>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon is-success"><x-icon name="user-check" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Sent</div>
                <div class="stat-value">{{ number_format($stats['sent']) }}</div>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon is-warning"><x-icon name="user-x" :size="21" /></div>
            <div class="stat-body">
                {{-- The number this screen exists for: a failed welcome email
                     used to be visible only in the log file. --}}
                <div class="stat-label">Failed</div>
                <div class="stat-value">{{ number_format($stats['failed']) }}</div>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon is-info"><x-icon name="clock" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Today</div>
                <div class="stat-value">{{ number_format($stats['today']) }}</div>
            </div>
        </div>
    </div>

    {{--
        data-ajax-list turns the controls below into no-reload filters:
        ajax-list.js collects every [data-ajax-filter], fetches the fragment
        and swaps [data-ajax-list-content]. The <form> wrapper and the plain
        page links remain as the no-JavaScript fallback.
    --}}
    <div class="card" data-ajax-list="{{ route('admin.email.logs.index') }}">
        <form method="GET" class="list-toolbar">
            <div class="list-search">
                <x-icon name="search" :size="15" />
                <label for="log-search" class="sr-only">Search the log</label>
                <input id="log-search" type="search" name="q" value="{{ $search }}"
                       placeholder="Search recipient, subject, sender or error…"
                       autocomplete="off" data-ajax-filter>
            </div>

            <div class="list-filters">
                <label for="f-status" class="sr-only">Status</label>
                <select id="f-status" name="status" data-ajax-filter>
                    <option value="">Any status</option>
                    @foreach ($statuses as $key => $label)
                        <option value="{{ $key }}" @selected($status === $key)>{{ $label }}</option>
                    @endforeach
                </select>

                @if ($kinds->isNotEmpty())
                    <label for="f-kind" class="sr-only">Type</label>
                    <select id="f-kind" name="kind" data-ajax-filter>
                        <option value="">Any type</option>
                        @foreach ($kinds as $class => $label)
                            <option value="{{ $class }}" @selected($kind === $class)>{{ $label }}</option>
                        @endforeach
                        <option value="—" @selected($kind === '—')>Direct (no mailable)</option>
                    </select>
                @endif

                <label for="f-period" class="sr-only">Sent</label>
                <select id="f-period" name="period" data-ajax-filter>
                    <option value="">Any date</option>
                    <option value="today" @selected($period === 'today')>Today</option>
                    <option value="week" @selected($period === 'week')>Last 7 days</option>
                    <option value="month" @selected($period === 'month')>This month</option>
                    <option value="quarter" @selected($period === 'quarter')>Last 90 days</option>
                </select>

                <label for="f-sort" class="sr-only">Sort by</label>
                <select id="f-sort" name="sort" data-ajax-filter>
                    <option value="newest" @selected($sort === 'newest')>Newest first</option>
                    <option value="oldest" @selected($sort === 'oldest')>Oldest first</option>
                    <option value="recipient" @selected($sort === 'recipient')>Recipient A–Z</option>
                </select>

                <noscript><button type="submit" class="btn btn-sm">Filter</button></noscript>

                <a class="btn btn-sm btn-ghost" href="{{ route('admin.email.logs.index') }}"
                   data-ajax-reset>Reset</a>
            </div>
        </form>

        <div data-ajax-list-content>
            @include('admin.email.logs._list')
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        /* Keep the Export link pointed at whatever the list is currently
           showing. ajax-list.js pushes the filtered URL; this mirrors its
           query string onto the download. */
        document.addEventListener('ajaxlist:loaded', function (event) {
            var link = document.querySelector('[data-export-link]');
            if (!link) { return; }

            var query = event.detail.url.split('?')[1] || '';
            link.href = @json(route('admin.email.logs.export')) + (query ? '?' + query : '');
        });
    </script>
@endpush
