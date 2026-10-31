@extends('admin.layouts.app')

@section('title', 'Wastage')

@section('content')
    <x-page-header
        title="Wastage"
        :subtitle="'What the kitchen threw away · '.$range->label()"
        :crumbs="['Operations' => null, 'Wastage' => null]"
    >
        <x-slot:actions>
            @allows('inventory.wastage.export')
                <a class="btn btn-sm" href="{{ route('admin.wastage.export', request()->query()) }}">
                    <x-icon name="download" :size="15" /> Export CSV
                </a>
            @endallows

            @allows('inventory.wastage.create')
                <a class="btn btn-primary btn-sm" href="{{ route('admin.wastage.create') }}"
                   data-modal="{{ route('admin.wastage.create') }}"
                   data-modal-title="Write something off"
                   data-modal-sub="It comes off the shelf straight away">
                    <x-icon name="plus" :size="15" /> Record wastage
                </a>
            @endallows
        </x-slot:actions>
    </x-page-header>

    <div class="stat-grid">
        <div class="stat">
            <div class="stat-icon" style="background: var(--danger-soft); color: var(--danger)">
                <x-icon name="trash" :size="21" />
            </div>
            <div class="stat-body">
                <div class="stat-label">Lost</div>
                <div class="stat-value">₹{{ number_format($summary['loss'], 2) }}</div>
                <span class="text-xs text-muted">{{ $range->label() }}</span>
            </div>
        </div>

        {{--
            Two numbers because they answer two questions. "Lost" is the
            kitchen's failure; "written off" adds the staff meals and the
            tastings, which are stock that left unsold but are working as
            intended. Only shown when they differ - one number is easier to
            read than two identical ones.
        --}}
        @if ($summary['value'] > $summary['loss'])
            <div class="stat">
                <div class="stat-icon"><x-icon name="package" :size="21" /></div>
                <div class="stat-body">
                    <div class="stat-label">Written off in total</div>
                    <div class="stat-value">₹{{ number_format($summary['value'], 2) }}</div>
                    <span class="text-xs text-muted">including staff meals and tastings</span>
                </div>
            </div>
        @endif

        <div class="stat">
            <div class="stat-icon"><x-icon name="list" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Entries</div>
                <div class="stat-value">{{ number_format($summary['entries']) }}</div>
            </div>
        </div>

        {{-- The biggest reason, because it is the one worth acting on. --}}
        @if ($summary['by_reason']->isNotEmpty())
            @php $worst = $summary['by_reason']->first(); @endphp
            <div class="stat">
                <div class="stat-icon is-warning"><x-icon name="help" :size="21" /></div>
                <div class="stat-body">
                    <div class="stat-label">Mostly</div>
                    <div class="stat-value" style="font-size:16px; line-height:1.35">{{ $worst['label'] }}</div>
                    <span class="text-xs text-muted">₹{{ number_format($worst['value'], 2) }}</span>
                </div>
            </div>
        @endif
    </div>

    <div class="card" data-ajax-list="{{ route('admin.wastage.index') }}">
        <form method="GET" class="list-toolbar">
            <div class="list-search">
                <x-icon name="search" :size="15" />
                <label for="wa-search" class="sr-only">Search wastage</label>
                <input id="wa-search" type="search" name="q" value="{{ $search }}"
                       placeholder="Search item, note or who recorded it…" autocomplete="off" data-ajax-filter>
            </div>

            <div class="list-filters">
                <label for="wa-preset" class="sr-only">Period</label>
                <select id="wa-preset" name="preset" data-ajax-filter>
                    @foreach ($presets as $key => $label)
                        @continue ($key === 'custom')
                        <option value="{{ $key }}" @selected($range->preset === $key)>{{ $label }}</option>
                    @endforeach
                </select>

                <label for="wa-reason" class="sr-only">Reason</label>
                <select id="wa-reason" name="reason" data-ajax-filter>
                    <option value="">Any reason</option>
                    @foreach ($reasons as $key => $label)
                        <option value="{{ $key }}" @selected($reason === $key)>{{ $label }}</option>
                    @endforeach
                </select>

                <noscript><button type="submit" class="btn btn-sm">Filter</button></noscript>

                <a class="btn btn-sm btn-ghost" href="{{ route('admin.wastage.index') }}" data-ajax-reset>Reset</a>
            </div>
        </form>

        <div data-ajax-list-content>
            @include('admin.wastage._list')
        </div>
    </div>
@endsection
