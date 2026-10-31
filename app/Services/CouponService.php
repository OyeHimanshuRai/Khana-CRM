<?php

namespace App\Services;

use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Shop;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Coupon validation and discount math for storefront checkout.
 *
 * v1 supports exactly two rule shapes - percent-off (optionally capped) and
 * fixed-amount-off - plus a minimum order amount and usage limits. See
 * Coupon's docblock for why it stops there.
 */
class CouponService
{
    /**
     * Find and validate a coupon for this shop/customer/order size, or
     * throw a message fit to show the customer directly.
     */
    public function validate(Shop $shop, string $code, Customer $customer, float $subtotal): Coupon
    {
        /*
         | Locked when asked from inside a transaction - which is how an order
         | redeems it (OrderService::place). Two checkouts with the same code
         | then queue on this row, and the second counts the first one's
         | redemption before deciding; without the lock both read "99 of
         | 100 used" and both went through. Outside a transaction (the
         | "apply coupon" preview) there is nothing to hold, so no lock.
         */
        $coupon = Coupon::forShop($shop->id)
            ->where('code', $code)
            ->when(DB::transactionLevel() > 0, fn ($query) => $query->lockForUpdate())
            ->first();

        if (! $coupon || ! $coupon->is_active) {
            throw new RuntimeException('That coupon code is not valid.');
        }

        if (! $coupon->isWithinWindow()) {
            throw new RuntimeException('That coupon is not active right now.');
        }

        if ($subtotal < (float) $coupon->min_order_amount) {
            throw new RuntimeException(sprintf(
                'This coupon needs a minimum order of ₹%s.',
                number_format((float) $coupon->min_order_amount, 2),
            ));
        }

        if ($coupon->usage_limit !== null && $coupon->redemptions()->count() >= $coupon->usage_limit) {
            throw new RuntimeException('This coupon has reached its usage limit.');
        }

        if ($coupon->usage_limit_per_customer !== null) {
            $used = $coupon->redemptions()->where('customer_id', $customer->id)->count();

            if ($used >= $coupon->usage_limit_per_customer) {
                throw new RuntimeException('You have already used this coupon.');
            }
        }

        return $coupon;
    }

    /** The discount a coupon works out to on a given subtotal. */
    public function discountFor(Coupon $coupon, float $subtotal): float
    {
        $discount = $coupon->type === Coupon::PERCENT
            ? $subtotal * (float) $coupon->value / 100
            : (float) $coupon->value;

        if ($coupon->type === Coupon::PERCENT && $coupon->max_discount_amount !== null) {
            $discount = min($discount, (float) $coupon->max_discount_amount);
        }

        return round(min($discount, $subtotal), 2);
    }
}
