{{-- Swappable fragment: one row per shop, with its figures for the chosen period. --}}
<div class="table-wrap">
    <table class="table table-list">
        <thead>
            <tr>
                <th>Shop</th>
                <th>Company</th>
                <th>Status</th>
                <th class="pf-num">Orders</th>
                <th class="pf-num">Sales</th>
                <th class="pf-num">Expenses</th>
                <th class="pf-num">Profit</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($shops as $shop)
                @php
                    $line = $figures->get($shop->id, ['orders' => 0, 'sales' => 0, 'expenses' => 0, 'profit' => 0]);
                    $suspended = (bool) $shop->tenant?->isSuspended();
                @endphp
                <tr>
                    <td>
                        <strong><a href="{{ route('admin.platform.shops.show', $shop->id) }}">{{ $shop->name }}</a></strong>
                        <span class="text-xs text-muted" style="display:block">{{ $shop->code }}</span>
                    </td>
                    <td class="text-sm">{{ $shop->tenant?->name ?? '—' }}</td>
                    <td>
                        @if ($suspended)
                            <span class="badge badge-danger"><span class="badge-dot"></span> Suspended</span>
                        @elseif (! $shop->is_active)
                            <span class="badge badge-warning"><span class="badge-dot"></span> Inactive</span>
                        @else
                            <span class="badge badge-success"><span class="badge-dot"></span> Active</span>
                        @endif
                    </td>
                    <td class="pf-num">{{ number_format($line['orders']) }}</td>
                    <td class="pf-num">₹{{ number_format($line['sales'], 0) }}</td>
                    <td class="pf-num">₹{{ number_format($line['expenses'], 0) }}</td>
                    <td class="pf-num">₹{{ number_format($line['profit'], 0) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">
                        <div class="empty">
                            <div class="empty-title">No shops match that</div>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<x-pagination :paginator="$shops" :per-page="$perPage" :page-sizes="$pageSizes" />
