@php
    $isNew = ! $coupon->exists;
@endphp

<form method="POST"
      action="{{ $isNew ? route('admin.coupons.store') : route('admin.coupons.update', $coupon) }}"
      data-ajax data-close-modal data-refresh-list>
    @csrf
    @unless ($isNew) @method('PUT') @endunless

    <div class="form-section">
        <div class="settings-grid">
            <div class="field">
                <label for="coupon-code">Code</label>
                <input id="coupon-code" type="text" name="code" class="form-control" required
                       value="{{ $coupon->code }}" style="text-transform:uppercase" autocomplete="off" aria-invalid="false">
                <div class="form-hint">What the customer types at checkout — kept uppercase.</div>
            </div>

            <div class="field">
                <label for="coupon-type">Discount type</label>
                <select id="coupon-type" name="type" class="form-control" required aria-invalid="false">
                    <option value="percent" @selected($coupon->type === 'percent')>Percent off</option>
                    <option value="fixed" @selected($coupon->type === 'fixed')>Fixed amount off</option>
                </select>
            </div>

            <div class="field">
                <label for="coupon-value">Value</label>
                <input id="coupon-value" type="number" step="0.01" name="value" class="form-control" required
                       value="{{ $coupon->value }}" aria-invalid="false">
            </div>

            <div class="field">
                <label for="coupon-max-discount">Max discount (percent only)</label>
                <input id="coupon-max-discount" type="number" step="0.01" name="max_discount_amount" class="form-control"
                       value="{{ $coupon->max_discount_amount }}" aria-invalid="false">
            </div>

            <div class="field">
                <label for="coupon-min-order">Minimum order amount</label>
                <input id="coupon-min-order" type="number" step="0.01" name="min_order_amount" class="form-control"
                       value="{{ $coupon->min_order_amount ?? 0 }}" aria-invalid="false">
            </div>

            <div class="field">
                <label for="coupon-usage-limit">Total usage limit</label>
                <input id="coupon-usage-limit" type="number" name="usage_limit" class="form-control"
                       value="{{ $coupon->usage_limit }}" placeholder="Unlimited" aria-invalid="false">
            </div>

            <div class="field">
                <label for="coupon-usage-per-customer">Usage limit per customer</label>
                <input id="coupon-usage-per-customer" type="number" name="usage_limit_per_customer" class="form-control"
                       value="{{ $coupon->usage_limit_per_customer ?? 1 }}" aria-invalid="false">
            </div>

            <div class="field">
                <label for="coupon-starts">Starts</label>
                <input id="coupon-starts" type="datetime-local" name="starts_at" class="form-control"
                       value="{{ $coupon->starts_at?->format('Y-m-d\TH:i') }}" aria-invalid="false">
            </div>

            <div class="field">
                <label for="coupon-expires">Expires</label>
                <input id="coupon-expires" type="datetime-local" name="expires_at" class="form-control"
                       value="{{ $coupon->expires_at?->format('Y-m-d\TH:i') }}" aria-invalid="false">
            </div>

            <div class="field field-full">
                <label for="coupon-description">Description</label>
                <input id="coupon-description" type="text" name="description" class="form-control"
                       value="{{ $coupon->description }}" placeholder="Shown to the customer" aria-invalid="false">
            </div>

            <div class="field field-full">
                <div class="form-label">Status</div>
                <label class="check">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" value="1" @checked($isNew ? true : $coupon->is_active)>
                    Active
                </label>
            </div>
        </div>
    </div>

    <div class="modal-actions">
        <button type="button" class="btn" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary">{{ $isNew ? 'Create coupon' : 'Save changes' }}</button>
    </div>
</form>
