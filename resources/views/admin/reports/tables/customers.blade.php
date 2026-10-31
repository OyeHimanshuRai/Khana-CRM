{{--
    Customer history (§13).

    Walk-ins are excluded rather than grouped into one enormous row. This
    report is for the people the restaurant can recognise again; a line reading
    "Walk-in: 4,812 visits" is a number nobody can do anything with, and it
    would sit at the top of every sort.
--}}

@php
    $rows = $data['rows'];
@endphp

<table class="table">
    <thead>
        <tr>
            <th>Customer</th>
            <th style="text-align:right">Visits</th>
            <th style="text-align:right">Average bill</th>
            <th style="text-align:right">Total</th>
            <th>Last seen</th>
        </tr>
    </thead>

    <tbody>
        @forelse ($rows as $row)
            @php $last = \Illuminate\Support\Carbon::parse($row->last_visit); @endphp
            <tr>
                <td>
                    <strong>{{ $row->customer }}</strong>
                    @if ($row->mobile)
                        <span class="text-xs text-muted" style="display:block">{{ $row->mobile }}</span>
                    @endif
                </td>

                <td style="text-align:right" class="text-sm">{{ number_format($row->visits) }}</td>

                <td style="text-align:right" class="text-sm">{{ number_format((float) $row->average, 2) }}</td>

                <td style="text-align:right"><strong>{{ number_format((float) $row->total, 2) }}</strong></td>

                <td class="text-sm">
                    {{ $last->format('j M Y') }}
                    <span class="text-xs {{ $last->diffInDays(now()) > 60 ? 'text-danger' : 'text-muted' }}"
                          style="display:block">
                        {{-- The column a restaurant actually acts on: a regular
                             who has not been in for two months is a phone call,
                             not a statistic. --}}
                        {{ $last->diffForHumans() }}
                    </span>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="5">
                    <div class="empty">
                        <x-icon name="users" :size="28" />
                        <h3>No named customers in this range</h3>
                        <p class="text-sm">
                            Only bills settled against a customer record appear here. Walk-ins are in
                            the sales report.
                        </p>
                    </div>
                </td>
            </tr>
        @endforelse
    </tbody>

    @if ($rows->isNotEmpty())
        <tfoot>
            <tr>
                <th>{{ number_format($rows->count()) }} customers</th>
                <th style="text-align:right">{{ number_format($rows->sum('visits')) }}</th>
                <th style="text-align:right">—</th>
                <th style="text-align:right">{{ number_format($rows->sum('total'), 2) }}</th>
                <th></th>
            </tr>
        </tfoot>
    @endif
</table>
