{{--
    Branch stock transfers (SRS 13, SRS 20.5).

    Both sides of the movement on one row. Dispatched-but-not-received is
    called out because that stock is in neither warehouse - it is on the van,
    and that is exactly the shortfall a transfer document exists to surface.
--}}

@php
    $rows = $data['rows'];
    $money = fn ($v) => '₹'.number_format((float) $v, 2);
    $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 3), '0'), '.');
@endphp

<table class="table">
    <thead>
        <tr>
            <th>Reference</th>
            <th>From</th>
            <th>To</th>
            <th style="text-align:right">Quantity</th>
            <th style="text-align:right">Value</th>
            <th>Moved</th>
            <th>Status</th>
        </tr>
    </thead>

    <tbody>
        @forelse ($rows as $row)
            <tr>
                <td>
                    <strong>
                        @allows('inventory.transfers.view')
                            <a href="{{ route('admin.stock-transfers.show', $row) }}">{{ $row->reference }}</a>
                        @else
                            {{ $row->reference }}
                        @endallows
                    </strong>
                    <span class="text-xs text-muted" style="display:block">
                        {{ $row->transfer_date?->format('d M Y') }}
                    </span>
                </td>

                <td class="text-sm">
                    {{ $row->shop?->name ?? '—' }}
                    <span class="text-xs text-muted" style="display:block">
                        {{ $row->fromWarehouse?->name ?? '—' }}
                    </span>
                </td>

                <td class="text-sm">
                    {{ $row->toShop?->name ?? '—' }}
                    <span class="text-xs text-muted" style="display:block">
                        {{ $row->toWarehouse?->name ?? '—' }}
                    </span>
                </td>

                <td style="text-align:right" class="text-sm">{{ $qty($row->total_quantity) }}</td>
                <td style="text-align:right" class="text-sm">{{ $money($row->total_value) }}</td>

                <td class="text-sm">
                    @if ($row->received_at)
                        {{ $row->received_at->format('d M Y') }}
                        <span class="text-xs text-muted" style="display:block">booked in</span>
                    @elseif ($row->dispatched_at)
                        {{ $row->dispatched_at->format('d M Y') }}
                        <span class="text-xs" style="display:block;color:var(--warning)">still in transit</span>
                    @else
                        —
                    @endif
                </td>

                <td>
                    <span class="badge badge-{{ $row->statusTone() ?: 'info' }}">{{ $row->statusLabel() }}</span>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="7">
                    <div class="empty">
                        <x-icon name="truck" :size="26" />
                        <h3>No transfers in this period</h3>
                    </div>
                </td>
            </tr>
        @endforelse
    </tbody>

    @if ($rows->isNotEmpty())
        <tfoot>
            <tr>
                <th colspan="3">Total</th>
                <th style="text-align:right">{{ $qty($rows->sum('total_quantity')) }}</th>
                <th style="text-align:right">{{ $money($rows->sum('total_value')) }}</th>
                <th colspan="2"></th>
            </tr>
        </tfoot>
    @endif
</table>
