{{-- Read-only detail, rendered straight into the modal body. --}}

@php
    $pct = fn ($value) => rtrim(rtrim(number_format((float) $value, 3), '0'), '.').'%';

    // A worked example beats a table of percentages for checking a slab is
    // set up the way the accountant meant.
    $sample = 1000.0;
    $intra = $rate->split($sample, false);
    $inter = $rate->split($sample, true);
@endphp

<div class="cat-view-body">
    <div class="sec-name">{{ $rate->name }}</div>

    <div style="display:flex;flex-wrap:wrap;gap:7px;margin:8px 0 14px">
        <span class="badge {{ $rate->is_active ? 'badge-success' : 'badge-danger' }}">
            <span class="badge-dot"></span> {{ $rate->is_active ? 'Active' : 'Inactive' }}
        </span>
        <span class="badge badge-info">{{ $pct($rate->rate) }} total</span>
        @if ($rate->is_default)
            <span class="badge badge-brand">Default for new products</span>
        @endif
        <span class="badge">{{ number_format($rate->products_count) }} products</span>
    </div>

    <dl class="sec-facts">
        <div><dt>CGST</dt><dd>{{ $pct($rate->cgst) }}</dd></div>
        <div><dt>SGST</dt><dd>{{ $pct($rate->sgst) }}</dd></div>
        <div><dt>IGST</dt><dd>{{ $pct($rate->igst) }}</dd></div>
        <div><dt>Cess</dt><dd>{{ $pct($rate->cess) }}</dd></div>
    </dl>

    <div style="margin-top:16px">
        <div class="form-label">On a ₹1,000 taxable value</div>

        <div class="table-wrap" style="margin-top:6px">
            <table class="table">
                <thead>
                    <tr><th>Supply</th><th>CGST</th><th>SGST</th><th>IGST</th><th>Cess</th><th>Total</th></tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Within state</td>
                        <td>₹{{ number_format($intra['cgst'], 2) }}</td>
                        <td>₹{{ number_format($intra['sgst'], 2) }}</td>
                        <td>—</td>
                        <td>₹{{ number_format($intra['cess'], 2) }}</td>
                        <td><strong>₹{{ number_format(array_sum($intra), 2) }}</strong></td>
                    </tr>
                    <tr>
                        <td>Other state</td>
                        <td>—</td>
                        <td>—</td>
                        <td>₹{{ number_format($inter['igst'], 2) }}</td>
                        <td>₹{{ number_format($inter['cess'], 2) }}</td>
                        <td><strong>₹{{ number_format(array_sum($inter), 2) }}</strong></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal-actions">
    <button type="button" class="btn" data-modal-close>Close</button>

    @allows('finance.taxes.edit')
        <a class="btn btn-primary" href="{{ route('admin.taxes.edit', $rate) }}"
           data-modal="{{ route('admin.taxes.edit', $rate) }}"
           data-modal-title="Edit Tax Slab"
           data-modal-sub="{{ $rate->name }}">
            <x-icon name="edit" :size="15" /> Edit
        </a>
    @endallows
</div>
