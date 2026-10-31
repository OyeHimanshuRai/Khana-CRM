{{-- Sales day by day. Shared with the profit report, which asks the same
     question of the same rows and only emphasises a different column. --}}

@php
    $rows = $data['rows'];
    $money = fn ($v) => '₹'.number_format((float) $v, 2);
@endphp

<table class="table">
    <thead>
        <tr>
            <th>Date</th>
            <th style="text-align:right">Invoices</th>
            <th style="text-align:right">Taxable</th>
            <th style="text-align:right">Tax</th>
            <th style="text-align:right">Total</th>
            <th style="text-align:right">Collected</th>
            @allows('reports.profit_report.view')
                <th style="text-align:right">Cost</th>
                <th style="text-align:right">Gross profit</th>
            @endallows
        </tr>
    </thead>

    <tbody>
        @forelse ($rows as $row)
            @php $profit = (float) $row->taxable - (float) $row->cost; @endphp
            <tr>
                <td class="text-sm" style="white-space:nowrap">
                    {{ \Illuminate\Support\Carbon::parse($row->period)->format('d M Y') }}
                    <span class="text-xs text-muted" style="display:block">
                        {{ \Illuminate\Support\Carbon::parse($row->period)->format('D') }}
                    </span>
                </td>
                <td style="text-align:right" class="text-sm">{{ number_format($row->invoices) }}</td>
                <td style="text-align:right" class="text-sm">{{ $money($row->taxable) }}</td>
                <td style="text-align:right" class="text-sm">{{ $money($row->tax) }}</td>
                <td style="text-align:right"><strong>{{ $money($row->total) }}</strong></td>
                <td style="text-align:right" class="text-sm">{{ $money($row->collected) }}</td>

                @allows('reports.profit_report.view')
                    <td style="text-align:right" class="text-sm">{{ $money($row->cost) }}</td>
                    <td style="text-align:right">
                        <strong style="color:var({{ $profit < 0 ? '--danger' : '--success' }})">
                            {{ $money($profit) }}
                        </strong>
                    </td>
                @endallows
            </tr>
        @empty
            <tr>
                <td colspan="8">
                    <div class="empty">
                        <x-icon name="inbox" :size="26" />
                        <h3>Nothing sold in this period</h3>
                        <p class="text-sm">Try a wider date range.</p>
                    </div>
                </td>
            </tr>
        @endforelse
    </tbody>

    @if ($rows->isNotEmpty())
        <tfoot>
            <tr>
                <th>Total</th>
                <th style="text-align:right">{{ number_format($rows->sum('invoices')) }}</th>
                <th style="text-align:right">{{ $money($rows->sum('taxable')) }}</th>
                <th style="text-align:right">{{ $money($rows->sum('tax')) }}</th>
                <th style="text-align:right">{{ $money($rows->sum('total')) }}</th>
                <th style="text-align:right">{{ $money($rows->sum('collected')) }}</th>
                @allows('reports.profit_report.view')
                    <th style="text-align:right">{{ $money($rows->sum('cost')) }}</th>
                    <th style="text-align:right">
                        {{ $money($rows->sum('taxable') - $rows->sum('cost')) }}
                    </th>
                @endallows
            </tr>
        </tfoot>
    @endif
</table>
