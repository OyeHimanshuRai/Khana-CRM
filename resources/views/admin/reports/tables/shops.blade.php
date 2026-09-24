{{--
    Branch against branch.

    Only useful in the consolidated view - inside one shop it is a single
    row, so the screen says as much rather than pretending to compare.
--}}

@php
    $rows = $data['rows'];
    $money = fn ($v) => '₹'.number_format((float) $v, 2);
@endphp

@unless ($showsShop)
    <p class="text-sm text-muted" style="padding:12px 16px 0;margin:0">
        You are working in a single shop, so this is one row. Switch to
        <strong>All shops</strong> in the header to compare branches.
    </p>
@endunless

<table class="table">
    <thead>
        <tr>
            <th>Shop</th>
            <th style="text-align:right">Invoices</th>
            <th style="text-align:right">Sales</th>
            <th style="text-align:right">Share</th>
            @allows('reports.profit_report.view')
                <th style="text-align:right">Gross profit</th>
            @endallows
        </tr>
    </thead>

    <tbody>
        @php $total = (float) $rows->sum('total'); @endphp

        @forelse ($rows as $row)
            <tr>
                <td><strong>{{ $row->shop }}</strong></td>
                <td style="text-align:right" class="text-sm">{{ number_format($row->invoices) }}</td>
                <td style="text-align:right"><strong>{{ $money($row->total) }}</strong></td>
                <td style="text-align:right" class="text-sm">
                    {{ $total > 0 ? number_format((float) $row->total / $total * 100, 1).'%' : '—' }}
                </td>
                @allows('reports.profit_report.view')
                    <td style="text-align:right" class="text-sm">{{ $money($row->profit) }}</td>
                @endallows
            </tr>
        @empty
            <tr>
                <td colspan="5">
                    <div class="empty">
                        <x-icon name="building" :size="26" />
                        <h3>No sales in this period</h3>
                    </div>
                </td>
            </tr>
        @endforelse
    </tbody>
</table>
