@extends('admin.layouts.app')

@section('title', 'Owner Dashboard')

@push('styles')
    <link rel="stylesheet" href="{{ asset('assets/css/platform.css') }}?v={{ filemtime(public_path('assets/css/platform.css')) }}">
@endpush

@section('content')
    <x-page-header
        title="Owner Dashboard"
        subtitle="Every company on the platform"
        :crumbs="['Platform' => null, 'Owner Dashboard' => null]"
    >
        <x-slot:actions>
            <x-info label="How these figures work">
                Restaurant sales belong to the restaurants. Your income is only what they pay for their plans, less GST.
                The two are never added together. Profit is sales before tax, less cost of goods and approved expenses.
            </x-info>
        </x-slot:actions>
    </x-page-header>

    <h2 class="pf-section">Your income</h2>
    <div class="stat-grid pf-five">
        @include('admin.platform._stat', ['label' => 'Income', 'value' => '₹'.number_format($saas['income'], 0), 'sub' => '₹'.number_format($saas['month_income'], 0).' this month', 'icon' => 'wallet', 'tone' => 'success'])
        @include('admin.platform._stat', ['label' => 'Completed payments', 'value' => number_format($saas['completed']), 'icon' => 'user-check', 'tone' => 'success'])
        @include('admin.platform._stat', ['label' => 'Pending payments', 'value' => number_format($saas['pending']), 'sub' => '₹'.number_format($saas['pending_amount'], 0).' in open checkouts', 'icon' => 'clock'])
        @include('admin.platform._stat', ['label' => 'Expiring in '.App\Services\PlatformReport::EXPIRING_DAYS.' days', 'value' => number_format($saas['expiring']->count()), 'icon' => 'clock'])
        @include('admin.platform._stat', ['label' => 'Expired', 'value' => number_format($saas['expired']), 'sub' => number_format($saas['due']).' more in grace', 'icon' => 'lock'])
    </div>

    <h2 class="pf-section">Companies and people</h2>
    <div class="stat-grid">
        @include('admin.platform._stat', ['label' => 'Companies', 'value' => number_format($overview['companies']), 'sub' => number_format($overview['companies_suspended']).' suspended', 'icon' => 'grid'])
        @include('admin.platform._stat', ['label' => 'Shops', 'value' => number_format($overview['shops']), 'sub' => number_format($overview['shops_active']).' active · '.number_format($overview['shops_inactive']).' inactive · '.number_format($overview['shops_suspended']).' suspended', 'icon' => 'building'])
        @include('admin.platform._stat', ['label' => 'Shop owners', 'value' => number_format($overview['owners']), 'icon' => 'user-check'])
        @include('admin.platform._stat', ['label' => 'Staff', 'value' => number_format($overview['staff']), 'icon' => 'user-check'])
    </div>

    <h2 class="pf-section">All restaurants together</h2>
    <div class="stat-grid">
        @include('admin.platform._stat', ['label' => 'Orders', 'value' => number_format($overview['all_time']['orders']), 'sub' => number_format($overview['month']['orders']).' this month', 'icon' => 'inbox'])
        @include('admin.platform._stat', ['label' => 'Sales', 'value' => '₹'.number_format($overview['all_time']['sales'], 0), 'sub' => '₹'.number_format($overview['month']['sales'], 0).' this month', 'icon' => 'chart'])
        @include('admin.platform._stat', ['label' => 'Expenses', 'value' => '₹'.number_format($overview['all_time']['expenses'], 0), 'sub' => '₹'.number_format($overview['month']['expenses'], 0).' this month', 'icon' => 'wallet'])
        @include('admin.platform._stat', ['label' => 'Profit', 'value' => '₹'.number_format($overview['all_time']['profit'], 0), 'sub' => '₹'.number_format($overview['month']['profit'], 0).' this month', 'icon' => 'chart'])
    </div>

    <div class="pf-grid">
        @include('admin.platform._bars', ['title' => 'Your income, last 12 months', 'rows' => collect($monthly)->map(fn ($m) => ['label' => $m['label'], 'value' => $m['income']])->all()])
        @include('admin.platform._bars', ['title' => 'Restaurant sales, last 12 months', 'rows' => collect($monthly)->map(fn ($m) => ['label' => $m['label'], 'value' => $m['sales']])->all()])
        @include('admin.platform._bars', ['title' => 'Your income by year', 'rows' => collect($yearly)->map(fn ($m) => ['label' => $m['label'], 'value' => $m['income']])->all()])
        @include('admin.platform._bars', ['title' => 'Restaurant sales by year', 'rows' => collect($yearly)->map(fn ($m) => ['label' => $m['label'], 'value' => $m['sales']])->all()])
    </div>

    <div class="pf-grid">
        <div class="card pf-card">
            <div class="pf-card-head">
                <h2 class="pf-card-title">Running out soon</h2>
                <a class="btn btn-sm btn-ghost" href="{{ route('admin.subscriptions.index') }}">Subscriptions</a>
            </div>

            @if ($saas['expiring']->isEmpty())
                <div class="pf-empty">Nobody runs out in the next {{ App\Services\PlatformReport::EXPIRING_DAYS }} days.</div>
            @else
                <ul class="pf-list">
                    @foreach ($saas['expiring']->take(8) as $subscription)
                        <li>
                            <span>{{ $subscription->tenant?->name }} · {{ $subscription->plan?->name }}</span>
                            <span class="pf-when">{{ $subscription->ends_at->format('j M') }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        <div class="card pf-card">
            <div class="pf-card-head">
                <h2 class="pf-card-title">Manage</h2>
            </div>
            <ul class="pf-list">
                <li><a href="{{ route('admin.platform.shops') }}">Shop performance</a></li>
                <li><a href="{{ route('admin.platform.finance') }}">SaaS finance</a></li>
                <li><a href="{{ route('admin.tenants.index') }}">Companies</a></li>
                <li><a href="{{ route('admin.plans.index') }}">Plans</a></li>
            </ul>
        </div>
    </div>
@endsection
