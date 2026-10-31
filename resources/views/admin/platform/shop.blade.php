@extends('admin.layouts.app')

@section('title', $shop->name)

@push('styles')
    <link rel="stylesheet" href="{{ asset('assets/css/platform.css') }}?v={{ filemtime(public_path('assets/css/platform.css')) }}">
@endpush

@section('content')
    <x-page-header
        :title="$shop->name"
        :subtitle="($shop->tenant?->name ?? 'No company').' · '.$shop->code"
        :crumbs="['Platform' => route('admin.platform.dashboard'), 'Shop Performance' => route('admin.platform.shops'), $shop->name => null]"
    >
        <x-slot:actions>
            @if ($shop->tenant)
                <a class="btn btn-sm" href="{{ route('admin.tenants.show', $shop->tenant_id) }}">Company</a>
            @endif

            @allows('settings.shops.edit')
                <form method="POST" action="{{ route('admin.shops.status', $shop->id) }}" data-ajax data-redirect-delay="0"
                      style="display:inline" onsubmit="this.addEventListener('ajax:success', function () { location.reload(); })">
                    @csrf
                    @method('PUT')
                    <button type="submit" class="btn btn-sm">{{ $shop->is_active ? 'Deactivate' : 'Activate' }}</button>
                </form>
            @endallows
        </x-slot:actions>
    </x-page-header>

    <h2 class="pf-section">This month</h2>
    <div class="stat-grid">
        @include('admin.platform._stat', ['label' => 'Orders', 'value' => number_format($month['orders']), 'icon' => 'inbox'])
        @include('admin.platform._stat', ['label' => 'Sales', 'value' => '₹'.number_format($month['sales'], 0), 'icon' => 'chart'])
        @include('admin.platform._stat', ['label' => 'Expenses', 'value' => '₹'.number_format($month['expenses'], 0), 'icon' => 'wallet'])
        @include('admin.platform._stat', ['label' => 'Profit', 'value' => '₹'.number_format($month['profit'], 0), 'icon' => 'chart'])
    </div>

    <h2 class="pf-section">All time</h2>
    <div class="stat-grid">
        @include('admin.platform._stat', ['label' => 'Orders', 'value' => number_format($allTime['orders']), 'icon' => 'inbox'])
        @include('admin.platform._stat', ['label' => 'Sales', 'value' => '₹'.number_format($allTime['sales'], 0), 'icon' => 'chart'])
        @include('admin.platform._stat', ['label' => 'Expenses', 'value' => '₹'.number_format($allTime['expenses'], 0), 'icon' => 'wallet'])
        @include('admin.platform._stat', ['label' => 'Profit', 'value' => '₹'.number_format($allTime['profit'], 0), 'icon' => 'chart'])
    </div>

    <div class="pf-grid">
        @include('admin.platform._bars', ['title' => 'Sales, last 12 months', 'rows' => collect($trend)->map(fn ($m) => ['label' => $m['label'], 'value' => $m['sales']])->all()])

        <div class="card pf-card">
            <div class="pf-card-head">
                <h2 class="pf-card-title">Subscription</h2>
            </div>
            @if ($subscription)
                <ul class="pf-list">
                    <li><span>Plan</span><span>{{ $subscription->plan?->name ?? '—' }}</span></li>
                    <li><span>State</span><span>{{ $subscription->stateLabel() }}</span></li>
                    <li><span>Runs until</span><span>{{ $subscription->ends_at?->format('j M Y') ?? 'No end date' }}</span></li>
                </ul>
            @else
                <div class="pf-empty">Not on a plan.</div>
            @endif
        </div>

        <div class="card pf-card">
            <div class="pf-card-head">
                <h2 class="pf-card-title">Owner and staff</h2>
            </div>
            @if ($people->isEmpty())
                <div class="pf-empty">Nobody is assigned to this shop.</div>
            @else
                <ul class="pf-list">
                    @foreach ($people as $person)
                        <li>
                            <span>{{ $person->name }} <span class="text-xs text-muted">{{ $person->email }}</span></span>
                            <span class="pf-when">{{ $person->roles->pluck('name')->implode(', ') ?: '—' }}{{ $person->is_active ? '' : ' · inactive' }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        <div class="card pf-card">
            <div class="pf-card-head">
                <h2 class="pf-card-title">Recent activity</h2>
            </div>
            @if ($activity->isEmpty())
                <div class="pf-empty">Nothing recorded yet.</div>
            @else
                <ul class="pf-list">
                    @foreach ($activity as $entry)
                        <li>
                            <span>{{ $entry->description }}</span>
                            <span class="pf-when">{{ $entry->created_at?->format('j M, H:i') }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
@endsection
