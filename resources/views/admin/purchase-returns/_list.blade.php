{{-- Swappable fragment: the table plus its pagination. --}}

<div class="table-wrap">
    <table class="table table-list">
        <thead>
            <tr>
                <th>Reference</th>
                <th>Returned</th>
                @if ($showsShop)<th>Shop</th>@endif
                <th>Receipt</th>
                <th>Supplier</th>
                <th>Reason</th>
                <th style="text-align:right">Value</th>
                <th>Settlement</th>
                <th>Status</th>
                <th class="col-action">Actions</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($returns as $row)
                <tr @if (in_array($row->status, ['rejected', 'cancelled'], true)) style="opacity:.6" @endif>
                    <td>
                        <strong>
                            <a href="{{ route('admin.purchase-returns.show', $row) }}" class="list-ref">
                                {{ $row->reference }}
                            </a>
                        </strong>
                        <span class="text-xs text-muted" style="display:block">
                            {{ $row->items_count }} line(s)
                        </span>
                    </td>

                    <td class="text-sm" style="white-space:nowrap">
                        {{ $row->returned_on?->format('d M Y') }}
                    </td>

                    @if ($showsShop)
                        <td class="text-sm">{{ $row->shop?->name ?? '—' }}</td>
                    @endif

                    <td class="text-sm">
                        @if ($row->goodsReceipt)
                            <a href="{{ route('admin.receipts.show', $row->goodsReceipt) }}" class="list-ref">
                                {{ $row->goodsReceipt->reference }}
                            </a>
                        @else
                            <span class="text-muted">No receipt</span>
                        @endif
                    </td>

                    <td class="text-sm">{{ $row->supplier_name ?: $row->supplier?->displayName() ?: '—' }}</td>

                    <td class="text-sm">{{ $row->reasonLabel() }}</td>

                    <td style="text-align:right;white-space:nowrap">
                        <strong>₹{{ number_format((float) $row->grand_total, 2) }}</strong>
                    </td>

                    <td class="text-sm">
                        {{ $row->settlementLabel() }}
                        @if ((float) $row->refund_amount > 0)
                            <span class="text-xs text-muted" style="display:block">
                                ₹{{ number_format((float) $row->refund_amount, 2) }} received
                            </span>
                        @endif
                    </td>

                    <td>
                        <span class="badge {{ $row->statusTone() ? 'badge-'.$row->statusTone() : '' }}">
                            <span class="badge-dot"></span> {{ $row->statusLabel() }}
                        </span>
                    </td>

                    <td class="col-action">
                        <div class="row-actions">
                            <a class="btn btn-icon" href="{{ route('admin.purchase-returns.show', $row) }}"
                               aria-label="Open {{ $row->reference }}">
                                <x-icon name="search" :size="15" />
                            </a>

                            @if ($row->isEditable())
                                @allows('purchasing.returns.approve')
                                    <form method="POST"
                                          action="{{ route('admin.purchase-returns.approve', $row) }}"
                                          data-ajax data-refresh-list style="display:inline"
                                          onsubmit="return confirm('Accept {{ $row->reference }}? The goods leave the shelf and the supplier is settled.')">
                                        @csrf
                                        @method('PUT')
                                        <button type="submit" class="btn btn-icon"
                                                title="Accept this return"
                                                aria-label="Accept {{ $row->reference }}">
                                            <x-icon name="user-check" :size="15" />
                                        </button>
                                    </form>
                                @endallows

                                @allows('purchasing.returns.reject')
                                    <a class="btn btn-icon is-danger"
                                       href="{{ route('admin.purchase-returns.reject.form', $row) }}"
                                       data-modal="{{ route('admin.purchase-returns.reject.form', $row) }}"
                                       data-modal-title="Refuse {{ $row->reference }}"
                                       data-modal-sub="Nothing moves"
                                       aria-label="Refuse {{ $row->reference }}">
                                        <x-icon name="x" :size="15" />
                                    </a>
                                @endallows

                                @allows('purchasing.returns.delete')
                                    <form method="POST"
                                          action="{{ route('admin.purchase-returns.destroy', $row) }}"
                                          data-ajax data-refresh-list
                                          onsubmit="return confirm('Delete {{ $row->reference }}? Nothing has moved yet.')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-icon is-danger"
                                                aria-label="Delete {{ $row->reference }}">
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
                            <h3>No returns</h3>
                            <p class="text-sm">
                                Adjust the filters, or take one against a receipt.
                            </p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<x-pagination :paginator="$returns" :per-page="$perPage" :page-sizes="$pageSizes" label="returns" />
