{{-- Read-only detail, rendered straight into the modal body. --}}

<div class="cat-view-body">
    <div class="sec-name">{{ $unit->name }} <span class="list-ref">{{ $unit->code }}</span></div>

    <div style="display:flex;flex-wrap:wrap;gap:7px;margin:8px 0 14px">
        <span class="badge {{ $unit->is_active ? 'badge-success' : 'badge-danger' }}">
            <span class="badge-dot"></span> {{ $unit->is_active ? 'Active' : 'Inactive' }}
        </span>

        @if ($unit->allow_decimal)
            <span class="badge badge-info">Fractional · {{ $unit->precision }} dp</span>
        @else
            <span class="badge">Whole numbers only</span>
        @endif

        <span class="badge">{{ number_format($unit->products_count) }} products</span>
    </div>

    <dl class="sec-facts">
        <div><dt>Example</dt><dd>{{ $unit->format($unit->allow_decimal ? 2.5 : 3) }}</dd></div>
        <div><dt>Display order</dt><dd>{{ $unit->sort_order }}</dd></div>
        <div><dt>Created</dt><dd>{{ $unit->created_at?->format('d M Y') ?? '—' }}</dd></div>
        <div><dt>Updated</dt><dd>{{ $unit->updated_at?->format('d M Y') ?? '—' }}</dd></div>
    </dl>

    <p class="text-sm text-muted" style="margin-top:14px">
        {{ $unit->allow_decimal
            ? 'The counter may bill part quantities in this unit.'
            : 'The counter will round part quantities to whole numbers in this unit.' }}
    </p>
</div>

<div class="modal-actions">
    <button type="button" class="btn" data-modal-close>Close</button>

    @allows('inventory.units.edit')
        <a class="btn btn-primary" href="{{ route('admin.units.edit', $unit) }}"
           data-modal="{{ route('admin.units.edit', $unit) }}"
           data-modal-title="Edit Unit"
           data-modal-sub="{{ $unit->name }}">
            <x-icon name="edit" :size="15" /> Edit
        </a>
    @endallows
</div>
