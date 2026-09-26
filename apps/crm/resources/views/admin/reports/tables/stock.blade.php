{{-- What is on the shelf now, at weighted average cost. --}}

@php
    $rows = $data['rows'];
    $money = fn ($v) => '₹'.number_format((float) $v, 2);
    $qty = fn ($v) => number_format((float) $v, 3);
@endphp

<table class="table">
    <thead>
        <tr>
            <th>Product</th>
            <th>Warehouse</th>
            <th>Batch</th>
            <th>Expiry</th>
            <th style="text-align:right">On hand</th>
            <th style="text-align:right">Avg cost</th>
            <th style="text-align:right">Value</th>
        </tr>
    </thead>

    <tbody>
        @forelse ($rows as $row)
            <tr>
                <td>
                    <strong>{{ $row->product?->name ?? '—' }}</strong>
                    <span class="text-xs text-muted" style="display:block">{{ $row->product?->sku }}</span>
                </td>
                <td class="text-sm">{{ $row->warehouse?->name ?? '—' }}</td>
                <td class="text-sm">{{ $row->batch?->batch_no ?? '—' }}</td>
                <td class="text-sm">
                    @if ($row->batch?->expiry_date)
                        @php $tone = $row->batch->expiryTone(); @endphp
                        <span @if ($tone) class="badge badge-{{ $tone }}" @endif>
                            {{ $row->batch->expiryLabel() }}
                        </span>
                    @else
                        —
                    @endif
                </td>
                <td style="text-align:right" class="text-sm">
                    {{ $qty($row->quantity) }}
                    <span class="text-xs text-muted">{{ $row->product?->unit?->code }}</span>
                </td>
                <td style="text-align:right" class="text-sm">{{ $money($row->average_cost) }}</td>
                <td style="text-align:right"><strong>{{ $money($row->value()) }}</strong></td>
            </tr>
        @empty
            <tr>
                <td colspan="7">
                    <div class="empty">
                        <x-icon name="package" :size="26" />
                        <h3>Nothing on the shelf</h3>
                        <p class="text-sm">Receive a consignment to bring stock in.</p>
                    </div>
                </td>
            </tr>
        @endforelse
    </tbody>

    @if ($rows->isNotEmpty())
        <tfoot>
            <tr>
                <th colspan="6">Total value of what is shown</th>
                <th style="text-align:right">
                    {{ $money($rows->sum(fn ($r) => $r->value())) }}
                </th>
            </tr>
        </tfoot>
    @endif
</table>
