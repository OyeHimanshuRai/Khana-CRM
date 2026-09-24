{{-- Swappable fragment: the table plus its pagination. --}}

<div class="table-wrap">
    <table class="table table-list">
        <thead>
            <tr>
                <th>Reference</th>
                <th>Date</th>
                @if ($showsShop)<th>Shop</th>@endif
                <th>Warehouse</th>
                <th>Reason</th>
                <th style="text-align:right">Lines</th>
                <th style="text-align:right">In / Out</th>
                <th style="text-align:right">Value</th>
                <th>Status</th>
                <th class="col-action">Actions</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($adjustments as $adjustment)
                @php $value = (float) $adjustment->value_change; @endphp
                <tr>
                    <td>
                        <strong>
                            <a href="{{ route('admin.stock-adjustments.show', $adjustment) }}"
                               class="list-ref">{{ $adjustment->reference }}</a>
                        </strong>
                        <span class="text-xs text-muted" style="display:block">
                            {{ $adjustment->created_by_name ?? '—' }}
                        </span>
                    </td>

                    <td class="text-sm" style="white-space:nowrap">
                        {{ $adjustment->adjustment_date?->format('d M Y') }}
                    </td>

                    @if ($showsShop)
                        <td class="text-sm">{{ $adjustment->shop?->name ?? '—' }}</td>
                    @endif

                    <td class="text-sm">{{ $adjustment->warehouse?->name ?? '—' }}</td>

                    <td class="text-sm">
                        {{ $adjustment->reasonLabel() }}
                        @if ($adjustment->reason)
                            <span class="text-xs text-muted" style="display:block">
                                {{ Str::limit($adjustment->reason, 40) }}
                            </span>
                        @endif
                    </td>

                    <td style="text-align:right" class="text-sm">{{ number_format($adjustment->items_count) }}</td>

                    <td style="text-align:right;white-space:nowrap" class="text-sm">
                        @if ($adjustment->status === App\Models\StockAdjustment::APPROVED)
                            <span style="color:var(--success)">+{{ rtrim(rtrim(number_format((float) $adjustment->total_in, 3), '0'), '.') }}</span>
                            /
                            <span style="color:var(--danger)">−{{ rtrim(rtrim(number_format((float) $adjustment->total_out, 3), '0'), '.') }}</span>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>

                    <td style="text-align:right;white-space:nowrap">
                        @if ($adjustment->status === App\Models\StockAdjustment::APPROVED)
                            <strong style="color:var({{ $value < 0 ? '--danger' : '--success' }})">
                                ₹{{ number_format($value, 2) }}
                            </strong>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>

                    <td>
                        <span class="badge {{ $adjustment->statusTone() ? 'badge-'.$adjustment->statusTone() : '' }}">
                            <span class="badge-dot"></span> {{ $adjustment->statusLabel() }}
                        </span>
                    </td>

                    <td class="col-action">
                        <div class="row-actions">
                            <a class="btn btn-icon" href="{{ route('admin.stock-adjustments.show', $adjustment) }}"
                               aria-label="Open {{ $adjustment->reference }}">
                                <x-icon name="search" :size="15" />
                            </a>

                            @if ($adjustment->isEditable())
                                @allows('inventory.adjustments.edit')
                                    <a class="btn btn-icon"
                                       href="{{ route('admin.stock-adjustments.edit', $adjustment) }}"
                                       aria-label="Edit {{ $adjustment->reference }}">
                                        <x-icon name="edit" :size="15" />
                                    </a>
                                @endallows

                                @allows('inventory.adjustments.delete')
                                    <form method="POST"
                                          action="{{ route('admin.stock-adjustments.destroy', $adjustment) }}"
                                          data-ajax data-refresh-list
                                          onsubmit="return confirm('Delete {{ $adjustment->reference }}? It has not been applied, so no stock changes.')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-icon is-danger"
                                                aria-label="Delete {{ $adjustment->reference }}">
                                            <x-icon name="trash" :size="15" />
                                        </button>
                                    </form>
                                @endallows
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="{{ $showsShop ? 10 : 9 }}">
                        <div class="empty">
                            <x-icon name="inbox" :size="28" />
                            <h3>No adjustments found</h3>
                            <p class="text-sm">Adjust the filters, or raise the first count.</p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<x-pagination :paginator="$adjustments" :per-page="$perPage" :page-sizes="$pageSizes" label="adjustments" />
