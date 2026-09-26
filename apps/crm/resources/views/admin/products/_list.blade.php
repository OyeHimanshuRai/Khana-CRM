{{--
    Swappable fragment: the table plus its pagination.

    Price and stock are read for the shop in context, not from the master —
    which is the point of a global catalogue with per-shop shelves. In
    All-shops mode the stock column is the total across the reader's shops,
    and the header says so.
--}}

<div class="table-wrap">
    <table class="table table-list">
        <thead>
            <tr>
                <th style="width:1%">Image</th>
                <th>Product</th>
                <th>Category</th>
                <th style="text-align:right">Price</th>
                <th style="text-align:right">
                    Stock
                    @if ($shopId === null)
                        <span class="text-xs text-muted" style="display:block;font-weight:400">all shops</span>
                    @endif
                </th>
                <th>Store</th>
                <th>Status</th>
                <th class="col-action">Actions</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($products as $product)
                @php
                    $image = $product->imageUrl();
                    $onHand = (float) ($product->on_hand ?? 0);
                    $reorder = $product->reorderLevelFor($shopId);
                    /*
                     | A dish is made when it is ordered, so it has no shelf
                     | to count and nothing comes off one when it sells. The
                     | column says so rather than showing a number that the
                     | next sale will leave exactly where it is - see
                     | Product::tracksStock().
                     */
                    $tracksStock = $product->tracksStock();
                    $isLow = $tracksStock && $reorder > 0 && $onHand <= $reorder;
                @endphp
                <tr>
                    <td>
                        <span class="cat-thumb">
                            @if ($image)
                                <img src="{{ $image }}" alt="{{ $product->name }}">
                            @else
                                {{ $product->initials() }}
                            @endif
                        </span>
                    </td>

                    <td>
                        <strong>
                            {{--
                                The veg/non-veg mark, carrying a title so it
                                is not colour alone - a square nobody can read
                                is worse than no square.
                            --}}
                            @if ($product->foodTypeDot())
                                <span style="display:inline-block;width:9px;height:9px;border-radius:2px;
                                             background:{{ $product->foodTypeDot() }};margin-right:5px"
                                      title="{{ $product->foodTypeLabel() }}"
                                      aria-label="{{ $product->foodTypeLabel() }}"
                                      role="img"></span>
                            @endif

                            <a href="{{ route('admin.products.show', $product) }}"
                               data-modal="{{ route('admin.products.show', $product) }}"
                               data-modal-title="{{ $product->name }}"
                               data-modal-sub="Menu item"
                               data-modal-size="lg">{{ $product->name }}</a>
                        </strong>

                        @if ($product->is_sold_out)
                            <span class="badge badge-danger" style="margin-left:6px">Sold out</span>
                        @endif

                        <span class="text-xs text-muted" style="display:block">
                            <span class="list-ref">{{ $product->sku }}</span>
                            @if ($product->barcode) · {{ $product->barcode }} @endif
                            @if ($product->brand) · {{ $product->brand->name }} @endif
                            @if ($product->servingWindowLabel()) · {{ $product->servingWindowLabel() }} @endif
                        </span>
                    </td>

                    <td class="text-sm">{{ $product->category?->name ?? '—' }}</td>

                    <td style="text-align:right;white-space:nowrap">
                        <strong>₹{{ number_format($product->sellingPriceFor($shopId), 2) }}</strong>
                        @php $mrp = $product->mrpFor($shopId); @endphp
                        @if ($mrp > $product->sellingPriceFor($shopId))
                            <span class="text-xs text-muted" style="display:block">
                                MRP ₹{{ number_format($mrp, 2) }}
                            </span>
                        @endif
                    </td>

                    <td style="text-align:right;white-space:nowrap">
                        @if (! $tracksStock)
                            <span class="text-xs text-muted">Made to order</span>
                        @elseif ($onHand > 0)
                            <span class="{{ $isLow ? '' : '' }}"
                                  @if ($isLow) style="color:var(--warning);font-weight:600" @endif>
                                {{ $product->unit?->format($onHand) ?? number_format($onHand, 3) }}
                            </span>
                            @if ($isLow)
                                <span class="text-xs" style="display:block;color:var(--warning)">
                                    at reorder level
                                </span>
                            @endif
                        @else
                            <span class="badge badge-danger">Out of stock</span>
                        @endif
                    </td>

                    <td>
                        @allows('inventory.products.edit')
                            <form method="POST" action="{{ route('admin.products.published', $product) }}"
                                  data-ajax data-refresh-list style="display:inline">
                                @csrf
                                @method('PUT')
                                <button type="submit" class="status-toggle {{ $product->is_published ? 'is-on' : '' }}"
                                        title="{{ $product->is_published ? 'Hide from the online store' : 'Show in the online store' }}">
                                    <span class="badge-dot"></span>
                                    {{ $product->is_published ? 'Listed' : 'Hidden' }}
                                </button>
                            </form>
                        @else
                            <span class="badge {{ $product->is_published ? 'badge-success' : '' }}">
                                {{ $product->is_published ? 'Listed' : 'Hidden' }}
                            </span>
                        @endallows
                    </td>

                    <td>
                        @allows('inventory.products.edit')
                            <form method="POST" action="{{ route('admin.products.status', $product) }}"
                                  data-ajax data-refresh-list style="display:inline">
                                @csrf
                                @method('PUT')
                                <button type="submit" class="status-toggle {{ $product->is_active ? 'is-on' : '' }}"
                                        title="{{ $product->is_active ? 'Click to deactivate' : 'Click to activate' }}">
                                    <span class="badge-dot"></span>
                                    {{ $product->is_active ? 'Active' : 'Inactive' }}
                                </button>
                            </form>
                        @else
                            <span class="badge {{ $product->is_active ? 'badge-success' : 'badge-danger' }}">
                                <span class="badge-dot"></span>
                                {{ $product->is_active ? 'Active' : 'Inactive' }}
                            </span>
                        @endallows
                    </td>

                    <td class="col-action">
                        <div class="row-actions">
                            {{--
                                Sold out, in one tap (§8). First in the row
                                because it is the thing pressed most during
                                service, by somebody who is busy.
                            --}}
                            @allows('inventory.products.edit')
                                <form method="POST" action="{{ route('admin.products.sold-out', $product) }}"
                                      data-ajax data-refresh-list style="display:inline">
                                    @csrf
                                    @method('PUT')
                                    <button type="submit"
                                            class="btn btn-icon {{ $product->is_sold_out ? 'is-danger' : '' }}"
                                            title="{{ $product->is_sold_out ? 'Put back on the menu' : 'Mark sold out' }}"
                                            aria-label="{{ $product->is_sold_out ? 'Put '.$product->name.' back on the menu' : 'Mark '.$product->name.' sold out' }}">
                                        <x-icon name="{{ $product->is_sold_out ? 'user-x' : 'user-check' }}" :size="15" />
                                    </button>
                                </form>
                            @endallows

                            <a class="btn btn-icon" href="{{ route('admin.products.show', $product) }}"
                               data-modal="{{ route('admin.products.show', $product) }}"
                               data-modal-title="{{ $product->name }}"
                               data-modal-sub="Menu item"
                               data-modal-size="lg"
                               aria-label="View {{ $product->name }}">
                                <x-icon name="search" :size="15" />
                            </a>

                            @allows('inventory.products.edit')
                                <a class="btn btn-icon" href="{{ route('admin.products.edit', $product) }}"
                                   data-modal="{{ route('admin.products.edit', $product) }}"
                                   data-modal-title="Edit Product"
                                   data-modal-sub="{{ $product->name }}"
                                   data-modal-size="lg"
                                   aria-label="Edit {{ $product->name }}">
                                    <x-icon name="edit" :size="15" />
                                </a>
                            @endallows

                            @allows('inventory.products.delete')
                                <form method="POST" action="{{ route('admin.products.destroy', $product) }}"
                                      data-ajax data-refresh-list
                                      onsubmit="return confirm('Remove “{{ $product->name }}”? Its stock and sales history are kept for audit.')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-icon is-danger"
                                            aria-label="Remove {{ $product->name }}">
                                        <x-icon name="trash" :size="15" />
                                    </button>
                                </form>
                            @endallows
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8">
                        <div class="empty">
                            <x-icon name="inbox" :size="28" />
                            <h3>No products found</h3>
                            <p class="text-sm">Adjust the filters, or add the first one.</p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

