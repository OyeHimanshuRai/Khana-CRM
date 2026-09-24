<?php

namespace App\Services;

use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Shop;
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
        $coupon = Coupon::forShop($shop->id)->where('code', $code)->first();

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

        /*
         | Both caps below are counted under a row lock on the coupon, and
         | counted with locking reads.
         |
         | Two checkouts arriving together is not the rare case for a coupon -
         | it is what a code posted to a group chat produces - and unlocked,
         | both read the same snapshot, both find room under the cap and both
         | redeem it. The lock serialises them; the locking counts are what
         | make the second one see the first, because a plain count under
         | REPEATABLE READ still answers from the snapshot the transaction
         | opened before the other checkout committed. The redemption row is
         | written by the caller inside this same transaction, so the lock
         | holds until there is something to count.
         */
        if ($coupon->usage_limit !== null || $coupon->usage_limit_per_customer !== null) {
            $coupon = Coupon::forShop($shop->id)->whereKey($coupon->id)->lockForUpdate()->first() ?? $coupon;
        }

        if ($coupon->usage_limit !== null && $coupon->redemptions()->lockForUpdate()->count() >= $coupon->usage_limit) {
            throw new RuntimeException('This coupon has reached its usage limit.');
        }

        if ($coupon->usage_limit_per_customer !== null) {
            $used = $coupon->redemptions()->where('customer_id', $customer->id)->lockForUpdate()->count();

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
