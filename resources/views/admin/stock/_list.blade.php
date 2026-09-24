{{-- Swappable fragment: the table plus its pagination. --}}

<div class="table-wrap">
    <table class="table table-list">
        <thead>
            <tr>
                <th>Product</th>
                @if ($showsShop)<th>Shop</th>@endif
                <th>Warehouse</th>
                <th>Batch</th>
                <th>Expiry</th>
                <th style="text-align:right">On hand</th>
                <th style="text-align:right">Reserved</th>
                <th style="text-align:right">Available</th>
                <th style="text-align:right">Value</th>
                <th class="col-action">Ledger</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($stocks as $row)
                @php
                    $product = $row->product;
                    $quantity = (float) $row->quantity;
                    $reorder = $product?->reorder_level !== null ? (float) $product->reorder_level : 0;
                    $tone = $row->batch?->expiryTone();
                @endphp
                <tr>
                    <td>
                        <strong>{{ $product?->name ?? '—' }}</strong>
                        <span class="text-xs text-muted" style="display:block">
                            <span class="list-ref">{{ $product?->sku }}</span>
                            @if ($product?->barcode) · {{ $product->barcode }} @endif
                        </span>
                    </td>

                    @if ($showsShop)
                        <td class="text-sm">{{ $row->shop?->name ?? '—' }}</td>
                    @endif

                    <td class="text-sm">{{ $row->warehouse?->name ?? '—' }}</td>

                    <td class="text-sm">
                        {{ $row->batch?->batch_no ?? '—' }}
                    </td>

                    <td class="text-sm">
                        @if ($row->batch?->expiry_date)
                            <span @if ($tone) class="badge badge-{{ $tone }}" @endif>
                                {{ $row->batch->expiryLabel() }}
                            </span>
                        @else
                            —
                        @endif
                    </td>

                    <td style="text-align:right;white-space:nowrap">
                        @if ($quantity < 0)
                            <strong style="color:var(--danger)">{{ number_format($quantity, 3) }}</strong>
                        @elseif ($reorder > 0 && $quantity <= $reorder)
                            <strong style="color:var(--warning)">{{ number_format($quantity, 3) }}</strong>
                        @else
                            {{ number_format($quantity, 3) }}
                        @endif
                        <span class="text-xs text-muted">{{ $product?->unit?->code }}</span>
                    </td>

                    <td style="text-align:right" class="text-sm">
                        {{ (float) $row->reserved > 0 ? number_format((float) $row->reserved, 3) : '—' }}
                    </td>

                    <td style="text-align:right" class="text-sm">
                        {{ number_format($row->available(), 3) }}
                    </td>

                    <td style="text-align:right;white-space:nowrap">
                        ₹{{ number_format($row->value(), 2) }}
                        <span class="text-xs text-muted" style="display:block">
                            @ ₹{{ number_format((float) $row->average_cost, 2) }}
                        </span>
                    </td>

                    <td class="col-action">
                        @if ($product)
                            <a class="btn btn-icon" href="{{ route('admin.stock.movements', $product) }}"
                               data-modal="{{ route('admin.stock.movements', $product) }}"
                               data-modal-title="{{ $product->name }}"
                               data-modal-sub="Stock ledger"
                               data-modal-size="lg"
                               aria-label="Ledger for {{ $product->name }}">
                                <x-icon name="list" :size="15" />
                            </a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="{{ $showsShop ? 10 : 9 }}">
                        <div class="empty">
                            <x-icon name="inbox" :size="28" />
                            <h3>Nothing in stock here</h3>
                            <p class="text-sm">
                                Adjust the filters, or receive goods against a purchase to bring stock in.
                            </p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<x-pagination :paginator="$stocks" :per-page="$perPage" :page-sizes="$pageSizes" label="stock lines" />
