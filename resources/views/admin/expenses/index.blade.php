@extends('admin.layouts.app')

@section('title', 'Expenses')

@section('content')
    <x-page-header
        title="Expenses"
        subtitle="Money spent that is not buying stock. Approving an expense is what records it as money out."
        :crumbs="['Finance' => null, 'Expenses' => null]"
    >
        <x-slot:actions>
            @allows('finance.expenses.export')
                <a class="btn btn-sm" href="{{ route('admin.expenses.export', request()->query()) }}">
                    <x-icon name="download" :size="15" /> Export
                </a>
            @endallows

            @allows('finance.expenses.create')
                <a class="btn btn-primary btn-sm" href="{{ route('admin.expenses.create') }}"
                   data-modal="{{ route('admin.expenses.create') }}"
                   data-modal-title="Record an Expense"
                   data-modal-sub="Rent, wages, diesel — anything that is not stock"
                   data-modal-size="lg">
                    <x-icon name="plus" :size="15" /> Record Expense
                </a>
            @endallows
        </x-slot:actions>
    </x-page-header>

    <div class="stat-grid">
        <div class="stat">
            <div class="stat-icon" style="background: var(--danger-soft); color: var(--danger)">
                <x-icon name="wallet" :size="21" />
            </div>
            <div class="stat-body">
                <div class="stat-label">Approved</div>
                <div class="stat-value">₹{{ number_format($stats['approved'], 0) }}</div>
                <span class="text-xs text-muted">{{ number_format($stats['count']) }} entries</span>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon" style="background: var(--warning-soft); color: var(--warning)">
                <x-icon name="clock" :size="21" />
            </div>
            <div class="stat-body">
                <div class="stat-label">Awaiting Approval</div>
                <div class="stat-value">₹{{ number_format($stats['pending'], 0) }}</div>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon"><x-icon name="calendar" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">This Month</div>
                <div class="stat-value">₹{{ number_format($stats['this_month'], 0) }}</div>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon is-info"><x-icon name="list" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Categories in Use</div>
                <div class="stat-value">{{ number_format($byCategory->count()) }}</div>
            </div>
        </div>
    </div>

    @if ($byCategory->isNotEmpty())
        <div class="card" style="margin-bottom:16px">
            <div class="card-header">
                <div>
                    <div class="card-title">Where the money went</div>
                    <div class="text-xs text-muted">Approved spending, for the filters applied</div>
                </div>
            </div>

            <div class="card-body" style="display:flex;flex-wrap:wrap;gap:10px">
                @foreach ($byCategory as $row)
                    <div class="stat" style="flex:1 1 170px;min-width:170px">
                        <div class="stat-body">
                            <div class="stat-label">{{ $row->category }}</div>
                            <div class="stat-value" style="font-size:17px">
                                ₹{{ number_format((float) $row->total, 0) }}
                            </div>
                            <div class="text-xs text-muted">{{ number_format($row->entries) }} entries</div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <div class="card" data-ajax-list="{{ route('admin.expenses.index') }}">
        <form method="GET" class="list-toolbar">
            <div class="list-search">
                <x-icon name="search" :size="15" />
                <label for="exp-search" class="sr-only">Search expenses</label>
                <input id="exp-search" type="search" name="q" value="{{ $search }}"
                       placeholder="Search reference, title, payee or reference no…"
                       autocomplete="off" data-ajax-filter>
            </div>

            <div class="list-filters">
                <label for="f-category" class="sr-only">Category</label>
                <select id="f-category" name="category" data-ajax-filter>
                    <option value="">Any category</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}" @selected($categoryId === $category->id)>
                            {{ $category->name }}
                        </option>
                    @endforeach
                </select>

                <label for="f-status" class="sr-only">Status</label>
                <select id="f-status" name="status" data-ajax-filter>
                    <option value="">Any status</option>
                    @foreach ($statuses as $key => $meta)
                        <option value="{{ $key }}" @selected($status === $key)>{{ $meta['label'] }}</option>
                    @endforeach
                </select>

                <label for="f-from" class="sr-only">From date</label>
                <input id="f-from" type="date" name="from" value="{{ $from }}" data-ajax-filter>

                <label for="f-to" class="sr-only">To date</label>
                <input id="f-to" type="date" name="to" value="{{ $to }}" data-ajax-filter>

                <noscript><button type="submit" class="btn btn-sm">Filter</button></noscript>

                <a class="btn btn-sm btn-ghost" href="{{ route('admin.expenses.index') }}" data-ajax-reset>Reset</a>
            </div>
        </form>

        <div data-ajax-list-content>
            @include('admin.expenses._list')
        </div>
    </div>
@endsection
