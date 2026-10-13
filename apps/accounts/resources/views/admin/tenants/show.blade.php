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
        <div class="card-header"><span class="card-title">Details</span></div>
        <div class="card-body">
            @php $owner = $tenant->users->firstWhere('is_owner', true); @endphp
            <p>Owner: {{ $owner?->name ?? '—' }} · {{ $owner?->email ?? $tenant->email ?? '—' }} · {{ $owner?->mobile ?? $tenant->phone ?? '—' }}</p>
            <p>Legal name: {{ $tenant->legal_name ?? '—' }} · GSTIN: {{ $tenant->gstin ?? '—' }}</p>
            <p class="text-muted">Signed up {{ $tenant->created_at?->format('d M Y, h:i A') }}</p>
        </div>
    </div>

    <div class="card" style="margin-bottom:16px">
        <div class="card-header"><span class="card-title">Outlets</span></div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Outlet</th><th>Code</th><th>Plan</th><th>State</th><th>Trial ends</th><th>Works until</th><th>Give extra days</th></tr></thead>
                <tbody>
                    @foreach ($tenant->outlets as $outlet)
                        @php $sub = $outlet->subscriptions->sortByDesc('id')->first(); @endphp
                        <tr>
                            <td>{{ $outlet->name }}</td>
                            <td>{{ $outlet->code }}</td>
                            <td>{{ $sub?->plan->name ?? '—' }} @if ($sub)<span class="text-muted">({{ $sub->billing_period }})</span>@endif</td>
                            <td>@if ($sub)<span class="badge badge-{{ $sub->stateTone() }}">{{ $sub->stateLabel() }}</span>@endif</td>
                            <td>{{ $sub?->trial_ends_at?->format('d M Y') ?? '—' }}</td>
                            <td>{{ $sub ? ($sub->ends_at?->format('d M Y') ?? 'No end date') : '—' }}</td>
                            <td>
                                @if ($sub?->ends_at)
                                    <form method="POST" action="{{ route('admin.tenants.extend', [$tenant, $sub]) }}" style="display:flex;gap:6px">
                                        @csrf
                                        <input type="number" name="days" min="1" max="365" value="7" class="form-control" style="width:80px">
                                        <button class="btn btn-sm btn-ghost">Extend</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    @php $payments = $tenant->outlets->flatMap(fn ($o) => $o->subscriptions->flatMap->payments)->sortByDesc('paid_at'); @endphp
    <div class="card" style="margin-bottom:16px">
        <div class="card-header"><span class="card-title">Payments</span></div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Paid on</th><th>Reference</th><th>Amount</th><th>Method</th><th>Period</th></tr></thead>
                <tbody>
                    @forelse ($payments as $payment)
                        <tr>
                            <td>{{ $payment->paid_at?->format('d M Y') }}</td>
                            <td>{{ $payment->reference }}</td>
                            <td>₹{{ number_format($payment->amount_paise / 100, 2) }}</td>
                            <td>{{ ucfirst($payment->method) }}</td>
                            <td>{{ $payment->period_start?->format('d M Y') }} – {{ $payment->period_end?->format('d M Y') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5"><span class="text-muted">No payments yet.</span></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><span class="card-title">People</span></div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Name</th><th>Contact</th><th>Role</th><th>Last login</th><th>Status</th></tr></thead>
                <tbody>
                    @foreach ($tenant->users as $user)
                        <tr>
                            <td>{{ $user->name }}</td>
                            <td>{{ $user->email ?? $user->mobile }}</td>
                            <td>{{ $user->is_owner ? 'Owner' : 'Staff' }}</td>
                            <td>{{ $user->last_login_at?->diffForHumans() ?? 'Never' }}</td>
                            <td><span class="badge badge-{{ $user->status === 'active' ? 'success' : 'info' }}">{{ ucfirst($user->status) }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endsection
