{{-- Read-only coupon detail, rendered straight into the modal body. --}}

<div>
    <div style="display:flex;flex-wrap:wrap;gap:7px;margin-bottom:14px">
        <span class="badge {{ $coupon->is_active ? 'badge-success' : 'badge-danger' }}">
            <span class="badge-dot"></span> {{ $coupon->is_active ? 'Active' : 'Inactive' }}
        </span>
        <span class="badge">{{ $coupon->typeLabel() }}</span>
        @unless ($coupon->isWithinWindow())
            <span class="badge badge-warning">Outside its active window</span>
        @endunless
    </div>

    <dl class="sec-facts">
        <div><dt>Code</dt><dd>{{ $coupon->code }}</dd></div>
        @if ($coupon->description)
            <div><dt>Description</dt><dd>{{ $coupon->description }}</dd></div>
        @endif
        <div>
            <dt>Discount</dt>
            <dd>
                {{ $coupon->type === 'percent' ? rtrim(rtrim(number_format($coupon->value, 2), '0'), '.').'%' : '₹'.number_format($coupon->value, 2) }}
                @if ($coupon->type === 'percent' && $coupon->max_discount_amount)
                    (capped at ₹{{ number_format($coupon->max_discount_amount, 2) }})
                @endif
            </dd>
        </div>
        <div><dt>Minimum order</dt><dd>₹{{ number_format($coupon->min_order_amount, 2) }}</dd></div>
        <div><dt>Total usage</dt><dd>{{ $coupon->redemptions_count ?? $coupon->redemptions()->count() }} / {{ $coupon->usage_limit ?? 'Unlimited' }}</dd></div>
        <div><dt>Per customer</dt><dd>{{ $coupon->usage_limit_per_customer ?? 'Unlimited' }}</dd></div>
        <div><dt>Starts</dt><dd>{{ $coupon->starts_at?->format('d M Y, H:i') ?? 'Immediately' }}</dd></div>
        <div><dt>Expires</dt><dd>{{ $coupon->expires_at?->format('d M Y, H:i') ?? 'Never' }}</dd></div>
    </dl>
</div>
