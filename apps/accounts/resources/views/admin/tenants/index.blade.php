@extends('khana::layouts.app')

@section('title', 'Businesses')

@section('content')
    <x-khana::page-header title="Businesses" subtitle="Every business on the platform" />

    <form method="GET" class="list-toolbar" style="margin-bottom:16px;display:flex;flex-wrap:wrap;gap:8px">
        <input class="form-control list-search" name="q" value="{{ $filters['q'] }}" placeholder="Name, email or mobile…" style="max-width:240px">
        <select name="status" class="form-control" style="width:auto">
            <option value="">Any status</option>
            @foreach (['active' => 'Active', 'suspended' => 'Suspended', 'closed' => 'Closed'] as $value => $label)
                <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
            @endforeach
        </select>
        <select name="state" class="form-control" style="width:auto">
            <option value="">Any plan state</option>
            @foreach ($states as $value => $label)
                <option value="{{ $value }}" @selected($filters['state'] === $value)>{{ $label }}</option>
            @endforeach
        </select>
        <select name="plan" class="form-control" style="width:auto">
            <option value="">Any plan</option>
            @foreach ($plans as $plan)
                <option value="{{ $plan->code }}" @selected($filters['plan'] === $plan->code)>{{ $plan->name }}</option>
            @endforeach
        </select>
        <button class="btn btn-ghost btn-sm">Filter</button>
        <a class="btn btn-ghost btn-sm" href="{{ route('admin.tenants.index') }}">Clear</a>
        <a class="btn btn-ghost btn-sm" href="{{ route('admin.tenants.index', array_filter($filters) + ['export' => 'csv']) }}">Download CSV</a>
    </form>

    <div class="card">
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr><th>Business</th><th>Owner</th><th>Plan</th><th>Plan state</th><th>Outlets</th><th>People</th><th>Last login</th><th>Signed up</th><th>Status</th><th></th></tr>
                </thead>
                <tbody>
                    @forelse ($tenants as $row)
                        @php $tenant = $row['tenant']; @endphp
                        <tr>
                            <td><strong>{{ $tenant->name }}</strong></td>
                            <td>
                                {{ $row['owner']?->name ?? '—' }}
                                <div class="text-muted" style="font-size:12px">{{ $row['owner']?->email ?? $tenant->email }} {{ $row['owner']?->mobile ?? $tenant->phone }}</div>
                            </td>
                            <td>{{ $row['plans'] ?: '—' }}</td>
                            <td>
                                <span class="badge badge-{{ $row['state_tone'] }}">{{ $row['state_label'] }}</span>
                                @if ($row['until'])<div class="text-muted" style="font-size:12px">until {{ $row['until']->format('d M Y') }}</div>@endif
                            </td>
                            <td>{{ $tenant->outlets_count }}</td>
                            <td>{{ $tenant->users_count }}</td>
                            <td>{{ $row['last_login_at'] ? \Illuminate\Support\Carbon::parse($row['last_login_at'])->diffForHumans() : 'Never' }}</td>
                            <td>{{ $tenant->created_at?->format('d M Y') }}</td>
                            <td><span class="badge badge-{{ $tenant->status === 'active' ? 'success' : 'danger' }}">{{ ucfirst($tenant->status) }}</span></td>
                            <td class="col-action"><a href="{{ route('admin.tenants.show', $tenant) }}" class="btn btn-sm btn-ghost">Open</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="10"><span class="text-muted">No businesses found.</span></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="list-footer" style="margin-top:14px">{{ $tenants->links() }}</div>
@endsection
