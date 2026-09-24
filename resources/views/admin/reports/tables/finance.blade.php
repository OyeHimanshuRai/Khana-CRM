{{--
    Financial summary (SRS 13).

    Month by month. Revenue is what was billed; collected is what was
    actually banked, and the gap between them is the credit book - which is
    why both are on the row rather than one standing in for the other.
--}}

@php
    $rows = $data['rows'];
    $money = fn ($v) => '₹'.number_format((float) $v, 2);
@endphp

<table class="table">
    <thead>
        <tr>
            <th>Month</th>
            <th style="text-align:right">Revenue</th>
            <th style="text-align:right">Cost of sales</th>
            <th style="text-align:right">Gross</th>
            <th style="text-align:right">Expenses</th>
            <th style="text-align:right">Net</th>
            <th style="text-align:right">Collected</th>
            <th style="text-align:right">Purchases</th>
        </tr>
    </thead>

    <tbody>
        @forelse ($rows as $row)
            <tr>
                <td><strong>{{ $row->label }}</strong></td>
                <td style="text-align:right" class="text-sm">{{ $money($row->revenue) }}</td>
                <td style="text-align:right" class="text-sm">{{ $money($row->cost) }}</td>
                <td style="text-align:right" class="text-sm">{{ $money($row->gross) }}</td>
                <td style="text-align:right" class="text-sm">{{ $money($row->expenses) }}</td>

                <td style="text-align:right">
                    <strong style="color:var({{ $row->net < 0 ? '--danger' : '--success' }})">
                        {{ $money($row->net) }}
                    </strong>
                </td>

                <td style="text-align:right" class="text-sm">
                    {{ $money($row->collected) }}
                    @if ($row->revenue > 0 && $row->collected < $row->revenue)
                        {{-- The gap is the credit book, said out loud. --}}
                        <span class="text-xs text-muted" style="display:block">
                            {{ $money($row->revenue - $row->collected) }} on account
                        </span>
                    @endif
                </td>

                <td style="text-align:right" class="text-sm">{{ $money($row->purchases) }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="8">
                    <div class="empty">
                        <x-icon name="wallet" :size="26" />
                        <h3>Nothing to summarise in this period</h3>
                    </div>
                </td>
            </tr>
        @endforelse
    </tbody>

    @if ($rows->isNotEmpty())
        <tfoot>
            <tr>
                <th>Total</th>
                <th style="text-align:right">{{ $money($rows->sum('revenue')) }}</th>
                <th style="text-align:right">{{ $money($rows->sum('cost')) }}</th>
                <th style="text-align:right">{{ $money($rows->sum('gross')) }}</th>
                <th style="text-align:right">{{ $money($rows->sum('expenses')) }}</th>
                <th style="text-align:right">{{ $money($rows->sum('net')) }}</th>
                <th style="text-align:right">{{ $money($rows->sum('collected')) }}</th>
                <th style="text-align:right">{{ $money($rows->sum('purchases')) }}</th>
            </tr>
        </tfoot>
    @endif
</table>
