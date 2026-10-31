@extends('admin.layouts.app')

@section('title', 'Tax / GST Setup')

@section('content')
    <x-page-header
        title="Tax / GST Setup"
        subtitle="Slabs products are taxed at. Invoices copy these numbers when they are raised, so editing a slab never rewrites tax already billed."
        :crumbs="['Finance' => null, 'Tax / GST' => null]"
    >
        <x-slot:actions>
            @allows('finance.taxes.create')
                <a class="btn btn-primary btn-sm" href="{{ route('admin.taxes.create') }}"
                   data-modal="{{ route('admin.taxes.create') }}"
                   data-modal-title="Add Tax Slab"
                   data-modal-sub="Create a GST rate">
                    <x-icon name="plus" :size="15" /> Add Slab
                </a>
            @endallows
        </x-slot:actions>
    </x-page-header>

    <div class="stat-grid">
        <div class="stat">
            <div class="stat-icon"><x-icon name="wallet" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Slabs</div>
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
            <div class="stat-icon is-info"><x-icon name="tag" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Nil-rated</div>
                <div class="stat-value">{{ number_format($stats['zero']) }}</div>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon"><x-icon name="trend-up" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Highest</div>
                <div class="stat-value">{{ rtrim(rtrim(number_format($stats['highest'], 2), '0'), '.') }}%</div>
            </div>
        </div>
    </div>

    <div class="card" data-ajax-list="{{ route('admin.taxes.index') }}">
        <form method="GET" class="list-toolbar">
            <div class="list-search">
                <x-icon name="search" :size="15" />
                <label for="tax-search" class="sr-only">Search slabs</label>
                <input id="tax-search" type="search" name="q" value="{{ $search }}"
                       placeholder="Search name or rate…" autocomplete="off" data-ajax-filter>
            </div>

            <div class="list-filters">
                <label for="f-status" class="sr-only">Status</label>
                <select id="f-status" name="status" data-ajax-filter>
                    <option value="">Any status</option>
                    <option value="active" @selected($status === 'active')>Active</option>
                    <option value="inactive" @selected($status === 'inactive')>Inactive</option>
                </select>

                <noscript><button type="submit" class="btn btn-sm">Filter</button></noscript>

                <a class="btn btn-sm btn-ghost" href="{{ route('admin.taxes.index') }}" data-ajax-reset>Reset</a>
            </div>
        </form>

        <div data-ajax-list-content>
            @include('admin.taxes._list')
        </div>
    </div>
@endsection
