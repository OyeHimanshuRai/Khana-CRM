{{--
    Day closing / cash reconciliation (SRS 13).

    Expected against counted, one row a day. A till still open is shown as
    such rather than skipped: the days nobody closed are the ones worth
    finding, and a report that quietly omitted them would read as a clean
    month.
--}}

@php
    $rows = $data['rows'];
    $money = fn ($v) => '₹'.number_format((float) $v, 2);
@endphp

<table class="table">
    <thead>
        <tr>
            <th>Business date</th>
            @if ($showsShop)<th>Branch</th>@endif
            <th style="text-align:right">Opening float</th>
            <th style="text-align:right">Expected</th>
            <th style="text-align:right">Counted</th>
            <th style="text-align:right">Over / short</th>
            <th>Closed by</th>
            <th>Status</th>
        </tr>
    </thead>

    <tbody>
        @forelse ($rows as $row)
            @php $variance = $row->variance === null ? null : (float) $row->variance; @endphp

            <tr>
                <td>
                    <strong>
                        @allows('pos.registers.view')
                            <a href="{{ route('admin.registers.show', $row) }}">
                                {{ $row->business_date?->format('d M Y') }}
                            </a>
                        @else
                            {{ $row->business_date?->format('d M Y') }}
                        @endallows
                    </strong>
                    @if ($row->opened_by_name)
                        <span class="text-xs text-muted" style="display:block">
                            opened by {{ $row->opened_by_name }}
                        </span>
                    @endif
                </td>

                @if ($showsShop)
                    <td class="text-sm">{{ $row->shop?->name ?? '—' }}</td>
                @endif

                <td style="text-align:right" class="text-sm">{{ $money($row->opening_float) }}</td>
                <td style="text-align:right" class="text-sm">
                    {{ $row->expected_cash === null ? '—' : $money($row->expected_cash) }}
                </td>
                <td style="text-align:right" class="text-sm">
                    {{ $row->counted_cash === null ? '—' : $money($row->counted_cash) }}
                </td>

                <td style="text-align:right">
                    @if ($variance === null)
                        <span class="text-muted">—</span>
                    @else
                        <strong style="color:var({{ abs($variance) < 0.005 ? '--success' : ($variance > 0 ? '--warning' : '--danger') }})">
                            {{ $variance > 0 ? '+' : '' }}{{ $money($variance) }}
                        </strong>
                    @endif
                </td>

                <td class="text-sm">
                    {{ $row->closed_by_name ?: '—' }}
                    @if ($row->closed_at)
                        <span class="text-xs text-muted" style="display:block">
                            {{ $row->closed_at->format('d M, H:i') }}
                        </span>
                    @endif
                </td>

                <td>
                    <span class="badge badge-{{ $row->statusTone() ?: 'info' }}">{{ $row->statusLabel() }}</span>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="{{ $showsShop ? 8 : 7 }}">
                    <div class="empty">
                        <x-icon name="wallet" :size="26" />
                        <h3>No tills opened in this period</h3>
                    </div>
                </td>
            </tr>
        @endforelse
    </tbody>

    @if ($rows->isNotEmpty())
        <tfoot>
            <tr>
                <th colspan="{{ $showsShop ? 3 : 2 }}">Total</th>
                <th style="text-align:right">{{ $money($rows->sum('expected_cash')) }}</th>
                <th style="text-align:right">{{ $money($rows->sum('counted_cash')) }}</th>
                {{-- Netted here only because the tiles above keep over and
                     short apart, which is where the honest figure lives. --}}
                <th style="text-align:right">{{ $money($rows->sum('variance')) }}</th>
                <th colspan="2"></th>
            </tr>
        </tfoot>
    @endif
</table>
