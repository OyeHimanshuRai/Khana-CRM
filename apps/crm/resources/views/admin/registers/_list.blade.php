<div class="table-wrap">
    <table class="table table-list">
        <thead>
            <tr>
                <th>Date</th>
                @if ($showsShop)<th>Shop</th>@endif
                <th style="text-align:right">Float</th>
                <th style="text-align:right">Expected</th>
                <th style="text-align:right">Counted</th>
                <th style="text-align:right">Variance</th>
                <th>Status</th>
                <th class="col-action">Actions</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($registers as $row)
                <tr>
                    <td><strong>{{ $row->business_date->format('d M Y') }}</strong></td>

                    @if ($showsShop)
                        <td class="text-sm">{{ $row->shop?->name ?? '—' }}</td>
                    @endif

                    <td style="text-align:right" class="text-sm">₹{{ number_format((float) $row->opening_float, 2) }}</td>

                    <td style="text-align:right" class="text-sm">
                        {{ $row->expected_cash !== null ? '₹'.number_format((float) $row->expected_cash, 2) : '—' }}
                    </td>

                    <td style="text-align:right" class="text-sm">
                        {{ $row->counted_cash !== null ? '₹'.number_format((float) $row->counted_cash, 2) : '—' }}
                    </td>

                    <td style="text-align:right">
                        @if ($row->variance === null)
                            <span class="text-muted">—</span>
                        @elseif ($row->isBalanced())
                            <span class="text-sm" style="color:var(--success)">Balanced</span>
                        @else
                            <strong style="color:{{ $row->isShort() ? 'var(--danger)' : 'var(--warning)' }}">
                                {{ $row->isOver() ? '+' : '−' }}₹{{ number_format(abs((float) $row->variance), 2) }}
                            </strong>
                        @endif
                    </td>

                    <td>
                        <span class="badge {{ $row->statusTone() ? 'badge-'.$row->statusTone() : '' }}">
                            <span class="badge-dot"></span> {{ $row->statusLabel() }}
                        </span>
                    </td>

                    <td class="col-action">
                        <div class="row-actions">
                            <a class="btn btn-icon" href="{{ route('admin.registers.show', $row) }}"
                               aria-label="Open {{ $row->business_date->format('d M Y') }}">
                                <x-icon name="search" :size="15" />
                            </a>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="{{ $showsShop ? 8 : 7 }}">
                        <div class="empty">
                            <x-icon name="wallet" :size="28" />
                            <h3>No registers yet</h3>
                            <p class="text-sm">Open today's register to start reconciling the till.</p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<x-pagination :paginator="$registers" :per-page="$perPage" :page-sizes="$pageSizes" label="registers" />
