@extends('admin.layouts.app')

@section('title', 'Shop Performance')

@push('styles')
    <link rel="stylesheet" href="{{ asset('assets/css/platform.css') }}?v={{ filemtime(public_path('assets/css/platform.css')) }}">
@endpush

@section('content')
    <x-page-header
        title="Shop Performance"
        subtitle="Orders, sales, expenses and profit for every shop"
        :crumbs="['Platform' => route('admin.platform.dashboard'), 'Shop Performance' => null]"
    >
        <x-slot:actions>
            @allows('platform.shops.export')
                <a class="btn btn-sm" href="{{ route('admin.platform.shops.export', request()->query()) }}">
                    <x-icon name="download" :size="15" /> Export
                </a>
            @endallows
        </x-slot:actions>
    </x-page-header>

    <div class="card" data-ajax-list="{{ route('admin.platform.shops') }}">
        <form method="GET" class="list-toolbar">
            <div class="list-search">
                <x-icon name="search" :size="15" />
                <label for="pf-search" class="sr-only">Search shops</label>
                <input id="pf-search" type="search" name="q" value="{{ $search }}"
                       placeholder="Search shop, code or company…" autocomplete="off" data-ajax-filter>
            </div>

            <div class="list-filters">
                <label for="pf-status" class="sr-only">Status</label>
                <select id="pf-status" name="status" data-ajax-filter>
                    <option value="">Any status</option>
                    @foreach (['active' => 'Active', 'inactive' => 'Inactive', 'suspended' => 'Suspended'] as $value => $label)
                        <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
                    @endforeach
                </select>

                <label for="pf-period" class="sr-only">Period</label>
                <select id="pf-period" name="period" data-ajax-filter>
                    @foreach ($periods as $value => $label)
                        <option value="{{ $value }}" @selected($period === $value)>{{ $label }}</option>
                    @endforeach
                </select>

                <noscript><button type="submit" class="btn btn-sm">Filter</button></noscript>

                <a class="btn btn-sm btn-ghost" href="{{ route('admin.platform.shops') }}" data-ajax-reset>Reset</a>
            </div>
        </form>

        <div data-ajax-list-content>
            @include('admin.platform._shops')
        </div>
    </div>
@endsection
