@extends('admin.layouts.app')

@section('title', $canManage ? 'Support Desk' : 'Support')

@section('content')
    {{--
        One screen, two readings (§2).

        The desk sees every company's tickets; a restaurant sees its own. The
        difference is not made here — it is made by
        SupportTicket::scopeVisibleTo() — so this template only has to change
        the words, never the query.
    --}}
    <x-page-header
        :title="$canManage ? 'Support Desk' : 'Support'"
        :subtitle="$canManage
            ? 'Every company on the platform, newest movement first'
            : 'Ask the platform team anything, and follow it here'"
        :crumbs="['System' => null, 'Support' => null]"
    >
        <x-slot:actions>
            @allows('support.tickets.create')
                <button type="button" class="btn btn-primary"
                        data-modal="{{ route('admin.support.create') }}"
                        data-modal-title="Raise a ticket"
                        data-modal-sub="Tell us what is wrong and we will pick it up"
                        data-modal-size="lg">
                    <x-icon name="plus" :size="15" /> Raise a ticket
                </button>
            @endallows

            @allows('support.tickets.export')
                <a class="btn btn-ghost" href="{{ route('admin.support.export', request()->query()) }}">
                    <x-icon name="download" :size="15" /> Export
                </a>
            @endallows
        </x-slot:actions>
    </x-page-header>

    <div class="stat-grid">
        <div class="stat">
            <div class="stat-icon"><x-icon name="inbox" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Open</div>
                <div class="stat-value">{{ number_format($stats['open']) }}</div>
                <span class="text-xs text-muted">not yet resolved</span>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon" style="background: var(--warning-soft); color: var(--warning)">
                <x-icon name="clock" :size="21" />
            </div>
            <div class="stat-body">
                <div class="stat-label">{{ $canManage ? 'Waiting on us' : 'With support' }}</div>
                <div class="stat-value">{{ number_format($stats['waiting']) }}</div>
                {{--
                    Excludes "awaiting customer" on purpose: a ticket sitting
                    with the restaurant is not the desk being behind.
                --}}
                <span class="text-xs text-muted">needs an answer</span>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon" style="background: var(--danger-soft); color: var(--danger)">
                <x-icon name="zap" :size="21" />
            </div>
            <div class="stat-body">
                <div class="stat-label">Urgent</div>
                <div class="stat-value">{{ number_format($stats['urgent']) }}</div>
                <span class="text-xs text-muted">open and urgent</span>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon is-success"><x-icon name="mail" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">First reply</div>
                <div class="stat-value">
                    {{--
                        Null, not zero. A desk that has answered nothing yet has
                        no average, and "0 min" would read as the opposite.
                    --}}
                    @if ($stats['response_minutes'] === null)
                        —
                    @elseif ($stats['response_minutes'] < 60)
                        {{ $stats['response_minutes'] }}<span class="text-sm">m</span>
                    @else
                        {{ round($stats['response_minutes'] / 60, 1) }}<span class="text-sm">h</span>
                    @endif
                </div>
                <span class="text-xs text-muted">average, 30 days</span>
            </div>
        </div>
    </div>

    <div class="card" data-ajax-list="{{ route('admin.support.index') }}">
        <form method="GET" class="list-toolbar">
            <div class="list-search">
                <x-icon name="search" :size="15" />
                <label for="tk-search" class="sr-only">Search tickets</label>
                <input id="tk-search" type="search" name="q" value="{{ $search }}"
                       placeholder="Search reference, subject or message…" autocomplete="off" data-ajax-filter>
            </div>

            <div class="list-filters">
                @if ($canManage)
                    <label class="check">
                        <input type="checkbox" name="mine" value="1" @checked($mine) data-ajax-filter>
                        <span>Assigned to me</span>
                    </label>
                @endif

                <label for="f-tk-status" class="sr-only">Status</label>
                <select id="f-tk-status" name="status" data-ajax-filter>
                    <option value="">Any status</option>
                    @foreach ($statuses as $key => $label)
                        <option value="{{ $key }}" @selected($status === $key)>{{ $label }}</option>
                    @endforeach
                </select>

                <label for="f-tk-priority" class="sr-only">Priority</label>
                <select id="f-tk-priority" name="priority" data-ajax-filter>
                    <option value="">Any priority</option>
                    @foreach ($priorities as $key => $label)
                        <option value="{{ $key }}" @selected($priority === $key)>{{ $label }}</option>
                    @endforeach
                </select>

                <noscript><button type="submit" class="btn btn-sm">Filter</button></noscript>

                <a class="btn btn-sm btn-ghost" href="{{ route('admin.support.index') }}" data-ajax-reset>Reset</a>
            </div>
        </form>

        <div data-ajax-list-content>
            @include('admin.support._list')
        </div>
    </div>
@endsection
