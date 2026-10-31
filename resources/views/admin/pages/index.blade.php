@extends('admin.layouts.app')

@section('title', 'Site Pages')

@section('content')
    <x-page-header
        title="Site Pages"
        :subtitle="$stats['total'].' page'.($stats['total'] === 1 ? '' : 's')"
        :crumbs="['Content' => null, 'Site Pages' => null]"
    >
        <x-slot:actions>
            @allows('settings.pages.create')
                <a class="btn btn-primary btn-sm" href="{{ route('admin.pages.create') }}"
                   data-modal="{{ route('admin.pages.create') }}"
                   data-modal-title="Add page"
                   data-modal-sub="Privacy policy, terms, refund policy, about…"
                   data-modal-size="lg">
                    <x-icon name="plus" :size="15" /> Add page
                </a>
            @endallows
        </x-slot:actions>
    </x-page-header>

    <div class="stat-grid">
        <div class="stat">
            <div class="stat-icon"><x-icon name="file" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Total</div>
                <div class="stat-value">{{ number_format($stats['total']) }}</div>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon is-success"><x-icon name="globe" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Published</div>
                <div class="stat-value">{{ number_format($stats['active']) }}</div>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon is-info"><x-icon name="list" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">In footer</div>
                <div class="stat-value">{{ number_format($stats['footer']) }}</div>
            </div>
        </div>
    </div>

    <div class="card" data-ajax-list="{{ route('admin.pages.index') }}">
        <form method="GET" class="list-toolbar">
            <div class="list-search">
                <x-icon name="search" :size="15" />
                <label for="page-search" class="sr-only">Search pages</label>
                <input id="page-search" type="search" name="q" value="{{ $search }}"
                       placeholder="Search title, address or text…" autocomplete="off" data-ajax-filter>
            </div>

            <div class="list-filters">
                <label for="f-status" class="sr-only">Status</label>
                <select id="f-status" name="status" data-ajax-filter>
                    <option value="">Any status</option>
                    <option value="active" @selected($status === 'active')>Published</option>
                    <option value="inactive" @selected($status === 'inactive')>Hidden</option>
                </select>

                <noscript><button type="submit" class="btn btn-sm">Filter</button></noscript>

                <a class="btn btn-sm btn-ghost" href="{{ route('admin.pages.index') }}" data-ajax-reset>Reset</a>
            </div>
        </form>

        <div data-ajax-list-content>
            @include('admin.pages._list')
        </div>
    </div>
@endsection
