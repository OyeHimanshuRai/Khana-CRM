{{-- Sales by category. --}}

@php
    $rows = $data['rows'];
    $money = fn ($v) => '₹'.number_format((float) $v, 2);
    $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 3), '0'), '.');
    $total = (float) $rows->sum('revenue');
@endphp

<table class="table">
    <thead>
        <tr>
            <th>Category</th>
            <th style="text-align:right">Quantity</th>
            <th style="text-align:right">Revenue</th>
            <th style="text-align:right">Share</th>
            @allows('reports.profit_report.view')
                <th style="text-align:right">Gross profit</th>
            @endallows
        </tr>
    </thead>

    <tbody>
        @forelse ($rows as $row)
            @php $share = $total > 0 ? (float) $row->revenue / $total * 100 : 0; @endphp
            <tr>
                <td><strong>{{ $row->category }}</strong></td>
                <td style="text-align:right" class="text-sm">{{ $qty($row->quantity) }}</td>
                <td style="text-align:right"><strong>{{ $money($row->revenue) }}</strong></td>
                <td style="text-align:right" class="text-sm">{{ number_format($share, 1) }}%</td>
                @allows('reports.profit_report.view')
                    <td style="text-align:right" class="text-sm">
                        {{ $money((float) $row->revenue - (float) $row->cost) }}
                    </td>
                @endallows
            </tr>
        @empty
            <tr>
                <td colspan="5">
                    <div class="empty">
                        <x-icon name="grid" :size="26" />
                        <h3>Nothing sold in this period</h3>
                    </div>
                </td>
            </tr>
        @endforelse
    </tbody>

    @if ($rows->isNotEmpty())
        <tfoot>
            <tr>
                <th>Total</th>
                <th style="text-align:right">{{ $qty($rows->sum('quantity')) }}</th>
                <th style="text-align:right">{{ $money($total) }}</th>
                <th style="text-align:right">100%</th>
                @allows('reports.profit_report.view')
                    <th style="text-align:right">
                        {{ $money($rows->sum('revenue') - $rows->sum('cost')) }}
                    </th>
                @endallows
            </tr>
        </tfoot>
    @endif
</table>
