@extends('admin.layouts.app')

@section('title', 'Orders')

@section('content')
    {{--
        Not "Online Orders" any more. Every channel writes to the same table,
        so this is the order history for all four - and the channel filter
        below is what narrows it back down to one.
    --}}
    <x-page-header
        title="Orders"
        subtitle="Every order, whichever channel it came from"
        :crumbs="['Sales' => null, 'Orders' => null]"
    >
        <x-slot:actions>
            @allows('sales.orders.export')
                <a class="btn btn-sm" href="{{ route('admin.orders.export', request()->query()) }}">
                    <x-icon name="download" :size="15" /> Export
                </a>
            @endallows
        </x-slot:actions>
    </x-page-header>

    <div class="card" data-ajax-list="{{ route('admin.orders.index') }}">
        <form method="GET" class="list-toolbar">
            <div class="list-search">
                <x-icon name="search" :size="15" />
                <label for="order-search" class="sr-only">Search orders</label>
                <input id="order-search" type="search" name="q" value="{{ $search }}"
                       placeholder="Search order #, customer or mobile…" autocomplete="off" data-ajax-filter>
            </div>

            <div class="list-filters">
                <label for="f-type" class="sr-only">Channel</label>
                <select id="f-type" name="type" data-ajax-filter>
                    <option value="">Every channel</option>
                    @foreach ($types as $key => $label)
                        <option value="{{ $key }}" @selected($type === $key)>{{ $label }}</option>
                    @endforeach
                </select>

                <label for="f-status" class="sr-only">Status</label>
                <select id="f-status" name="status" data-ajax-filter>
                    <option value="">Any status</option>
                    @foreach ($statuses as $key => $label)
                        <option value="{{ $key }}" @selected($status === $key)>{{ $label }}</option>
                    @endforeach
                </select>

                <label for="f-payment" class="sr-only">Payment status</label>
                <select id="f-payment" name="payment_status" data-ajax-filter>
                    <option value="">Any payment status</option>
                    <option value="pending" @selected($paymentStatus === 'pending')>Pending</option>
                    <option value="paid" @selected($paymentStatus === 'paid')>Paid</option>
                    <option value="failed" @selected($paymentStatus === 'failed')>Failed</option>
                    <option value="refunded" @selected($paymentStatus === 'refunded')>Refunded</option>
                </select>

                <noscript><button type="submit" class="btn btn-sm">Filter</button></noscript>
                <a class="btn btn-sm btn-ghost" href="{{ route('admin.orders.index') }}" data-ajax-reset>Reset</a>
            </div>
        </form>

        <div data-ajax-list-content>
            @include('admin.orders._list')
        </div>
    </div>
@endsection
