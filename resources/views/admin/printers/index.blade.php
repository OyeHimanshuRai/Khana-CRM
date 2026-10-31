@extends('admin.layouts.app')

@section('title', 'Printers & Devices')

@section('content')
    <x-page-header
        title="Printers & Devices"
        subtitle="Where each kind of paper comes out"
        :crumbs="['Settings' => null, 'Printers' => null]"
    >
        <x-slot:actions>
            @allows('settings.printers.view')
                <a class="btn btn-sm" href="{{ route('admin.printers.jobs') }}">
                    <x-icon name="list" :size="15" /> Print Log
                </a>
            @endallows

            @allows('settings.printers.create')
                <a class="btn btn-primary btn-sm" href="{{ route('admin.printers.create') }}"
                   data-modal="{{ route('admin.printers.create') }}"
                   data-modal-title="Add Printer"
                   data-modal-sub="Where a kind of paper comes out"
                   data-modal-size="lg">
                    <x-icon name="plus" :size="15" /> Add Printer
                </a>
            @endallows
        </x-slot:actions>
    </x-page-header>

    <div class="stat-grid">
        <div class="stat">
            <div class="stat-icon"><x-icon name="file" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Printers</div>
                <div class="stat-value">{{ number_format($stats['total']) }}</div>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon is-success"><x-icon name="zap" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Automatic</div>
                <div class="stat-value">{{ number_format($stats['automatic']) }}</div>
                <span class="text-xs text-muted">print with nobody at a screen</span>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon" style="background: var(--danger-soft); color: var(--danger)">
                <x-icon name="x" :size="21" />
            </div>
            <div class="stat-body">
                <div class="stat-label">Failed today</div>
                <div class="stat-value">{{ number_format($stats['failed']) }}</div>
                @if ($stats['failed'] > 0)
                    <a class="text-xs" href="{{ route('admin.printers.jobs', ['failed' => 1]) }}">see what went wrong</a>
                @endif
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon" style="background: var(--warning-soft); color: var(--warning)">
                <x-icon name="file" :size="21" />
            </div>
            <div class="stat-body">
                <div class="stat-label">Reprints today</div>
                <div class="stat-value">{{ number_format($stats['reprints']) }}</div>
                <span class="text-xs text-muted">a second copy of the same ticket</span>
            </div>
        </div>
    </div>

    <div class="card" data-ajax-list="{{ route('admin.printers.index') }}">
        <form method="GET" class="list-toolbar">
            <div class="list-search">
                <x-icon name="search" :size="15" />
                <label for="p-search" class="sr-only">Search printers</label>
                <input id="p-search" type="search" name="q" value="{{ $search }}"
                       placeholder="Search name, code or address…" autocomplete="off" data-ajax-filter>
            </div>

            <div class="list-filters">
                <label for="f-kind" class="sr-only">Kind</label>
                <select id="f-kind" name="kind" data-ajax-filter>
                    <option value="">Any kind</option>
                    @foreach ($kinds as $value => $label)
                        <option value="{{ $value }}" @selected($kind === $value)>{{ $label }}</option>
                    @endforeach
                </select>

                <noscript><button type="submit" class="btn btn-sm">Filter</button></noscript>

                <a class="btn btn-sm btn-ghost" href="{{ route('admin.printers.index') }}" data-ajax-reset>Reset</a>
            </div>
        </form>

        <div data-ajax-list-content>
            @include('admin.printers._list')
        </div>
    </div>
@endsection
