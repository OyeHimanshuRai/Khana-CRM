{{-- Read-only batch detail, rendered straight into the modal body. --}}

@php
    $tone = $batch->expiryTone();
    $days = $batch->daysToExpiry();
    $onHand = (float) $stockRows->sum('quantity');
    $value = (float) $stockRows->sum(fn ($row) => $row->value());
@endphp

<div class="cat-view-body">
    <div class="sec-name">
        Batch <span class="list-ref">{{ $batch->batch_no }}</span>
    </div>
    <div class="text-sm text-muted">
        {{ $batch->product?->name }} · {{ $batch->product?->sku }}
    </div>

    <div style="display:flex;flex-wrap:wrap;gap:7px;margin:8px 0 14px">
        <span class="badge {{ $batch->is_active ? 'badge-success' : 'badge-danger' }}">
            <span class="badge-dot"></span> {{ $batch->is_active ? 'Sellable' : 'Blocked from sale' }}
        </span>

        @if ($batch->expiry_date)
            <span class="badge {{ $tone ? 'badge-'.$tone : '' }}">
                @if ($days < 0)
                    Expired {{ abs($days) }} days ago
                @elseif ($days === 0)
                    Expires today
                @else
                    Expires in {{ $days }} days
                @endif
            </span>
        @else
            <span class="badge">No expiry date</span>
        @endif

        <span class="badge badge-info">{{ $batch->shop?->name ?? 'No shop' }}</span>
    </div>

    <dl class="sec-facts">
        <div><dt>Manufactured</dt><dd>{{ $batch->mfg_date?->format('d M Y') ?? '—' }}</dd></div>
        <div><dt>Expires</dt><dd>{{ $batch->expiryLabel() }}</dd></div>
        <div>
            <dt>On hand</dt>
            <dd>{{ $batch->product?->unit?->format($onHand) ?? number_format($onHand, 3) }}</dd>
        </div>
        <div><dt>Value</dt><dd>₹{{ number_format($value, 2) }}</dd></div>
        <div><dt>Purchase price</dt><dd>₹{{ number_format((float) $batch->purchase_price, 2) }}</dd></div>
        <div><dt>MRP</dt><dd>₹{{ number_format((float) $batch->mrp, 2) }}</dd></div>
        <div>
            <dt>Selling price</dt>
            <dd>
                {{ (float) $batch->selling_price > 0
                    ? '₹'.number_format((float) $batch->selling_price, 2)
                    : 'follows the product' }}
            </dd>
        </div>
        <div><dt>Supplier ref</dt><dd>{{ $batch->supplier_batch_ref ?: '—' }}</dd></div>
        <div><dt>Registered</dt><dd>{{ $batch->created_at?->format('d M Y') ?? '—' }}</dd></div>
    </dl>
</div>

@if ($stockRows->isNotEmpty())
    <div style="margin-top:16px">
        <div class="form-label">Where this lot is</div>

        <div class="table-wrap" style="margin-top:6px">
            <table class="table">
                <thead>
                    <tr>
                        <th>Warehouse</th>
                        <th style="text-align:right">On hand</th>
                        <th style="text-align:right">Reserved</th>
                        <th style="text-align:right">Avg cost</th>
                        <th style="text-align:right">Value</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($stockRows as $row)
                        <tr>
                            <td>{{ $row->warehouse?->name ?? '—' }}</td>
                            <td style="text-align:right">{{ number_format((float) $row->quantity, 3) }}</td>
                            <td style="text-align:right">{{ number_format((float) $row->reserved, 3) }}</td>
                            <td style="text-align:right">₹{{ number_format((float) $row->average_cost, 2) }}</td>
                            <td style="text-align:right">₹{{ number_format($row->value(), 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif

<div class="modal-actions">
    <button type="button" class="btn" data-modal-close>Close</button>

    @allows('inventory.batches.edit')
        <a class="btn btn-primary" href="{{ route('admin.batches.edit', $batch) }}"
           data-modal="{{ route('admin.batches.edit', $batch) }}"
           data-modal-title="Edit Batch"
           data-modal-sub="{{ $batch->batch_no }}">
            <x-icon name="edit" :size="15" /> Edit
        </a>
    @endallows
</div>
