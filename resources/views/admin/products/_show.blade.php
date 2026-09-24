{{-- Read-only product detail, rendered straight into the modal body. --}}

@php
    $image = $product->imageUrl();
    $price = $product->sellingPriceFor($shopId);
    $mrp = $product->mrpFor($shopId);
    $reorder = $product->reorderLevelFor($shopId);
    // A dish is cooked when it is ordered: no shelf, no count, no low-stock
    // state to be in. See Product::tracksStock().
    $tracksStock = $product->tracksStock();
    $isLow = $tracksStock && $reorder > 0 && $onHand <= $reorder;
@endphp

<div class="cat-view">
    <div class="cat-view-image">
        @if ($image)
            <img src="{{ $image }}" alt="{{ $product->name }}">
        @else
            <span class="text-xs text-muted">{{ $product->initials() }}</span>
        @endif
    </div>

    <div class="cat-view-body">
        <div class="sec-name">{{ $product->name }}</div>
        <div class="text-sm text-muted">
            <span class="list-ref">{{ $product->sku }}</span>
            @if ($product->barcode) · {{ $product->barcode }} @endif
        </div>

        <div style="display:flex;flex-wrap:wrap;gap:7px;margin:8px 0 14px">
            <span class="badge {{ $product->is_active ? 'badge-success' : 'badge-danger' }}">
                <span class="badge-dot"></span> {{ $product->is_active ? 'Active' : 'Inactive' }}
            </span>

            <span class="badge {{ $product->is_published ? 'badge-info' : '' }}">
                {{ $product->is_published ? 'In online store' : 'Not listed online' }}
            </span>

            @if ($product->track_batches)
                <span class="badge badge-brand">Batch tracked</span>
            @endif

            @if (! $tracksStock)
                <span class="badge badge-brand">Made to order</span>
            @elseif ($onHand <= 0)
                <span class="badge badge-danger">Out of stock</span>
            @elseif ($isLow)
                <span class="badge badge-warning">At reorder level</span>
            @endif
        </div>

        <dl class="sec-facts">
            <div><dt>Selling price</dt><dd><strong>₹{{ number_format($price, 2) }}</strong></dd></div>
            <div><dt>MRP</dt><dd>₹{{ number_format($mrp, 2) }}</dd></div>
            <div><dt>Purchase price</dt><dd>₹{{ number_format($product->purchasePriceFor($shopId), 2) }}</dd></div>
            <div>
                <dt>Tax</dt>
                <dd>
                    {{ $product->taxRate?->label() ?? 'None' }}
                    <span class="text-xs text-muted" style="display:block">
                        {{ $product->tax_inclusive ? 'price includes tax' : 'tax added at billing' }}
                    </span>
                </dd>
            </div>
            <div><dt>HSN</dt><dd>{{ $product->hsn_code ?: '—' }}</dd></div>
            <div><dt>Unit</dt><dd>{{ $product->unit?->name ?? '—' }} ({{ $product->unit?->code }})</dd></div>
            <div><dt>Category</dt><dd>{{ $product->category?->name ?? '—' }}</dd></div>
            <div><dt>Brand</dt><dd>{{ $product->brand?->name ?? '—' }}</dd></div>
            @if ($tracksStock)
                <div>
                    <dt>On hand{{ $shopId === null ? ' (all shops)' : '' }}</dt>
                    <dd>{{ $product->unit?->format($onHand) ?? number_format($onHand, 3) }}</dd>
                </div>
                <div>
                    <dt>Available</dt>
                    <dd>
                        {{ $product->unit?->format($available) ?? number_format($available, 3) }}
                        @if ($available < $onHand)
                            <span class="text-xs text-muted" style="display:block">rest is reserved for orders</span>
                        @endif
                    </dd>
                </div>
                <div><dt>Reorder level</dt><dd>{{ $reorder > 0 ? number_format($reorder, 3) : '—' }}</dd></div>
            @else
                <div>
                    <dt>Stock</dt>
                    <dd>
                        Made to order
                        <span class="text-xs text-muted" style="display:block">
                            Its ingredients come off the shelf through the recipe when
                            the kitchen cooks it, not when the bill is raised.
                        </span>
                    </dd>
                </div>
            @endif
            <div><dt>Manufacturer</dt><dd>{{ $product->manufacturer ?: '—' }}</dd></div>
        </dl>

        @if ($product->food_type || $product->spiceLabel() || $product->serves || $product->prep_minutes)
            <div style="margin-top:16px">
                <div class="form-label">Menu detail</div>
                <dl class="sec-facts">
                    @if ($product->food_type)
                        <div>
                            <dt>Food type</dt>
                            <dd>
                                {{-- The mark, and the words. Colour alone is
                                     not a label anybody can rely on. --}}
                                <span style="display:inline-block;width:9px;height:9px;border-radius:2px;
                                             background:{{ $product->foodTypeDot() }};margin-right:5px"
                                      aria-hidden="true"></span>
                                {{ $product->foodTypeLabel() }}
                            </dd>
                        </div>
                    @endif
                    @if ($product->spiceLabel())
                        <div><dt>Spice</dt><dd>{{ $product->spiceLabel() }}</dd></div>
                    @endif
                    @if ($product->serves)
                        <div><dt>Serves</dt><dd>{{ $product->serves }}</dd></div>
                    @endif
                    @if ($product->prep_minutes)
                        <div><dt>Kitchen time</dt><dd>{{ $product->prep_minutes }} min</dd></div>
                    @endif
                </dl>
            </div>
        @endif

        @if (filled($product->food_tags))
            <div style="margin-top:14px">
                <div class="form-label">Tags</div>
                <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:4px">
                    @foreach ($product->food_tags as $tag)
                        <span class="badge">{{ $tag }}</span>
                    @endforeach
                </div>
            </div>
        @endif

        @if ($reason = $product->unavailableReason())
            <div style="margin-top:14px">
                <div class="form-label">Not being served</div>
                <p class="text-sm text-muted">{{ $reason }}</p>
            </div>
        @elseif ($product->servingWindowLabel())
            <div style="margin-top:14px">
                <div class="form-label">Availability</div>
                <p class="text-sm text-muted">{{ $product->servingWindowLabel() }}</p>
            </div>
        @endif
    </div>
