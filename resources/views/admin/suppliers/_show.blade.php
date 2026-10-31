{{-- Read-only supplier detail, rendered straight into the modal body. --}}

@php $balance = (float) $supplier->balance; @endphp

<div class="cat-view-body">
    <div class="sec-name">{{ $supplier->displayName() }}</div>
    <div class="text-sm text-muted">{{ $supplier->reference() }}</div>

    <div style="display:flex;flex-wrap:wrap;gap:7px;margin:8px 0 14px">
        <span class="badge {{ $supplier->is_active ? 'badge-success' : 'badge-danger' }}">
            <span class="badge-dot"></span> {{ $supplier->is_active ? 'Active' : 'Inactive' }}
        </span>

        @if ($balance > 0)
            <span class="badge badge-danger">We owe ₹{{ number_format($balance, 2) }}</span>
        @elseif ($balance < 0)
            <span class="badge badge-success">₹{{ number_format(abs($balance), 2) }} paid ahead</span>
        @else
            <span class="badge badge-success">Settled</span>
        @endif

        <span class="badge badge-info">{{ $supplier->shop?->name ?? 'No shop' }}</span>
    </div>

    <dl class="sec-facts">
        <div><dt>Contact</dt><dd>{{ $supplier->contact_person ?: '—' }}</dd></div>
        <div><dt>Mobile</dt><dd>{{ $supplier->mobile ?: '—' }}</dd></div>
        <div><dt>Alternate</dt><dd>{{ $supplier->alt_mobile ?: '—' }}</dd></div>
        <div><dt>Email</dt><dd>{{ $supplier->email ?: '—' }}</dd></div>
        <div><dt>GSTIN</dt><dd>{{ $supplier->gstin ?: '—' }}</dd></div>
        <div><dt>PAN</dt><dd>{{ $supplier->pan ?: '—' }}</dd></div>
        <div>
            <dt>Terms</dt>
            <dd>{{ $supplier->credit_days > 0 ? $supplier->credit_days.' days' : 'Immediate' }}</dd>
        </div>
        <div><dt>Credit limit</dt><dd>₹{{ number_format((float) $supplier->credit_limit, 2) }}</dd></div>
        <div><dt>Opening balance</dt><dd>₹{{ number_format((float) $supplier->opening_balance, 2) }}</dd></div>
        <div><dt>Bank</dt><dd>{{ $supplier->bank_name ?: '—' }}</dd></div>
        <div><dt>Account</dt><dd>{{ $supplier->bank_account ?: '—' }}</dd></div>
        <div><dt>IFSC</dt><dd>{{ $supplier->bank_ifsc ?: '—' }}</dd></div>
        <div><dt>Since</dt><dd>{{ $supplier->created_at?->format('d M Y') ?? '—' }}</dd></div>
    </dl>

    @if ($supplier->addressLine())
        <div style="margin-top:16px">
            <div class="form-label">Address</div>
            <p class="text-sm text-muted">{{ $supplier->addressLine() }}</p>
        </div>
    @endif

    @if ($supplier->notes)
        <div style="margin-top:16px">
            <div class="form-label">Notes</div>
            <p class="text-sm text-muted">{{ $supplier->notes }}</p>
        </div>
    @endif
</div>

<div class="modal-actions">
    <button type="button" class="btn" data-modal-close>Close</button>

    @allows('purchasing.suppliers.edit')
        <a class="btn btn-primary" href="{{ route('admin.suppliers.edit', $supplier) }}"
           data-modal="{{ route('admin.suppliers.edit', $supplier) }}"
           data-modal-title="Edit Supplier"
           data-modal-sub="{{ $supplier->displayName() }}"
           data-modal-size="lg">
            <x-icon name="edit" :size="15" /> Edit
        </a>
    @endallows
</div>
