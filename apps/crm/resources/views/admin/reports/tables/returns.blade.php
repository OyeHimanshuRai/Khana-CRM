{{--
    Returns and refunds (SRS 13).

    Sales returns only - what came back over the counter. Purchase returns
    belong to ERP now, which owns suppliers and what is sent back to them.
--}}

@php
    $rows = $data['rows'];
    $money = fn ($v) => '₹'.number_format((float) $v, 2);
@endphp

<table class="table">
    <thead>
        <tr>
            <th>Date</th>
            <th style="text-align:right">Sales returns</th>
            <th style="text-align:right">Taken back</th>
            <th style="text-align:right">Refunded</th>
        </tr>
    </thead>

    <tbody>
        @forelse ($rows as $row)
            <tr>
                <td>{{ \Illuminate\Support\Carbon::parse($row->period)->format('d M Y') }}</td>

                <td style="text-align:right" class="text-sm">
                    {{ $row->sales_returns ? number_format($row->sales_returns) : '—' }}
                </td>
                <td style="text-align:right">
                    @if ($row->sales_value > 0)
                        <strong style="color:var(--danger)">{{ $money($row->sales_value) }}</strong>
                    @else
                        <span class="text-muted">—</span>
                    @endif
                </td>
                <td style="text-align:right" class="text-sm">
                    {{ $row->refunded > 0 ? $money($row->refunded) : '—' }}
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="4">
                    <div class="empty">
                        <x-icon name="user-check" :size="26" />
                        <h3>Nothing came back in this period</h3>
                    </div>
                </td>
            </tr>
        @endforelse
    </tbody>

    @if ($rows->isNotEmpty())
        <tfoot>
            <tr>
                <th>Total</th>
                <th style="text-align:right">{{ number_format($rows->sum('sales_returns')) }}</th>
                <th style="text-align:right">{{ $money($rows->sum('sales_value')) }}</th>
                <th style="text-align:right">{{ $money($rows->sum('refunded')) }}</th>
            </tr>
        </tfoot>
    @endif
</table>
