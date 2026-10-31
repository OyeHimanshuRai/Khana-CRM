@extends('admin.layouts.app')

@section('title', 'Refunds')

@section('content')
    <x-page-header
        title="Refunds"
        subtitle="What went back out"
        :crumbs="['Finance' => null, 'Online Payments' => route('admin.online-payments.index'), 'Refunds' => null]"
    >
        <x-slot:actions>
            <a class="btn btn-sm" href="{{ route('admin.online-payments.index') }}">
                <x-icon name="wallet" :size="15" /> Payments
            </a>
        </x-slot:actions>
    </x-page-header>

    @if ($failedCount > 0)
        {{--
            The one thing on this screen that needs acting on. A failed refund
            leaves somebody certain they paid a guest back who is still out of
            pocket, and "insufficient balance in your account" is the
            commonest reason there is.
        --}}
        <div class="alert alert-danger" style="margin-bottom:14px">
            <strong>{{ $failedCount }} refund{{ $failedCount === 1 ? '' : 's' }} did not go through.</strong>
            The guest has not been paid. Check the reason on each, put it right, and refund again.
            <a href="{{ route('admin.online-payments.refunds', ['failed' => 1]) }}">Show only those</a>.
        </div>
    @endif

    <div class="card" data-ajax-list="{{ route('admin.online-payments.refunds') }}">
        <form method="GET" class="list-toolbar">
            <div class="list-filters">
                <label class="check">
                    <input type="checkbox" name="failed" value="1" @checked($failed) data-ajax-filter>
                    <span>Failed only</span>
                </label>

                <noscript><button type="submit" class="btn btn-sm">Filter</button></noscript>

                <a class="btn btn-sm btn-ghost" href="{{ route('admin.online-payments.refunds') }}" data-ajax-reset>Reset</a>
            </div>
        </form>

        <div data-ajax-list-content>
            @include('admin.online-payments._refunds')
        </div>
    </div>
@endsection
