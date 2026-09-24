{{--
    Returns and refunds (SRS 13).

    Both directions on one screen. A shop asks "what did we take back, and
    what did we send back" as one question, and splitting it across two
    reports means neither gets opened.
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
            <th style="text-align:right">Purchase returns</th>
            <th style="text-align:right">Sent back</th>
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

                <td style="text-align:right" class="text-sm">
                    {{ $row->purchase_returns ? number_format($row->purchase_returns) : '—' }}
                </td>
                <td style="text-align:right" class="text-sm">
                    {{ $row->purchase_value > 0 ? $money($row->purchase_value) : '—' }}
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="6">
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
                <th style="text-align:right">{{ number_format($rows->sum('purchase_returns')) }}</th>
                <th style="text-align:right">{{ $money($rows->sum('purchase_value')) }}</th>
            </tr>
        </tfoot>
    @endif
</table>
