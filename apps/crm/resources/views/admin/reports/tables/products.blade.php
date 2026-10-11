{{-- Sales by product, with margin. --}}

@php
    $rows = $data['rows'];
    $money = fn ($v) => '₹'.number_format((float) $v, 2);
    $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 3), '0'), '.');
@endphp

<table class="table">
    <thead>
        <tr>
            <th>Product</th>
            <th style="text-align:right">Quantity</th>
            <th style="text-align:right">Revenue</th>
            @allows('reports.profit_report.view')
                <th style="text-align:right">Cost</th>
                <th style="text-align:right">Gross profit</th>
                <th style="text-align:right">Margin</th>
            @endallows
        </tr>
    </thead>

    <tbody>
        @forelse ($rows as $row)
            @php
                $profit = (float) $row->revenue - (float) $row->cost;
                $margin = (float) $row->revenue > 0 ? $profit / (float) $row->revenue * 100 : null;
            @endphp
            <tr>
                <td>
                    <strong>{{ $row->product_name }}</strong>
                    <span class="text-xs text-muted" style="display:block">{{ $row->sku }}</span>
                </td>
                <td style="text-align:right" class="text-sm">{{ $qty($row->quantity) }}</td>
                <td style="text-align:right"><strong>{{ $money($row->revenue) }}</strong></td>

                @allows('reports.profit_report.view')
                    <td style="text-align:right" class="text-sm">{{ $money($row->cost) }}</td>
                    <td style="text-align:right">
                        <strong style="color:var({{ $profit < 0 ? '--danger' : '--success' }})">
                            {{ $money($profit) }}
                        </strong>
                    </td>
                    <td style="text-align:right" class="text-sm">
                        @if ($margin === null)
                            —
                        @else
                            {{-- Anything under ten points is worth a second
                                 look before the next order is placed. --}}
                            <span style="color:var({{ $margin < 10 ? '--danger' : ($margin < 20 ? '--warning' : '--success') }})">
                                {{ number_format($margin, 1) }}%
                            </span>
                        @endif
                    </td>
                @endallows
            </tr>
        @empty
            <tr>
                <td colspan="6">
                    <div class="empty">
                        <x-icon name="package" :size="26" />
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
                <th style="text-align:right">{{ $money($rows->sum('revenue')) }}</th>
                @allows('reports.profit_report.view')
                    <th style="text-align:right">{{ $money($rows->sum('cost')) }}</th>
                    <th style="text-align:right">
                        {{ $money($rows->sum('revenue') - $rows->sum('cost')) }}
                    </th>
                    <th></th>
                @endallows
            </tr>
        </tfoot>
    @endif
</table>
