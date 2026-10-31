@extends('admin.layouts.app')

@section('title', 'Payment Reminders')

@section('content')
    <x-page-header
        title="Payment Reminders"
        subtitle="What the shop has told its customers about money owed, and what it is about to."
        :crumbs="['Customers' => null, 'Payment Reminders' => null]"
    >
        <x-slot:actions>
            @allows('crm.reminders.export')
                <a class="btn btn-sm" href="{{ route('admin.reminders.export', request()->query()) }}">
                    <x-icon name="download" :size="15" /> Export
                </a>
            @endallows

            @allows('crm.reminders.create')
                <form method="POST" action="{{ route('admin.reminders.run') }}"
                      data-ajax data-refresh-list style="display:inline">
                    @csrf
                    <button type="submit" class="btn btn-primary btn-sm">
                        <x-icon name="zap" :size="15" /> Run the scheduler now
                    </button>
                </form>
            @endallows
        </x-slot:actions>
    </x-page-header>

    @unless ($enabled)
        <div class="pos-warning" style="margin-bottom:16px">
            <strong>Reminders are switched off.</strong>
            Nothing will be scheduled or sent while <span class="list-ref">REMINDERS_ENABLED</span>
            is false. Existing rows are shown below unchanged.
        </div>
    @endunless

    <div class="stat-grid">
        <div class="stat">
            <div class="stat-icon" style="background: var(--warning-soft); color: var(--warning)">
                <x-icon name="clock" :size="21" />
            </div>
            <div class="stat-body">
                <div class="stat-label">Queued</div>
                <div class="stat-value">{{ number_format($stats['queued']) }}</div>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon is-success"><x-icon name="mail" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Sent</div>
                <div class="stat-value">{{ number_format($stats['sent']) }}</div>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon" style="background: var(--danger-soft); color: var(--danger)">
                <x-icon name="x" :size="21" />
            </div>
            <div class="stat-body">
                <div class="stat-label">Failed</div>
                <div class="stat-value">{{ number_format($stats['failed']) }}</div>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon is-info"><x-icon name="wallet" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Being Chased</div>
                <div class="stat-value">₹{{ number_format($stats['chasing'], 0) }}</div>
            </div>
        </div>
    </div>

    <div class="card" data-ajax-list="{{ route('admin.reminders.index') }}">
        <form method="GET" class="list-toolbar">
            <div class="list-search">
                <x-icon name="search" :size="15" />
                <label for="rem-search" class="sr-only">Search reminders</label>
                <input id="rem-search" type="search" name="q" value="{{ $search }}"
                       placeholder="Search customer, recipient or subject…" autocomplete="off"
                       data-ajax-filter>
            </div>

            <div class="list-filters">
                <label for="f-trigger" class="sr-only">Trigger</label>
                <select id="f-trigger" name="trigger" data-ajax-filter>
                    <option value="">Any trigger</option>
                    @foreach ($triggers as $key => $meta)
                        <option value="{{ $key }}" @selected($trigger === $key)>{{ $meta['label'] }}</option>
                    @endforeach
                </select>

                <label for="f-status" class="sr-only">Status</label>
                <select id="f-status" name="status" data-ajax-filter>
                    <option value="">Any status</option>
                    @foreach ($statuses as $key => $meta)
                        <option value="{{ $key }}" @selected($status === $key)>{{ $meta['label'] }}</option>
                    @endforeach
                </select>

                <noscript><button type="submit" class="btn btn-sm">Filter</button></noscript>

                <a class="btn btn-sm btn-ghost" href="{{ route('admin.reminders.index') }}" data-ajax-reset>Reset</a>
            </div>
        </form>

        <div data-ajax-list-content>
            @include('admin.reminders._list')
        </div>
    </div>
@endsection
