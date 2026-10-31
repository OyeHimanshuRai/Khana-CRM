{{-- Read-only detail, rendered straight into the modal body. --}}

<div class="cat-view-body">
    <div class="sec-name">{{ $warehouse->name }} <span class="list-ref">{{ $warehouse->code }}</span></div>

    <div style="display:flex;flex-wrap:wrap;gap:7px;margin:8px 0 14px">
        <span class="badge {{ $warehouse->is_active ? 'badge-success' : 'badge-danger' }}">
            <span class="badge-dot"></span> {{ $warehouse->is_active ? 'Active' : 'Inactive' }}
        </span>

        @if ($warehouse->is_default)
            <span class="badge badge-brand">Default location</span>
        @endif

        <span class="badge badge-info">{{ $warehouse->shop?->name ?? 'No shop' }}</span>
    </div>

    <dl class="sec-facts">
        <div><dt>Stocked lines</dt><dd>{{ number_format($stockedLines) }}</dd></div>
        <div><dt>Stock value</dt><dd>₹{{ number_format($stockValue, 2) }}</dd></div>
        <div><dt>City</dt><dd>{{ $warehouse->city ?: '—' }}</dd></div>
        <div><dt>Phone</dt><dd>{{ $warehouse->phone ?: '—' }}</dd></div>
        <div><dt>Display order</dt><dd>{{ $warehouse->sort_order }}</dd></div>
        <div><dt>Created</dt><dd>{{ $warehouse->created_at?->format('d M Y') ?? '—' }}</dd></div>
    </dl>

    @if ($warehouse->address)
        <div style="margin-top:16px">
            <div class="form-label">Address</div>
            <p class="text-sm text-muted">{{ $warehouse->address }}</p>
        </div>
    @endif

    <p class="text-xs text-muted" style="margin-top:14px">
        Stock value is quantity × weighted average cost, which is what receipts have paid —
        not what the shelf price would fetch.
    </p>
</div>

<div class="modal-actions">
    <button type="button" class="btn" data-modal-close>Close</button>

    @allows('inventory.warehouses.edit')
        <a class="btn btn-primary" href="{{ route('admin.warehouses.edit', $warehouse) }}"
           data-modal="{{ route('admin.warehouses.edit', $warehouse) }}"
           data-modal-title="Edit Warehouse"
           data-modal-sub="{{ $warehouse->name }}">
            <x-icon name="edit" :size="15" /> Edit
        </a>
    @endallows
</div>
