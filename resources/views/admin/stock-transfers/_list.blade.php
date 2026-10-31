{{-- Swappable fragment: the table plus its pagination. --}}

<div class="table-wrap">
    <table class="table table-list">
        <thead>
            <tr>
                <th>Reference</th>
                <th>Date</th>
                <th>From</th>
                <th>To</th>
                <th style="text-align:right">Lines</th>
                <th style="text-align:right">Quantity</th>
                <th>Status</th>
                <th class="col-action">Actions</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($transfers as $transfer)
                <tr>
                    <td>
                        <strong>
                            <a href="{{ route('admin.stock-transfers.show', $transfer) }}"
                               class="list-ref">{{ $transfer->reference }}</a>
                        </strong>
                        <span class="text-xs text-muted" style="display:block">
                            {{ $transfer->created_by_name ?? '—' }}
                        </span>
                    </td>

                    <td class="text-sm" style="white-space:nowrap">
                        {{ $transfer->transfer_date?->format('d M Y') }}
                    </td>

                    <td class="text-sm">
                        {{ $transfer->fromWarehouse?->name ?? '—' }}
                        <span class="text-xs text-muted" style="display:block">{{ $transfer->shop?->name }}</span>
                    </td>

                    <td class="text-sm">
                        {{ $transfer->toWarehouse?->name ?? '—' }}
                        <span class="text-xs text-muted" style="display:block">
                            {{ $transfer->toShop?->name }}
                            @if ($transfer->isInterShop())
                                <span class="badge badge-info" style="margin-left:4px">inter-shop</span>
                            @endif
                        </span>
                    </td>

                    <td style="text-align:right" class="text-sm">{{ number_format($transfer->items_count) }}</td>

                    <td style="text-align:right" class="text-sm">
                        {{ rtrim(rtrim(number_format((float) $transfer->total_quantity, 3), '0'), '.') }}
                    </td>

                    <td>
                        <span class="badge {{ $transfer->statusTone() ? 'badge-'.$transfer->statusTone() : '' }}">
                            <span class="badge-dot"></span> {{ $transfer->statusLabel() }}
                        </span>
                    </td>

                    <td class="col-action">
                        <div class="row-actions">
                            <a class="btn btn-icon" href="{{ route('admin.stock-transfers.show', $transfer) }}"
                               aria-label="Open {{ $transfer->reference }}">
                                <x-icon name="search" :size="15" />
                            </a>

                            @if ($transfer->isEditable() && $direction === 'outgoing')
                                @allows('inventory.transfers.edit')
                                    <a class="btn btn-icon"
                                       href="{{ route('admin.stock-transfers.edit', $transfer) }}"
                                       aria-label="Edit {{ $transfer->reference }}">
                                        <x-icon name="edit" :size="15" />
                                    </a>
                                @endallows

                                @allows('inventory.transfers.delete')
                                    <form method="POST"
                                          action="{{ route('admin.stock-transfers.destroy', $transfer) }}"
                                          data-ajax data-refresh-list
                                          onsubmit="return confirm('Delete {{ $transfer->reference }}? Nothing has moved yet.')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-icon is-danger"
                                                aria-label="Delete {{ $transfer->reference }}">
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
                    <td colspan="8">
                        <div class="empty">
                            <x-icon name="inbox" :size="28" />
                            <h3>
                                {{ $direction === 'incoming' ? 'Nothing on its way here' : 'No transfers found' }}
                            </h3>
                            <p class="text-sm">
                                {{ $direction === 'incoming'
                                    ? 'Consignments other shops send you will appear here.'
                                    : 'Adjust the filters, or raise the first transfer.' }}
                            </p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<x-pagination :paginator="$transfers" :per-page="$perPage" :page-sizes="$pageSizes" label="transfers" />
