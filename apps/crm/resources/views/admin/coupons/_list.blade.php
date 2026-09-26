<div class="table-wrap">
    <table class="table table-list">
        <thead>
            <tr>
                <th>Code</th>
                <th>Discount</th>
                <th>Min order</th>
                <th>Usage</th>
                <th>Window</th>
                <th>Status</th>
                <th class="col-action">Actions</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($coupons as $coupon)
                <tr>
                    <td>
                        <strong>
                            <a href="{{ route('admin.coupons.show', $coupon) }}"
                               data-modal="{{ route('admin.coupons.show', $coupon) }}"
                               data-modal-title="{{ $coupon->code }}"
                               data-modal-sub="Coupon details">{{ $coupon->code }}</a>
                        </strong>
                        @if ($coupon->description)
                            <span class="text-xs text-muted" style="display:block">{{ $coupon->description }}</span>
                        @endif
                    </td>

                    <td class="text-sm">
                        {{ $coupon->type === 'percent' ? rtrim(rtrim(number_format($coupon->value, 2), '0'), '.').'%' : '₹'.number_format($coupon->value, 2) }}
                        @if ($coupon->type === 'percent' && $coupon->max_discount_amount)
                            <span class="text-xs text-muted" style="display:block">Up to ₹{{ number_format($coupon->max_discount_amount, 2) }}</span>
                        @endif
                    </td>

                    <td class="text-sm">₹{{ number_format($coupon->min_order_amount, 2) }}</td>

                    <td class="text-sm">
                        {{ $coupon->usage_limit ?? '∞' }} total
                        <span class="text-xs text-muted" style="display:block">{{ $coupon->usage_limit_per_customer ?? '∞' }} / customer</span>
                    </td>

                    <td class="text-sm">
                        @if ($coupon->starts_at || $coupon->expires_at)
                            {{ $coupon->starts_at?->format('d M Y') ?? '—' }} &ndash; {{ $coupon->expires_at?->format('d M Y') ?? '—' }}
                        @else
                            Always on
                        @endif
                    </td>

                    <td>
                        @allows('sales.coupons.edit')
                            <form method="POST" action="{{ route('admin.coupons.status', $coupon) }}"
                                  data-ajax data-refresh-list style="display:inline">
                                @csrf
                                @method('PUT')
                                <button type="submit" class="status-toggle {{ $coupon->is_active ? 'is-on' : '' }}"
                                        title="{{ $coupon->is_active ? 'Click to deactivate' : 'Click to activate' }}">
                                    <span class="badge-dot"></span>
                                    {{ $coupon->is_active ? 'Active' : 'Inactive' }}
                                </button>
                            </form>
                        @else
                            <span class="badge {{ $coupon->is_active ? 'badge-success' : 'badge-danger' }}">
                                <span class="badge-dot"></span> {{ $coupon->is_active ? 'Active' : 'Inactive' }}
                            </span>
                        @endallows
                    </td>

                    <td class="col-action">
                        <div class="row-actions">
                            @allows('sales.coupons.edit')
                                <a class="btn btn-icon" href="{{ route('admin.coupons.edit', $coupon) }}"
                                   data-modal="{{ route('admin.coupons.edit', $coupon) }}"
                                   data-modal-title="Edit Coupon"
                                   data-modal-sub="{{ $coupon->code }}"
                                   data-modal-size="lg"
                                   aria-label="Edit {{ $coupon->code }}">
                                    <x-icon name="edit" :size="15" />
                                </a>
                            @endallows

                            @allows('sales.coupons.delete')
                                <form method="POST" action="{{ route('admin.coupons.destroy', $coupon) }}"
                                      data-ajax data-refresh-list
                                      onsubmit="return confirm('Remove coupon “{{ $coupon->code }}”?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-icon is-danger" aria-label="Remove {{ $coupon->code }}">
                                        <x-icon name="trash" :size="15" />
                                    </button>
                                </form>
                            @endallows
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">
                        <div class="empty">
                            <x-icon name="inbox" :size="28" />
                            <h3>No coupons yet</h3>
                            <p class="text-sm">Add one to offer a discount at storefront checkout.</p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<x-pagination :paginator="$coupons" label="coupons" />
