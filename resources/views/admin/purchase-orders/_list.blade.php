{{-- Swappable fragment: the table plus its pagination. --}}

<div class="table-wrap">
    <table class="table table-list">
        <thead>
            <tr>
                <th>Reference</th>
                <th>Ordered</th>
                @if ($showsShop)<th>Shop</th>@endif
                <th>Supplier</th>
                <th>Expected</th>
                <th style="text-align:right">Lines</th>
                <th style="text-align:right">Value</th>
                <th>Status</th>
                <th class="col-action">Actions</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($orders as $order)
                <tr @if ($order->status === 'cancelled') style="opacity:.6" @endif>
                    <td>
                        <strong>
                            <a href="{{ route('admin.purchase-orders.show', $order) }}" class="list-ref">
                                {{ $order->reference }}
                            </a>
                        </strong>
                        <span class="text-xs text-muted" style="display:block">
                            {{ $order->created_by_name ?? '—' }}
                        </span>
                    </td>

                    <td class="text-sm" style="white-space:nowrap">
                        {{ $order->ordered_on?->format('d M Y') }}
                    </td>

                    @if ($showsShop)
                        <td class="text-sm">{{ $order->shop?->name ?? '—' }}</td>
                    @endif

                    <td>{{ $order->supplier?->displayName() ?? '—' }}</td>

                    <td class="text-sm">
                        {{ $order->expected_on?->format('d M Y') ?? '—' }}
                        @if ($order->expected_on && $order->isReceivable() && $order->expected_on->isBefore(today()))
                            <span class="badge badge-warning" style="margin-left:4px">late</span>
                        @endif
                    </td>

                    <td style="text-align:right" class="text-sm">{{ number_format($order->items_count) }}</td>

                    <td style="text-align:right;white-space:nowrap">
                        <strong>₹{{ number_format((float) $order->grand_total, 2) }}</strong>
                    </td>

                    <td>
                        <span class="badge {{ $order->statusTone() ? 'badge-'.$order->statusTone() : '' }}">
                            <span class="badge-dot"></span> {{ $order->statusLabel() }}
                        </span>
                    </td>

                    <td class="col-action">
                        <div class="row-actions">
                            <a class="btn btn-icon" href="{{ route('admin.purchase-orders.show', $order) }}"
                               aria-label="Open {{ $order->reference }}">
                                <x-icon name="search" :size="15" />
                            </a>

                            @if ($order->isReceivable())
                                @allows('purchasing.receipts.create')
                                    <a class="btn btn-icon"
                                       href="{{ route('admin.receipts.create', ['order' => $order->id]) }}"
                                       title="Receive against this order"
                                       aria-label="Receive against {{ $order->reference }}">
                                        <x-icon name="truck" :size="15" />
                                    </a>
                                @endallows
                            @endif

                            @if ($order->isEditable())
                                @allows('purchasing.purchase_orders.edit')
                                    <a class="btn btn-icon"
                                       href="{{ route('admin.purchase-orders.edit', $order) }}"
                                       aria-label="Edit {{ $order->reference }}">
                                        <x-icon name="edit" :size="15" />
                                    </a>
                                @endallows

                                @allows('purchasing.purchase_orders.delete')
                                    <form method="POST"
                                          action="{{ route('admin.purchase-orders.destroy', $order) }}"
                                          data-ajax data-refresh-list
                                          onsubmit="return confirm('Delete {{ $order->reference }}?')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-icon is-danger"
                                                aria-label="Delete {{ $order->reference }}">
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
                    <td colspan="{{ $showsShop ? 9 : 8 }}">
                        <div class="empty">
                            <x-icon name="file" :size="28" />
                            <h3>No purchase orders</h3>
                            <p class="text-sm">
                                Adjust the filters, or raise one. Orders are optional — goods can also
                                be received directly.
                            </p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<x-pagination :paginator="$orders" :per-page="$perPage" :page-sizes="$pageSizes" label="orders" />
