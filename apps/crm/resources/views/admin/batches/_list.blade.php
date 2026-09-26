{{-- Swappable fragment: the table plus its pagination. --}}

<div class="table-wrap">
    <table class="table table-list">
        <thead>
            <tr>
                <th>Batch</th>
                <th>Product</th>
                @if ($showsShop)<th>Shop</th>@endif
                <th>Manufactured</th>
                <th>Expiry</th>
                <th style="text-align:right">On hand</th>
                <th>Sellable</th>
                <th class="col-action">Actions</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($batches as $batch)
                @php
                    $tone = $batch->expiryTone();
                    $days = $batch->daysToExpiry();
                    $onHand = (float) ($batch->on_hand ?? 0);
                @endphp
                <tr>
                    <td>
                        <strong>
                            <a href="{{ route('admin.batches.show', $batch) }}"
                               data-modal="{{ route('admin.batches.show', $batch) }}"
                               data-modal-title="Batch {{ $batch->batch_no }}"
                               data-modal-sub="{{ $batch->product?->name }}"
                               class="list-ref">{{ $batch->batch_no }}</a>
                        </strong>
                        @if ($batch->supplier_batch_ref)
                            <span class="text-xs text-muted" style="display:block">
                                supplier ref {{ $batch->supplier_batch_ref }}
                            </span>
                        @endif
                    </td>

                    <td>
                        {{ $batch->product?->name ?? '—' }}
                        <span class="text-xs text-muted" style="display:block">{{ $batch->product?->sku }}</span>
                    </td>

                    @if ($showsShop)
                        <td class="text-sm">{{ $batch->shop?->name ?? '—' }}</td>
                    @endif

                    <td class="text-sm">{{ $batch->mfg_date?->format('d M Y') ?? '—' }}</td>

                    <td class="text-sm" style="white-space:nowrap">
                        @if ($batch->expiry_date)
                            <span @if ($tone) class="badge badge-{{ $tone }}" @endif>
                                {{ $batch->expiryLabel() }}
                            </span>
                            <span class="text-xs text-muted" style="display:block">
                                @if ($days < 0)
                                    {{ abs($days) }} days ago
                                @elseif ($days === 0)
                                    today
                                @else
                                    in {{ $days }} days
                                @endif
                            </span>
                        @else
                            —
                        @endif
                    </td>

                    <td style="text-align:right;white-space:nowrap">
                        @if ($onHand > 0)
                            <strong>{{ number_format($onHand, 3) }}</strong>
                            <span class="text-xs text-muted">{{ $batch->product?->unit?->code }}</span>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>

                    <td>
                        @allows('inventory.batches.edit')
                            <form method="POST" action="{{ route('admin.batches.status', $batch) }}"
                                  data-ajax data-refresh-list style="display:inline">
                                @csrf
                                @method('PUT')
                                <button type="submit" class="status-toggle {{ $batch->is_active ? 'is-on' : '' }}"
                                        title="{{ $batch->is_active ? 'Block this lot from sale' : 'Release this lot for sale' }}">
                                    <span class="badge-dot"></span>
                                    {{ $batch->is_active ? 'Sellable' : 'Blocked' }}
                                </button>
                            </form>
                        @else
                            <span class="badge {{ $batch->is_active ? 'badge-success' : 'badge-danger' }}">
                                <span class="badge-dot"></span>
                                {{ $batch->is_active ? 'Sellable' : 'Blocked' }}
                            </span>
                        @endallows
                    </td>

                    <td class="col-action">
                        <div class="row-actions">
                            <a class="btn btn-icon" href="{{ route('admin.batches.show', $batch) }}"
                               data-modal="{{ route('admin.batches.show', $batch) }}"
                               data-modal-title="Batch {{ $batch->batch_no }}"
                               data-modal-sub="{{ $batch->product?->name }}"
                               aria-label="View batch {{ $batch->batch_no }}">
                                <x-icon name="search" :size="15" />
                            </a>

                            @allows('inventory.batches.edit')
                                <a class="btn btn-icon" href="{{ route('admin.batches.edit', $batch) }}"
                                   data-modal="{{ route('admin.batches.edit', $batch) }}"
                                   data-modal-title="Edit Batch"
                                   data-modal-sub="{{ $batch->batch_no }}"
                                   aria-label="Edit batch {{ $batch->batch_no }}">
                                    <x-icon name="edit" :size="15" />
                                </a>
                            @endallows

                            @allows('inventory.batches.delete')
                                <form method="POST" action="{{ route('admin.batches.destroy', $batch) }}"
                                      data-ajax data-refresh-list
                                      onsubmit="return confirm('Delete batch “{{ $batch->batch_no }}”? This cannot be undone.')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-icon is-danger"
                                            aria-label="Delete batch {{ $batch->batch_no }}">
                                        <x-icon name="trash" :size="15" />
                                    </button>
                                </form>
                            @endallows
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="{{ $showsShop ? 8 : 7 }}">
                        <div class="empty">
                            <x-icon name="inbox" :size="28" />
                            <h3>No batches found</h3>
                            <p class="text-sm">
                                Adjust the filters, or receive goods for a batch-tracked product.
                            </p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<x-pagination :paginator="$batches" :per-page="$perPage" :page-sizes="$pageSizes" label="batches" />