{{--
    Grid view: the same $products collection rendered as cards instead of
    table rows. Hidden by default via CSS (data-view="list" on the wrapping
    [data-ajax-list-content]) so the table above remains what a no-JS visit
    and the default state both show.
--}}
<div class="product-grid">
    @forelse ($products as $product)
        @php
            $image = $product->imageUrl();
            $onHand = (float) ($product->on_hand ?? 0);
            $reorder = $product->reorderLevelFor($shopId);
            $tracksStock = $product->tracksStock();
            $isLow = $tracksStock && $reorder > 0 && $onHand <= $reorder;
            $price = $product->sellingPriceFor($shopId);
            $mrp = $product->mrpFor($shopId);
        @endphp
        <article class="product-card">
            <div class="product-card-top">
                <span class="cat-thumb product-card-thumb">
                    @if ($image)
                        <img src="{{ $image }}" alt="{{ $product->name }}" loading="lazy">
                    @else
                        {{ $product->initials() }}
                    @endif
                </span>

                <div class="product-card-heading">
                    <strong class="product-card-title">
                        <a href="{{ route('admin.products.show', $product) }}"
                           data-modal="{{ route('admin.products.show', $product) }}"
                           data-modal-title="{{ $product->name }}"
                           data-modal-sub="Product details"
                           data-modal-size="lg">{{ $product->name }}</a>
                    </strong>
                    <span class="text-xs text-muted product-card-sub">
                        <span class="list-ref">{{ $product->sku }}</span>
                        @if ($product->barcode) · {{ $product->barcode }} @endif
                        @if ($product->brand) · {{ $product->brand->name }} @endif
                    </span>
                </div>
            </div>

            <dl class="product-card-facts">
                <div>
                    <dt>Category</dt>
                    <dd>{{ $product->category?->name ?? '—' }}</dd>
                </div>

                <div>
                    <dt>Price</dt>
                    <dd>
                        ₹{{ number_format($price, 2) }}
                        @if ($mrp > $price)
                            <span class="text-xs text-muted" style="display:block">MRP ₹{{ number_format($mrp, 2) }}</span>
                        @endif
                    </dd>
                </div>

                <div>
                    <dt>Stock @if ($shopId === null)<span class="text-xs text-muted">(all)</span>@endif</dt>
                    <dd>
                        @if (! $tracksStock)
                            <span class="text-xs text-muted">Made to order</span>
                        @elseif ($onHand > 0)
                            <span @if ($isLow) style="color:var(--warning);font-weight:600" @endif>
                                {{ $product->unit?->format($onHand) ?? number_format($onHand, 3) }}
                            </span>
                        @else
                            <span class="badge badge-danger">Out of stock</span>
                        @endif
                    </dd>
                </div>
            </dl>

            <div class="product-card-toggles">
                @allows('inventory.products.edit')
                    <form method="POST" action="{{ route('admin.products.published', $product) }}"
                          data-ajax data-refresh-list>
                        @csrf
                        @method('PUT')
                        <button type="submit" class="status-toggle {{ $product->is_published ? 'is-on' : '' }}"
                                title="{{ $product->is_published ? 'Hide from the online store' : 'Show in the online store' }}">
                            <span class="badge-dot"></span>
                            {{ $product->is_published ? 'Listed' : 'Hidden' }}
                        </button>
                    </form>
                @else
                    <span class="badge {{ $product->is_published ? 'badge-success' : '' }}">
                        {{ $product->is_published ? 'Listed' : 'Hidden' }}
                    </span>
                @endallows

                @allows('inventory.products.edit')
                    <form method="POST" action="{{ route('admin.products.status', $product) }}"
                          data-ajax data-refresh-list>
                        @csrf
                        @method('PUT')
                        <button type="submit" class="status-toggle {{ $product->is_active ? 'is-on' : '' }}"
                                title="{{ $product->is_active ? 'Click to deactivate' : 'Click to activate' }}">
                            <span class="badge-dot"></span>
                            {{ $product->is_active ? 'Active' : 'Inactive' }}
                        </button>
                    </form>
                @else
                    <span class="badge {{ $product->is_active ? 'badge-success' : 'badge-danger' }}">
                        <span class="badge-dot"></span>
                        {{ $product->is_active ? 'Active' : 'Inactive' }}
                    </span>
                @endallows
            </div>

            <div class="product-card-footer row-actions">
                <a class="btn btn-icon" href="{{ route('admin.products.show', $product) }}"
                   data-modal="{{ route('admin.products.show', $product) }}"
                   data-modal-title="{{ $product->name }}"
                   data-modal-sub="Product details"
                   data-modal-size="lg"
                   aria-label="View {{ $product->name }}">
                    <x-icon name="search" :size="15" />
                </a>

                @allows('inventory.products.edit')
                    <a class="btn btn-icon" href="{{ route('admin.products.edit', $product) }}"
                       data-modal="{{ route('admin.products.edit', $product) }}"
                       data-modal-title="Edit Product"
                       data-modal-sub="{{ $product->name }}"
                       data-modal-size="lg"
                       aria-label="Edit {{ $product->name }}">
                        <x-icon name="edit" :size="15" />
                    </a>
                @endallows

                @allows('inventory.products.delete')
                    <form method="POST" action="{{ route('admin.products.destroy', $product) }}"
                          data-ajax data-refresh-list
                          onsubmit="return confirm('Remove “{{ $product->name }}”? Its stock and sales history are kept for audit.')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-icon is-danger"
                                aria-label="Remove {{ $product->name }}">
                            <x-icon name="trash" :size="15" />
                        </button>
                    </form>
                @endallows
            </div>
        </article>
    @empty
        <div class="empty">
            <x-icon name="inbox" :size="28" />
            <h3>No products found</h3>
            <p class="text-sm">Adjust the filters, or add the first one.</p>
        </div>
    @endforelse
</div>

<x-pagination :paginator="$products" :per-page="$perPage" :page-sizes="$pageSizes" label="products" />
