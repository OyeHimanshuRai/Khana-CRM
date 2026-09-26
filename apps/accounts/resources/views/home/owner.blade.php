@extends('khana::layouts.app')

@section('title', 'Home')

@section('content')
    <x-khana::page-header :title="$tenant->name" subtitle="Your business at a glance">
        <x-slot:actions>
            <a href="{{ route('outlets.create') }}" class="btn btn-primary">
                <x-khana::icon name="plus" :size="16" /> Add outlet
            </a>
        </x-slot:actions>
    </x-khana::page-header>

    <div class="stat-grid">
        <div class="stat">
            <div class="stat-body">
                <div class="stat-label">Outlets</div>
                <div class="stat-value">{{ $outlets->count() }}</div>
            </div>
        </div>
        <div class="stat">
            <div class="stat-body">
                <div class="stat-label">Team</div>
                <div class="stat-value">{{ $tenant->users->count() }}</div>
            </div>
        </div>
    </div>

    <div class="card" style="margin-top:20px">
        <div class="card-header"><span class="card-title">Outlets &amp; plans</span></div>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr><th>Outlet</th><th>Plan</th><th>Status</th><th>Works until</th></tr>
                </thead>
                <tbody>
                    @forelse ($outlets as $outlet)
                        @php $sub = $outlet->subscriptions->sortByDesc('id')->first(); @endphp
                        <tr>
                            <td><strong>{{ $outlet->name }}</strong> <span class="text-muted">{{ $outlet->code }}</span></td>
                            <td>{{ $sub?->plan->name ?? '—' }}</td>
                            <td>
                                @if ($sub)
                                    <span class="badge badge-{{ $sub->stateTone() }}">{{ $sub->stateLabel() }}</span>
                                @else
                                    <span class="text-muted">No plan</span>
                                @endif
                            </td>
                            <td>{{ $sub?->status()[1]?->format('d M Y') ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4"><span class="text-muted">No outlets yet.</span></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
