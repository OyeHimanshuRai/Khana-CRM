{{--
    Kitchen performance, station by station (§9).

    The two waits are kept in separate columns on purpose. "Average time" would
    add them together and hide whichever one is actually wrong:

        wait to accept   nobody picked the ticket up  -> staffing, attention
        time to cook     accepted to on the pass      -> recipe, prep, equipment

    They are different problems and the fix for each is a different person's.
--}}

@php
    $rows = $data['rows'];
    $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 3), '0'), '.');

    /*
     | Minutes and seconds, because a kitchen thinks in both: "4m 12s" is a
     | number a head chef can argue with and "4.2 minutes" is not. The CSV
     | export gives decimal minutes instead - a spreadsheet can average a
     | number and cannot average a string.
     */
    $clock = function ($seconds) {
        if ($seconds === null) {
            return '—';
        }

        $seconds = (int) round((float) $seconds);

        return $seconds < 60
            ? $seconds.'s'
            : intdiv($seconds, 60).'m '.str_pad((string) ($seconds % 60), 2, '0', STR_PAD_LEFT).'s';
    };
@endphp

<table class="table">
    <thead>
        <tr>
            <th>Station</th>
            <th style="text-align:right">Tickets</th>
            <th style="text-align:right">Items</th>
            <th style="text-align:right">Wait to accept</th>
            <th style="text-align:right">Time to cook</th>
            <th style="text-align:right">Slowest</th>
            <th style="text-align:right">Late</th>
        </tr>
    </thead>

    <tbody>
        @forelse ($rows as $row)
            <tr>
                <td>
                    <strong>{{ $row->station }}</strong>

                    {{-- Only worth saying when there is something unfinished:
                         a permanent blank is easier to read than a permanent
                         zero. --}}
                    @if ((int) $row->unfinished > 0)
                        <span class="text-xs text-muted" style="display:block">
                            {{ number_format($row->unfinished) }} never reached the pass
                        </span>
                    @endif
                </td>

                <td style="text-align:right" class="text-sm">{{ number_format($row->tickets) }}</td>

                <td style="text-align:right" class="text-sm">
                    {{ number_format($row->lines_made) }}
                    <span class="text-xs text-muted" style="display:block">
                        {{ $qty($row->quantity) }} portion(s)
                    </span>
                </td>

                <td style="text-align:right" class="text-sm">{{ $clock($row->accept_seconds) }}</td>

                <td style="text-align:right"><strong>{{ $clock($row->cook_seconds) }}</strong></td>

                <td style="text-align:right" class="text-sm">{{ $clock($row->worst_seconds) }}</td>

                <td style="text-align:right" class="text-sm">
                    @if ((int) $row->late > 0)
                        <span style="color:var(--danger)">{{ number_format($row->late) }}</span>
                        <span class="text-xs text-muted" style="display:block">
                            {{ number_format($row->late / max(1, (int) $row->lines_made) * 100, 1) }}%
                        </span>
                    @else
                        —
                    @endif
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="7">
                    <div class="empty">
                        <x-icon name="clock" :size="26" />
                        <h3>The kitchen made nothing in this period</h3>
                        <p class="text-sm">
                            Only dine-in and counter tickets reach the kitchen. A period
                            before the kitchen screen existed has none.
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
                <th style="text-align:right">{{ number_format($rows->sum('tickets')) }}</th>
                <th style="text-align:right">{{ number_format($rows->sum('lines_made')) }}</th>
                {{-- No average of the averages: stations cook different numbers
                     of things, and the mean of four means is not the mean. --}}
                <th style="text-align:right">—</th>
                <th style="text-align:right">—</th>
                <th style="text-align:right">—</th>
                <th style="text-align:right">{{ number_format($rows->sum('late')) }}</th>
            </tr>
        </tfoot>
    @endif
</table>
