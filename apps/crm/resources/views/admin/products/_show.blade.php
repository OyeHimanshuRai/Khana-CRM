{{-- Read-only product detail, rendered straight into the modal body. --}}

@php
    $image = $product->imageUrl();
    $price = $product->sellingPriceFor($shopId);
    $mrp = $product->mrpFor($shopId);
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

            @if ($product->is_sold_out)
                <span class="badge badge-danger">Sold out</span>
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
