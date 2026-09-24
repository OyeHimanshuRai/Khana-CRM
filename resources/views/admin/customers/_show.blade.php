{{-- Read-only customer detail, rendered straight into the modal body. --}}

@php
    $photo = $customer->imageUrl();
    $balance = (float) $customer->balance;
@endphp

<div class="cat-view">
    <div class="cat-view-image">
        @if ($photo)
            <img src="{{ $photo }}" alt="{{ $customer->name }}">
        @else
            <span class="text-xs text-muted">{{ $customer->initials() }}</span>
        @endif
    </div>

    <div class="cat-view-body">
        <div class="sec-name">{{ $customer->name }}</div>
        <div class="text-sm text-muted">{{ $customer->reference() }}</div>

        <div style="display:flex;flex-wrap:wrap;gap:7px;margin:8px 0 14px">
            <span class="badge {{ $customer->is_active ? 'badge-success' : 'badge-danger' }}">
                <span class="badge-dot"></span> {{ $customer->is_active ? 'Active' : 'Inactive' }}
            </span>

            <span class="badge">{{ $customer->typeLabel() }}</span>

            @if ($balance > 0)
                <span class="badge badge-danger">Owes ₹{{ number_format($balance, 2) }}</span>
            @elseif ($balance < 0)
                <span class="badge badge-success">₹{{ number_format(abs($balance), 2) }} in advance</span>
            @else
                <span class="badge badge-success">Nothing owed</span>
            @endif

            @if ($customer->isOverLimit())
                <span class="badge badge-danger">Over credit limit</span>
            @endif

            <span class="badge badge-info">{{ $customer->shop?->name ?? 'No shop' }}</span>
        </div>

        <dl class="sec-facts">
            <div><dt>Mobile</dt><dd>{{ $customer->mobile ?: '—' }}</dd></div>
            <div><dt>Alternate</dt><dd>{{ $customer->alt_mobile ?: '—' }}</dd></div>
            <div><dt>Email</dt><dd>{{ $customer->email ?: '—' }}</dd></div>
            <div><dt>GSTIN</dt><dd>{{ $customer->gstin ?: '—' }}</dd></div>
            <div>
                <dt>Credit</dt>
                <dd>{{ $customer->allow_credit ? 'Allowed' : 'Cash only' }}</dd>
            </div>
            <div>
                <dt>Credit limit</dt>
                <dd>{{ $customer->allow_credit ? '₹'.number_format((float) $customer->credit_limit, 2) : '—' }}</dd>
            </div>
            <div>
                <dt>Available credit</dt>
                <dd>{{ $customer->allow_credit ? '₹'.number_format($customer->availableCredit(), 2) : '—' }}</dd>
            </div>
            <div>
                <dt>Credit days</dt>
                <dd>{{ $customer->allow_credit ? $customer->credit_days.' days' : '—' }}</dd>
            </div>
            <div><dt>Opening balance</dt><dd>₹{{ number_format((float) $customer->opening_balance, 2) }}</dd></div>
            <div><dt>Land</dt><dd>{{ $customer->land_area ? $customer->land_area.' acres' : '—' }}</dd></div>
            <div><dt>Crops</dt><dd>{{ $customer->primary_crops ?: '—' }}</dd></div>
            <div><dt>Since</dt><dd>{{ $customer->created_at?->format('d M Y') ?? '—' }}</dd></div>
        </dl>

        @if ($customer->addressLine())
            <div style="margin-top:16px">
                <div class="form-label">Address</div>
                <p class="text-sm text-muted">{{ $customer->addressLine() }}</p>
            </div>
        @endif

        @if ($customer->notes)
            <div style="margin-top:16px">
                <div class="form-label">Notes</div>
                <p class="text-sm text-muted">{{ $customer->notes }}</p>
            </div>
        @endif
    </div>
</div>

<div class="modal-actions">
    <button type="button" class="btn" data-modal-close>Close</button>

    @allows('crm.customers.edit')
        <a class="btn btn-primary" href="{{ route('admin.customers.edit', $customer) }}"
           data-modal="{{ route('admin.customers.edit', $customer) }}"
           data-modal-title="Edit Customer"
           data-modal-sub="{{ $customer->name }}"
           data-modal-size="lg">
            <x-icon name="edit" :size="15" /> Edit
        </a>
    @endallows
</div>
