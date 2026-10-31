@extends('admin.layouts.app')

@section('title', 'SaaS Finance')

@push('styles')
    <link rel="stylesheet" href="{{ asset('assets/css/platform.css') }}?v={{ filemtime(public_path('assets/css/platform.css')) }}">
@endpush

@section('content')
    <x-page-header
        title="SaaS Finance"
        subtitle="What restaurants pay you, kept apart from what they sell"
        :crumbs="['Platform' => route('admin.platform.dashboard'), 'SaaS Finance' => null]"
    >
        <x-slot:actions>
            <x-info label="What this counts">
                Income is every subscription payment, refunds included, less the GST collected on it.
                Restaurant sales are not on this page.
            </x-info>
            @allows('platform.finance.export')
                <a class="btn btn-sm" href="{{ route('admin.platform.finance.export', request()->query()) }}">
                    <x-icon name="download" :size="15" /> Export
                </a>
            @endallows
        </x-slot:actions>
    </x-page-header>

    <div class="stat-grid">
        @include('admin.platform._stat', ['label' => 'Income', 'value' => '₹'.number_format($saas['income'], 0), 'sub' => '₹'.number_format($saas['month_income'], 0).' this month', 'icon' => 'wallet', 'tone' => 'success'])
        @include('admin.platform._stat', ['label' => 'GST collected', 'value' => '₹'.number_format($saas['gst'], 0), 'icon' => 'tag'])
        @include('admin.platform._stat', ['label' => 'Open checkouts', 'value' => number_format($saas['pending']), 'sub' => '₹'.number_format($saas['pending_amount'], 0), 'icon' => 'clock'])
        @include('admin.platform._stat', ['label' => 'Outstanding', 'value' => '₹'.number_format($outstanding->sum(fn ($s) => (float) $s->price), 0), 'sub' => number_format($outstanding->count()).' subscriptions', 'icon' => 'lock'])
    </div>

    <div class="pf-grid">
        @include('admin.platform._bars', ['title' => 'Income, last 12 months', 'rows' => collect($monthly)->map(fn ($m) => ['label' => $m['label'], 'value' => $m['income']])->all()])

        <div class="card pf-card">
            <div class="pf-card-head">
                <h2 class="pf-card-title">Outstanding</h2>
                <a class="btn btn-sm btn-ghost" href="{{ route('admin.subscriptions.index') }}">Subscriptions</a>
            </div>

            @if ($outstanding->isEmpty())
                <div class="pf-empty">Nobody owes anything.</div>
            @else
                <ul class="pf-list">
                    @foreach ($outstanding->take(8) as $subscription)
                        <li>
                            <span>{{ $subscription->tenant?->name }} · ₹{{ number_format((float) $subscription->price, 0) }}</span>
                            <span class="pf-when">{{ $subscription->stateLabel() }} · {{ $subscription->ends_at?->format('j M') }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>

    <h2 class="pf-section">Payments</h2>
    <div class="card" data-ajax-list="{{ route('admin.platform.finance') }}">
        <form method="GET" class="list-toolbar">
            <div class="list-search">
                <x-icon name="search" :size="15" />
                <label for="pf-pay-search" class="sr-only">Search payments</label>
                <input id="pf-pay-search" type="search" name="q" value="{{ $search }}"
                       placeholder="Search reference or company…" autocomplete="off" data-ajax-filter>
            </div>
            <noscript><button type="submit" class="btn btn-sm">Search</button></noscript>
        </form>

        <div data-ajax-list-content>
            @include('admin.platform._payments')
        </div>
    </div>
@endsection
