{{-- Swappable fragment: the table plus its pagination. --}}

<div class="table-wrap">
    <table class="table table-list">
        <thead>
            <tr>
                <th>Reference</th>
                <th>Received</th>
                @if ($showsShop)<th>Shop</th>@endif
                <th>Supplier</th>
                <th>Bill</th>
                <th style="text-align:right">Total</th>
                <th style="text-align:right">Due</th>
                <th>Status</th>
                <th class="col-action">Actions</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($receipts as $receipt)
                @php $due = (float) $receipt->due_total; @endphp
                <tr @if ($receipt->status === 'cancelled') style="opacity:.6" @endif>
                    <td>
                        <strong>
                            <a href="{{ route('admin.receipts.show', $receipt) }}" class="list-ref">
                                {{ $receipt->reference }}
                            </a>
                        </strong>
                        <span class="text-xs text-muted" style="display:block">
                            {{ $receipt->items_count }} line(s) · {{ $receipt->warehouse?->name }}
                        </span>
                    </td>

                    <td class="text-sm" style="white-space:nowrap">
                        {{ $receipt->received_on?->format('d M Y') }}
                    </td>

                    @if ($showsShop)
                        <td class="text-sm">{{ $receipt->shop?->name ?? '—' }}</td>
                    @endif

                    <td>{{ $receipt->supplier?->displayName() ?? '—' }}</td>

                    <td class="text-sm">
                        {{ $receipt->bill_number ?: '—' }}
                        @if ($receipt->bill_date)
                            <span class="text-xs text-muted" style="display:block">
                                {{ $receipt->bill_date->format('d M Y') }}
                            </span>
                        @endif
                    </td>

                    <td style="text-align:right;white-space:nowrap">
                        <strong>₹{{ number_format((float) $receipt->grand_total, 2) }}</strong>
                    </td>

                    <td style="text-align:right;white-space:nowrap">
                        @if ($due > 0)
                            <strong style="color:var({{ $receipt->isOverdue() ? '--danger' : '--warning' }})">
                                ₹{{ number_format($due, 2) }}
                            </strong>
                            @if ($receipt->due_date)
                                <span class="text-xs text-muted" style="display:block">
                                    due {{ $receipt->due_date->format('d M') }}
                                </span>
                            @endif
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>

                    <td>
                        <span class="badge {{ $receipt->statusTone() ? 'badge-'.$receipt->statusTone() : '' }}">
                            <span class="badge-dot"></span> {{ $receipt->statusLabel() }}
                        </span>
                    </td>

                    <td class="col-action">
                        <div class="row-actions">
                            <a class="btn btn-icon" href="{{ route('admin.receipts.show', $receipt) }}"
                               aria-label="Open {{ $receipt->reference }}">
                                <x-icon name="search" :size="15" />
                            </a>

                            @if ($receipt->isEditable())
                                @allows('purchasing.receipts.edit')
                                    <a class="btn btn-icon" href="{{ route('admin.receipts.edit', $receipt) }}"
                                       aria-label="Edit {{ $receipt->reference }}">
                                        <x-icon name="edit" :size="15" />
                                    </a>
                                @endallows

                                @allows('purchasing.receipts.approve')
                                    <form method="POST" action="{{ route('admin.receipts.post', $receipt) }}"
                                          data-ajax data-refresh-list style="display:inline"
                                          onsubmit="return confirm('Post {{ $receipt->reference }}? The stock goes on the shelf and the supplier is billed.')">
                                        @csrf
                                        @method('PUT')
                                        <button type="submit" class="btn btn-icon"
                                                title="Post this receipt"
                                                aria-label="Post {{ $receipt->reference }}">
                                            <x-icon name="user-check" :size="15" />
                                        </button>
                                    </form>
                                @endallows

                                @allows('purchasing.receipts.delete')
                                    <form method="POST" action="{{ route('admin.receipts.destroy', $receipt) }}"
                                          data-ajax data-refresh-list
                                          onsubmit="return confirm('Delete draft {{ $receipt->reference }}? Nothing has moved yet.')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-icon is-danger"
                                                aria-label="Delete {{ $receipt->reference }}">
                                            <x-icon name="trash" :size="15" />
                                        </button>
                                    </form>
                                @endallows
                            @elseif ($receipt->isPosted())
                                @allows('purchasing.receipts.delete')
                                    <a class="btn btn-icon is-danger"
                                       href="{{ route('admin.receipts.cancel.form', $receipt) }}"
                                       data-modal="{{ route('admin.receipts.cancel.form', $receipt) }}"
                                       data-modal-title="Cancel {{ $receipt->reference }}"
                                       data-modal-sub="Takes the stock back off the shelf"
                                       aria-label="Cancel {{ $receipt->reference }}">
                                        <x-icon name="x" :size="15" />
                                    </a>
                                @endallows
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="{{ $showsShop ? 9 : 8 }}">
                        <div class="empty">
                            <x-icon name="truck" :size="28" />
                            <h3>No goods receipts</h3>
                            <p class="text-sm">
                                Adjust the filters, or receive the first consignment to bring stock in.
                            </p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<x-pagination :paginator="$receipts" :per-page="$perPage" :page-sizes="$pageSizes" label="receipts" />
