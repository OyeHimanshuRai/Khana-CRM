{{-- What was bought, from whom, and what is still owed. --}}

@php
    $rows = $data['rows'];
    $money = fn ($v) => '₹'.number_format((float) $v, 2);
@endphp

<table class="table">
    <thead>
        <tr>
            <th>Supplier</th>
            <th style="text-align:right">Consignments</th>
            <th style="text-align:right">Goods</th>
            <th style="text-align:right">Tax</th>
            <th style="text-align:right">Total</th>
            <th style="text-align:right">Still to pay</th>
        </tr>
    </thead>

    <tbody>
        @forelse ($rows as $row)
            <tr>
                <td><strong>{{ $row->supplier }}</strong></td>
                <td style="text-align:right" class="text-sm">{{ number_format($row->receipts) }}</td>
                <td style="text-align:right" class="text-sm">{{ $money($row->goods) }}</td>
                <td style="text-align:right" class="text-sm">{{ $money($row->tax) }}</td>
                <td style="text-align:right"><strong>{{ $money($row->total) }}</strong></td>
                <td style="text-align:right">
                    @if ((float) $row->outstanding > 0)
                        <strong style="color:var(--danger)">{{ $money($row->outstanding) }}</strong>
                    @else
                        <span class="text-muted">—</span>
                    @endif
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="6">
                    <div class="empty">
                        <x-icon name="truck" :size="26" />
                        <h3>Nothing received in this period</h3>
                    </div>
                </td>
            </tr>
        @endforelse
    </tbody>

    @if ($rows->isNotEmpty())
        <tfoot>
            <tr>
                <th>Total</th>
                <th style="text-align:right">{{ number_format($rows->sum('receipts')) }}</th>
                <th style="text-align:right">{{ $money($rows->sum('goods')) }}</th>
                <th style="text-align:right">{{ $money($rows->sum('tax')) }}</th>
                <th style="text-align:right">{{ $money($rows->sum('total')) }}</th>
                <th style="text-align:right">{{ $money($rows->sum('outstanding')) }}</th>
            </tr>
        </tfoot>
    @endif
</table>
