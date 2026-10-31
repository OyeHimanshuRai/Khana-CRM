@extends('admin.layouts.app')

@section('title', 'Add-ons & Modifiers')

@section('content')
    <x-page-header
        title="Add-ons &amp; Modifiers"
        subtitle="The questions a dish asks — choose your crust, add extra cheese, how spicy."
        :crumbs="['Menu &amp; Stock' => null, 'Add-ons' => null]"
    >
        <x-slot:actions>
            @allows('inventory.products.view')
                <a class="btn btn-sm" href="{{ route('admin.products.index') }}">
                    <x-icon name="list" :size="15" /> Menu items
                </a>
            @endallows

            @allows('inventory.modifiers.create')
                <a class="btn btn-primary btn-sm" href="{{ route('admin.modifiers.create') }}"
                   data-modal="{{ route('admin.modifiers.create') }}"
                   data-modal-title="New Add-on"
                   data-modal-sub="A question and its answers"
                   data-modal-size="lg">
                    <x-icon name="plus" :size="15" /> New Add-on
                </a>
            @endallows
        </x-slot:actions>
    </x-page-header>

    <div class="stat-grid">
        <div class="stat">
            <div class="stat-icon"><x-icon name="help" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Questions</div>
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
            <div class="stat-icon is-warning"><x-icon name="star" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Must be answered</div>
                <div class="stat-value">{{ number_format($stats['required']) }}</div>
            </div>
        </div>

        {{--
            A question attached to nothing is a question nobody is asked.
            Only shown when there is one, so the row is never a habitual zero.
        --}}
        @if ($stats['unattached'] > 0)
            <div class="stat">
                <div class="stat-icon" style="background: var(--danger-soft); color: var(--danger)">
                    <x-icon name="user-x" :size="21" />
                </div>
                <div class="stat-body">
                    <div class="stat-label">On no dish</div>
                    <div class="stat-value">{{ number_format($stats['unattached']) }}</div>
                    <span class="text-xs text-muted">nobody is ever asked these</span>
                </div>
            </div>
        @endif
    </div>

    <div class="card" data-ajax-list="{{ route('admin.modifiers.index') }}">
        <form method="GET" class="list-toolbar">
            <div class="list-search">
                <x-icon name="search" :size="15" />
                <label for="mod-search" class="sr-only">Search add-ons</label>
                <input id="mod-search" type="search" name="q" value="{{ $search }}"
                       placeholder="Search a question or one of its answers…" autocomplete="off" data-ajax-filter>
            </div>

            <div class="list-filters">
                <label for="mod-status" class="sr-only">Status</label>
                <select id="mod-status" name="status" data-ajax-filter>
                    <option value="">Any status</option>
                    <option value="active" @selected($status === 'active')>Active</option>
                    <option value="inactive" @selected($status === 'inactive')>Inactive</option>
                </select>

                <noscript><button type="submit" class="btn btn-sm">Filter</button></noscript>

                <a class="btn btn-sm btn-ghost" href="{{ route('admin.modifiers.index') }}" data-ajax-reset>Reset</a>
            </div>
        </form>

        <div data-ajax-list-content>
            @include('admin.modifiers._list')
        </div>
    </div>
@endsection
