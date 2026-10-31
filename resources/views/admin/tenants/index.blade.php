@extends('admin.layouts.app')

@section('title', 'Companies')

@section('content')
    <x-page-header
        title="Companies"
        :subtitle="$stats['total'].' compan'.($stats['total'] === 1 ? 'y' : 'ies').' · '.$stats['branches'].' branch'.($stats['branches'] === 1 ? '' : 'es')"
        :crumbs="['Settings' => null, 'Companies' => null]"
    >
        <x-slot:actions>
            @if ($canCreate)
                <a class="btn btn-primary btn-sm" href="{{ route('admin.tenants.create') }}"
                   data-modal="{{ route('admin.tenants.create') }}"
                   data-modal-title="Add Company"
                   data-modal-sub="Onboard a new business"
                   data-modal-size="lg">
                    <x-icon name="plus" :size="15" /> Add Company
                </a>
            @endif
        </x-slot:actions>
    </x-page-header>

    <div class="stat-grid">
        <div class="stat">
            <div class="stat-icon"><x-icon name="building" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Companies</div>
                <div class="stat-value">{{ number_format($stats['total']) }}</div>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon is-success"><x-icon name="user-check" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Trading</div>
                <div class="stat-value">{{ number_format($stats['active']) }}</div>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon" style="background: var(--danger-soft); color: var(--danger)">
                <x-icon name="lock" :size="21" />
            </div>
            <div class="stat-body">
                <div class="stat-label">Suspended</div>
                <div class="stat-value">{{ number_format($stats['suspended']) }}</div>
                <span class="text-xs text-muted">records kept, sign-in blocked</span>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon is-info"><x-icon name="grid" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Branches</div>
                <div class="stat-value">{{ number_format($stats['branches']) }}</div>
            </div>
        </div>
    </div>

    <div class="card" data-ajax-list="{{ route('admin.tenants.index') }}">
        <form method="GET" class="list-toolbar">
            <div class="list-search">
                <x-icon name="search" :size="15" />
                <label for="tenant-search" class="sr-only">Search companies</label>
                <input id="tenant-search" type="search" name="q" value="{{ $search }}"
                       placeholder="Search name, code, city or GSTIN…" autocomplete="off" data-ajax-filter>
            </div>

            <div class="list-filters">
                <label for="f-status" class="sr-only">Status</label>
                <select id="f-status" name="status" data-ajax-filter>
                    <option value="">Any status</option>
                    <option value="active" @selected($status === 'active')>Trading</option>
                    <option value="inactive" @selected($status === 'inactive')>Suspended</option>
                </select>

                <label for="f-sort" class="sr-only">Sort by</label>
                <select id="f-sort" name="sort" data-ajax-filter>
                    <option value="order" @selected($sort === 'order')>Display order</option>
                    <option value="name_asc" @selected($sort === 'name_asc')>Name A–Z</option>
                    <option value="name_desc" @selected($sort === 'name_desc')>Name Z–A</option>
                    <option value="newest" @selected($sort === 'newest')>Newest first</option>
                    <option value="oldest" @selected($sort === 'oldest')>Oldest first</option>
                </select>

                <noscript><button type="submit" class="btn btn-sm">Filter</button></noscript>

                <a class="btn btn-sm btn-ghost" href="{{ route('admin.tenants.index') }}" data-ajax-reset>Reset</a>
            </div>
        </form>

        <div data-ajax-list-content>
            @include('admin.tenants._list')
        </div>
    </div>
@endsection
