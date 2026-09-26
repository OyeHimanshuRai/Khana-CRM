{{--
    Read-only company detail, rendered straight into the modal body.

    Deliberately not the form: "View" should never be one stray keystroke
    away from renaming a business.
--}}

@php
    $logo = $tenant->logoUrl();
    $currentTenantId = App\Support\CurrentTenant::id();
@endphp

<div class="cat-view">
    <div class="cat-view-image">
        @if ($logo)
            <img src="{{ $logo }}" alt="{{ $tenant->name }}">
        @else
            <span class="text-xs text-muted">No logo</span>
        @endif
    </div>

    <div class="cat-view-body">
        <div class="sec-name">{{ $tenant->name }}</div>

        @if ($tenant->legal_name)
            <div class="text-sm text-muted">{{ $tenant->legal_name }}</div>
        @endif

        <div style="display:flex;flex-wrap:wrap;gap:7px;margin:8px 0 14px">
            <span class="badge {{ $tenant->is_active ? 'badge-success' : 'badge-danger' }}">
                <span class="badge-dot"></span> {{ $tenant->is_active ? 'Trading' : 'Suspended' }}
            </span>
            <span class="badge badge-info">{{ $tenant->code }}</span>

            @if ($tenant->id === $currentTenantId)
                <span class="badge badge-brand">You are working here</span>
            @endif
        </div>

        @unless ($tenant->is_active)
            <div class="alert alert-danger" style="margin-bottom:14px">
                <strong>Suspended {{ $tenant->suspended_at?->format('d M Y') ?? '' }}.</strong>
                {{ $tenant->suspend_reason ?: 'No reason recorded.' }}
                Staff cannot sign in; every invoice, payment and stock balance is untouched.
            </div>
        @endunless

        <dl class="sec-facts">
            <div><dt>GSTIN</dt><dd>{{ $tenant->gstin ?: '—' }}</dd></div>
            <div><dt>PAN</dt><dd>{{ $tenant->pan ?: '—' }}</dd></div>
            <div><dt>State code</dt><dd>{{ $tenant->state_code ?: '—' }}</dd></div>
            <div><dt>Phone</dt><dd>{{ $tenant->phone ?: '—' }}</dd></div>
            <div><dt>Email</dt><dd>{{ $tenant->email ?: '—' }}</dd></div>
            <div><dt>Currency</dt><dd>{{ $tenant->currency }}</dd></div>
            <div><dt>Timezone</dt><dd>{{ $tenant->timezone }}</dd></div>
            <div><dt>Staff</dt><dd>{{ number_format($tenant->users_count) }}</dd></div>
            <div><dt>Created</dt><dd>{{ $tenant->created_at?->format('d M Y') ?? '—' }}</dd></div>
        </dl>

        @if ($tenant->addressLine())
            <div style="margin-top:16px">
                <div class="form-label">Address</div>
                <p class="text-sm text-muted">
                    {{ $tenant->addressLine() }}{{ $tenant->country ? ', '.$tenant->country : '' }}
                </p>
            </div>
        @endif

        <div style="margin-top:16px">
            <div class="form-label">Branches ({{ $tenant->shops_count }})</div>

            @if ($branches->isEmpty())
                <p class="text-sm text-muted">
                    No branches yet. A company with no branch has nowhere to file an invoice —
                    add one from Shops.
                </p>
            @else
                <div class="table-wrap" style="margin-top:6px">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Branch</th>
                                <th>Code</th>
                                <th>Location</th>
                                <th style="text-align:right">Staff</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($branches as $branch)
                                <tr>
                                    <td class="text-sm"><strong>{{ $branch->name }}</strong></td>
                                    <td><span class="list-ref">{{ $branch->code }}</span></td>
                                    <td class="text-sm">
                                        {{ collect([$branch->city, $branch->state])->filter()->implode(', ') ?: '—' }}
                                    </td>
                                    <td style="text-align:right" class="text-sm">
                                        {{ number_format($branch->users_count) }}
                                    </td>
                                    <td>
                                        <span class="badge {{ $branch->is_active ? 'badge-success' : 'badge-danger' }}">
                                            {{ $branch->is_active ? 'Active' : 'Inactive' }}
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</div>

<div class="modal-actions">
    <button type="button" class="btn" data-modal-close>Close</button>

    @allows('settings.tenants.edit')
        <a class="btn btn-primary" href="{{ route('admin.tenants.edit', $tenant) }}"
           data-modal="{{ route('admin.tenants.edit', $tenant) }}"
           data-modal-title="Edit Company"
           data-modal-sub="{{ $tenant->name }}"
           data-modal-size="lg">
            <x-icon name="edit" :size="15" /> Edit
        </a>
    @endallows
</div>