</div>

@if ($stockRows->isNotEmpty())
    <div style="margin-top:18px">
        <div class="form-label">Where this stock is</div>

        <div class="table-wrap" style="margin-top:6px">
            <table class="table">
                <thead>
                    <tr>
                        <th>Warehouse</th>
                        <th>Batch</th>
                        <th>Expiry</th>
                        <th style="text-align:right">On hand</th>
                        <th style="text-align:right">Reserved</th>
                        <th style="text-align:right">Avg cost</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($stockRows as $row)
                        <tr>
                            <td>{{ $row->warehouse?->name ?? '—' }}</td>
                            <td>{{ $row->batch?->batch_no ?? '—' }}</td>
                            <td>
                                @if ($row->batch?->expiry_date)
                                    @php $tone = $row->batch->expiryTone(); @endphp
                                    <span @if ($tone) class="badge badge-{{ $tone }}" @endif>
                                        {{ $row->batch->expiryLabel() }}
                                    </span>
                                @else
                                    —
                                @endif
                            </td>
                            <td style="text-align:right">{{ number_format((float) $row->quantity, 3) }}</td>
                            <td style="text-align:right">{{ number_format((float) $row->reserved, 3) }}</td>
                            <td style="text-align:right">₹{{ number_format((float) $row->average_cost, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif

<div class="modal-actions">
    <button type="button" class="btn" data-modal-close>Close</button>

    @allows('inventory.products.edit')
        <a class="btn btn-primary" href="{{ route('admin.products.edit', $product) }}"
           data-modal="{{ route('admin.products.edit', $product) }}"
           data-modal-title="Edit Product"
           data-modal-sub="{{ $product->name }}"
           data-modal-size="lg">
            <x-icon name="edit" :size="15" /> Edit
        </a>
    @endallows
</div>
