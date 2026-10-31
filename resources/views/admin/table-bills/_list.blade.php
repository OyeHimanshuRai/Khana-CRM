{{--
    The tables the counter can act on.

    No search and no pagination on purpose: a restaurant has as many rows here
    as it has occupied tables, and a filter bar over eleven rows is furniture.
--}}

@php
    $money = fn ($v) => '₹'.number_format((float) $v, 2);
@endphp

<div class="table-wrap">
    <table class="table table-list">
        <thead>
            <tr>
                <th>Table</th>
                <th>Party</th>
                <th>Seated</th>
                <th>Rounds</th>
                <th style="text-align:right">On the table</th>
                <th style="text-align:right">Billed</th>
                <th class="col-action">Actions</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($sessions as $session)
                {{-- A name of its own: assigning back into $totals would
                     empty the map for every later row. --}}
                @php $t = $totals[$session->id] ?? []; @endphp
                <tr>
                    <td>
                        <strong>{{ $session->table?->code ?? '—' }}</strong>
                        <span class="text-xs text-muted" style="display:block">
                            {{ $session->table?->floor?->name }}
                            @if ($session->table?->name) · Table {{ $session->table->name }} @endif
                        </span>
                    </td>

                    <td>
                        {{ $session->partyName() }}
                        @if ($session->covers)
                            <span class="text-xs text-muted" style="display:block">
                                {{ $session->covers }} cover(s)
                            </span>
                        @endif
                    </td>

                    <td class="text-sm">
                        {{ $session->seatedMinutes() }}m
                        <span class="badge {{ $session->statusTone() ? 'badge-'.$session->statusTone() : '' }}">
                            <span class="badge-dot"></span> {{ $session->statusLabel() }}
                        </span>
                    </td>

                    <td class="text-sm">{{ number_format($session->orders_count) }}</td>

                    <td style="text-align:right">
                        <strong>{{ $money($t['unbilled'] ?? 0) }}</strong>

                        {{-- Only said when it is true, because it is the one
                             thing that makes "bill this table" premature. --}}
                        @if (($t['in_kitchen'] ?? 0) > 0)
                            <span class="text-xs" style="display:block; color:var(--warning)">
                                {{ rtrim(rtrim(number_format($t['in_kitchen'], 2), '0'), '.') }}
                                still in the kitchen
                            </span>
                        @endif
                    </td>

                    <td style="text-align:right" class="text-sm">
                        @if (($t['billed'] ?? 0) > 0)
                            {{ $money($t['billed']) }}
                            @if (($t['due'] ?? 0) > 0)
                                <span class="text-xs" style="display:block; color:var(--danger)">
                                    {{ $money($t['due']) }} unpaid
                                </span>
                            @endif
                        @else
                            —
                        @endif
                    </td>

                    <td class="col-action">
                        <a class="btn btn-sm btn-primary" href="{{ route('admin.table-bills.show', $session) }}">
                            Open bill
                        </a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">
                        <div class="empty">
                            <x-icon name="wallet" :size="28" />
                            <h3>Nobody is sitting down</h3>
                            <p class="text-sm">
                                A table appears here when somebody scans its QR code
                                or a captain seats a party on the floor plan.
                            </p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
