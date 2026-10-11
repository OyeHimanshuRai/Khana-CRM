@extends('admin.layouts.app')

@section('title', 'Table QR Codes')

@section('content')
    <x-page-header
        title="Table QR Codes"
        subtitle="One code per table. A guest scans it and the menu opens on their own phone — no app to install."
        :crumbs="['Floor' => null, 'QR Codes' => null]"
    >
        <x-slot:actions>
            @allows('dining.qr.print')
                <a class="btn btn-sm" href="{{ route('admin.qr.sheet', request()->only('floor_id', 'q')) }}"
                   target="_blank" rel="noopener">
                    <x-icon name="file" :size="15" /> Print sheet
                </a>
            @endallows

            @if ($stats['missing'] > 0)
                @allows('dining.qr.create')
                    <form method="POST" action="{{ route('admin.qr.issue-missing') }}"
                          data-ajax data-refresh-list style="display:inline">
                        @csrf
                        <button type="submit" class="btn btn-primary btn-sm">
                            <x-icon name="plus" :size="15" />
                            Issue {{ number_format($stats['missing']) }} missing
                        </button>
                    </form>
                @endallows
            @endif
        </x-slot:actions>
    </x-page-header>

    <div class="stat-grid">
        <div class="stat">
            <div class="stat-icon"><x-icon name="grid" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Tables in service</div>
                <div class="stat-value">{{ number_format($stats['tables']) }}</div>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon is-success"><x-icon name="scan" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">With a code</div>
                <div class="stat-value">{{ number_format($stats['coded']) }}</div>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon" style="background: var(--danger-soft); color: var(--danger)">
                <x-icon name="user-x" :size="21" />
            </div>
            <div class="stat-body">
                <div class="stat-label">Without one</div>
                <div class="stat-value">{{ number_format($stats['missing']) }}</div>
                @if ($stats['missing'] > 0)
                    <span class="text-xs text-muted">these tables cannot take QR orders</span>
                @endif
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon is-info"><x-icon name="trend-up" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Scans</div>
                <div class="stat-value">{{ number_format($stats['scans']) }}</div>
                <span class="text-xs text-muted">on codes in use</span>
            </div>
        </div>
    </div>

    <div class="card" data-ajax-list="{{ route('admin.qr.index') }}">
        <form method="GET" class="list-toolbar">
            <div class="list-search">
                <x-icon name="search" :size="15" />
                <label for="qr-search" class="sr-only">Search tables</label>
                <input id="qr-search" type="search" name="q" value="{{ $search }}"
                       placeholder="Search table, code or area…" autocomplete="off" data-ajax-filter>
            </div>

            <div class="list-filters">
                <label for="qr-floor" class="sr-only">Dining area</label>
                <select id="qr-floor" name="floor_id" data-ajax-filter>
                    <option value="">Any area</option>
                    @foreach ($floors as $floor)
                        <option value="{{ $floor->id }}" @selected($floorId === $floor->id)>
                            {{ $floor->name }}
                        </option>
                    @endforeach
                </select>

                <noscript><button type="submit" class="btn btn-sm">Filter</button></noscript>

                <a class="btn btn-sm btn-ghost" href="{{ route('admin.qr.index') }}" data-ajax-reset>Reset</a>
            </div>
        </form>

        <div data-ajax-list-content>
            @include('admin.qr._list')
        </div>
    </div>
@endsection
