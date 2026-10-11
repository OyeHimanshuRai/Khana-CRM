@extends('admin.layouts.app')

@section('title', 'Coupons & Offers')

@section('content')
    <x-page-header
        title="Coupons & Offers"
        subtitle="Discounts customers can redeem at storefront checkout"
        :crumbs="['Sales' => null, 'Coupons & Offers' => null]"
    >
        <x-slot:actions>
            @allows('sales.coupons.export')
                <a class="btn btn-sm" href="{{ route('admin.coupons.export', request()->query()) }}">
                    <x-icon name="download" :size="15" /> Export
                </a>
            @endallows

            @allows('sales.coupons.create')
                <a class="btn btn-primary btn-sm" href="{{ route('admin.coupons.create') }}"
                   data-modal="{{ route('admin.coupons.create') }}"
                   data-modal-title="Add Coupon"
                   data-modal-sub="Create a storefront discount code"
                   data-modal-size="lg">
                    <x-icon name="plus" :size="15" /> Add Coupon
                </a>
            @endallows
        </x-slot:actions>
    </x-page-header>

    <div class="card" data-ajax-list="{{ route('admin.coupons.index') }}">
        <form method="GET" class="list-toolbar">
            <div class="list-search">
                <x-icon name="search" :size="15" />
                <label for="coupon-search" class="sr-only">Search coupons</label>
                <input id="coupon-search" type="search" name="q" value="{{ $search }}"
                       placeholder="Search code or description…" autocomplete="off" data-ajax-filter>
            </div>

            <div class="list-filters">
                <label for="f-status" class="sr-only">Status</label>
                <select id="f-status" name="status" data-ajax-filter>
                    <option value="">Any status</option>
                    <option value="active" @selected($status === 'active')>Active</option>
                    <option value="inactive" @selected($status === 'inactive')>Inactive</option>
                </select>

                <noscript><button type="submit" class="btn btn-sm">Filter</button></noscript>
                <a class="btn btn-sm btn-ghost" href="{{ route('admin.coupons.index') }}" data-ajax-reset>Reset</a>
            </div>
        </form>

        <div data-ajax-list-content>
            @include('admin.coupons._list')
        </div>
    </div>
@endsection
