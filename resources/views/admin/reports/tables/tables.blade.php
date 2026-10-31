{{--
    Sales by table (§13).

    Dine-in only, and the report says so rather than quietly lumping counter
    sales into a row called "no table" — a takeaway has no table to be busy,
    and including it would make the busiest "table" in the restaurant the till.

    Average bill sits beside the total on purpose. A table that takes the most
    money is often just the biggest one; the average is what says whether it is
    actually earning its floor space.
--}}

@php
    $rows = $data['rows'];
@endphp

<table class="table">
    <thead>
        <tr>
            <th>Table</th>
            <th style="text-align:right">Bills</th>
            <th style="text-align:right">Covers</th>
            <th style="text-align:right">Average bill</th>
            <th style="text-align:right">Total</th>
        </tr>
    </thead>

    <tbody>
        @forelse ($rows as $row)
            <tr>
                <td>
                    <strong>{{ $row->table_name }}</strong>
                    <span class="text-xs text-muted" style="display:block">
                        {{ $row->area }} · {{ $row->seats }} seats
                    </span>
                </td>

                <td style="text-align:right" class="text-sm">{{ number_format($row->bills) }}</td>

                <td style="text-align:right" class="text-sm">
                    {{ (int) $row->covers > 0 ? number_format($row->covers) : '—' }}
                    @if ((int) $row->covers > 0)
                        <span class="text-xs text-muted" style="display:block">
                            {{ number_format($row->total / max(1, (int) $row->covers), 0) }} a head
                        </span>
                    @endif
                </td>

                <td style="text-align:right" class="text-sm">{{ number_format((float) $row->average, 2) }}</td>

                <td style="text-align:right"><strong>{{ number_format((float) $row->total, 2) }}</strong></td>
            </tr>
        @empty
            <tr>
                <td colspan="5">
                    <div class="empty">
                        <x-icon name="grid" :size="28" />
                        <h3>No table bills in this range</h3>
                        <p class="text-sm">
                            Only dine-in bills settled against a table appear here. Counter and
                            takeaway sales are in the sales report.
                        </p>
                    </div>
                </td>
            </tr>
        @endforelse
    </tbody>

    @if ($rows->isNotEmpty())
        <tfoot>
            <tr>
                <th>Total</th>
                <th style="text-align:right">{{ number_format($rows->sum('bills')) }}</th>
                <th style="text-align:right">{{ number_format($rows->sum('covers')) }}</th>
                <th style="text-align:right">—</th>
                <th style="text-align:right">{{ number_format($rows->sum('total'), 2) }}</th>
            </tr>
        </tfoot>
    @endif
</table>
