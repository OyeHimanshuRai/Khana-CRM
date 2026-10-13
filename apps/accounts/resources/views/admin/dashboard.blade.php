@extends('khana::layouts.app')

@section('title', 'Platform overview')

@php
    $rupees = fn (int $paise) => '₹'.number_format($paise / 100, 0);
    $tile = fn (string $label, $value, string $foot = '') => compact('label', 'value', 'foot');
    $tiles = [
        $tile('Businesses registered', $businesses['total'], $businesses['new_7'].' new this week · '.$businesses['new_30'].' this month'),
        $tile('Active businesses', $businesses['active'], $businesses['suspended'].' suspended · '.$businesses['closed'].' closed'),
        $tile('Used in the last 7 days', $businesses['in_use_7'], 'businesses with a sign-in'),
        $tile('Outlets', $outlets['total'], $outlets['active'].' active'),
        $tile('People', $people, 'across all businesses'),
        $tile('Monthly revenue', $rupees($mrr), 'paid subscriptions, yearly ÷ 12'),
    ];
@endphp

@section('content')
    <x-khana::page-header title="Platform overview" subtitle="Who is on Khana, and how it is going" />

    <div class="kpi-grid">
        @foreach ($tiles as $t)
            <div class="kpi">
                <div class="kpi-top"><span class="kpi-label">{{ $t['label'] }}</span></div>
                <div class="kpi-main"><div class="kpi-value">{{ $t['value'] }}</div></div>
                <span class="kpi-foot">{{ $t['foot'] }}</span>
            </div>
        @endforeach
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px;margin-top:16px">
        <div class="card">
            <div class="card-header"><h3>Subscriptions (outlets)</h3></div>
            <div class="table-wrap">
                <table class="table">
                    <tbody>
                        @foreach (['trial' => 'On trial', 'active' => 'Paying', 'grace' => 'Payment due', 'suspended' => 'Suspended', 'ended' => 'Ended'] as $key => $label)
                            <tr>
                                <td><a href="{{ route('admin.tenants.index', ['state' => in_array($key, ['suspended', 'ended']) ? 'expired' : $key]) }}">{{ $label }}</a></td>
                                <td style="text-align:right"><strong>{{ $states[$key] }}</strong></td>
                            </tr>
                        @endforeach
                        <tr><td>Outlets using CRM</td><td style="text-align:right"><strong>{{ $products['crm'] }}</strong></td></tr>
                        <tr><td>Outlets using ERP</td><td style="text-align:right"><strong>{{ $products['erp'] }}</strong></td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h3>New businesses, last 6 months</h3></div>
            <div class="card-body" style="display:flex;align-items:flex-end;gap:10px;height:150px">
                @foreach ($months as $m)
                    <div style="flex:1;text-align:center">
                        <div title="{{ $m['count'] }}" style="background:var(--primary);border-radius:4px 4px 0 0;height:{{ max(2, round($m['count'] / $maxMonth * 100)) }}px"></div>
                        <div class="text-muted" style="font-size:12px;margin-top:4px">{{ $m['label'] }}<br>{{ $m['count'] }}</div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    <div class="card" style="margin-top:16px">
        <div class="card-header"><h3>Latest signups</h3> <a href="{{ route('admin.tenants.index') }}" class="btn btn-sm btn-ghost">All businesses</a></div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Business</th><th>Outlets</th><th>People</th><th>Status</th><th>Joined</th><th></th></tr></thead>
                <tbody>
                    @forelse ($recent as $tenant)
                        <tr>
                            <td><strong>{{ $tenant->name }}</strong></td>
                            <td>{{ $tenant->outlets_count }}</td>
                            <td>{{ $tenant->users_count }}</td>
                            <td><span class="badge badge-{{ $tenant->status === 'active' ? 'success' : 'danger' }}">{{ ucfirst($tenant->status) }}</span></td>
                            <td>{{ $tenant->created_at?->diffForHumans() }}</td>
                            <td class="col-action"><a href="{{ route('admin.tenants.show', $tenant) }}" class="btn btn-sm btn-ghost">Open</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="6"><span class="text-muted">No businesses have signed up yet.</span></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px;margin-top:16px">
        @foreach ([['Trials ending within 7 days', $trialsEnding, 'No trial ends this week.'], ['Payment due', $dueSoon, 'Nobody is overdue.']] as [$title, $rows, $empty])
            <div class="card">
                <div class="card-header"><h3>{{ $title }}</h3></div>
                <div class="table-wrap">
                    <table class="table">
                        <tbody>
                            @forelse ($rows as [$subscription, $until])
                                <tr>
                                    <td>
                                        <a href="{{ route('admin.tenants.show', $subscription->tenant) }}"><strong>{{ $subscription->tenant->name }}</strong></a>
                                        <div class="text-muted" style="font-size:12px">{{ $subscription->outlet->name }} · {{ $subscription->plan->name }}</div>
                                    </td>
                                    <td style="text-align:right">{{ $until?->format('d M Y') }}</td>
                                </tr>
                            @empty
                                <tr><td><span class="text-muted">{{ $empty }}</span></td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endforeach
    </div>
@endsection
