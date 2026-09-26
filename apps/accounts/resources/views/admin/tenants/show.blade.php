@extends('khana::layouts.app')

@section('title', $tenant->name)

@section('content')
    <x-khana::page-header :title="$tenant->name"
        :subtitle="$tenant->gstin ? 'GSTIN '.$tenant->gstin : null"
        :crumbs="['Businesses' => route('admin.tenants.index'), $tenant->name => null]" />

    <div class="card" style="margin-bottom:16px">
        <div class="card-header"><span class="card-title">Status</span></div>
        <div class="card-body">
            <p>Currently <span class="badge badge-{{ $tenant->status === 'active' ? 'success' : 'danger' }}">{{ ucfirst($tenant->status) }}</span>
                @if ($tenant->status_reason)<span class="text-muted">— {{ $tenant->status_reason }}</span>@endif
            </p>
            <form method="POST" action="{{ route('admin.tenants.status', $tenant) }}" style="display:flex;gap:8px;align-items:center;margin-top:10px">
                @csrf
                <select name="status" class="form-control" style="width:auto">
                    <option value="active" @selected($tenant->status === 'active')>Active</option>
                    <option value="suspended" @selected($tenant->status === 'suspended')>Suspended</option>
                    <option value="closed" @selected($tenant->status === 'closed')>Closed</option>
                </select>
                <input name="reason" class="form-control" placeholder="Reason (optional)" style="max-width:280px">
                <button class="btn btn-sm btn-primary">Update</button>
            </form>
            <div class="form-hint">Suspending or closing signs the business's people out of every product.</div>
        </div>
    </div>

    <div class="card" style="margin-bottom:16px">
        <div class="card-header"><span class="card-title">Outlets</span></div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Outlet</th><th>Code</th><th>Plan</th><th>State</th></tr></thead>
                <tbody>
                    @foreach ($tenant->outlets as $outlet)
                        @php $sub = $outlet->subscriptions->sortByDesc('id')->first(); @endphp
                        <tr>
                            <td>{{ $outlet->name }}</td>
                            <td>{{ $outlet->code }}</td>
                            <td>{{ $sub?->plan->name ?? '—' }}</td>
                            <td>@if ($sub)<span class="badge badge-{{ $sub->stateTone() }}">{{ $sub->stateLabel() }}</span>@endif</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><span class="card-title">People</span></div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Name</th><th>Contact</th><th>Role</th><th>Status</th></tr></thead>
                <tbody>
                    @foreach ($tenant->users as $user)
                        <tr>
                            <td>{{ $user->name }}</td>
                            <td>{{ $user->email ?? $user->mobile }}</td>
                            <td>{{ $user->is_owner ? 'Owner' : 'Staff' }}</td>
                            <td><span class="badge badge-{{ $user->status === 'active' ? 'success' : 'info' }}">{{ ucfirst($user->status) }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endsection
