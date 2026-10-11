<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Shop;
use App\Services\CouponService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Coupon validation and discount math - see Coupon's docblock for why v1
 * stops at percent/fixed with a minimum order amount and usage limits.
 */
class CouponServiceTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private Customer $customer;

    private CouponService $coupons;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();

        $this->shop = Shop::first();
        $this->customer = Customer::create([
            'shop_id' => $this->shop->id,
            'code' => Customer::nextCode($this->shop->id),
            'name' => 'Coupon Customer',
            'mobile' => '9700000001',
            'type' => 'retail',
            'is_active' => true,
        ]);
        $this->coupons = new CouponService();
    }

    private function coupon(array $attributes = []): Coupon
    {
        return Coupon::create([
            'shop_id' => $this->shop->id,
            'code' => 'TESTCODE',
            'type' => Coupon::PERCENT,
            'value' => 10,
            'min_order_amount' => 0,
            'usage_limit_per_customer' => 1,
            'is_active' => true,
            ...$attributes,
        ]);
    }

    public function test_percent_discount_is_capped_by_max_discount_amount(): void
    {
        $coupon = $this->coupon(['type' => Coupon::PERCENT, 'value' => 50, 'max_discount_amount' => 100]);

        $this->assertEqualsWithDelta(100.0, $this->coupons->discountFor($coupon, 1000), 0.01);
    }

    public function test_fixed_discount_never_exceeds_the_subtotal(): void
    {
        $coupon = $this->coupon(['type' => Coupon::FIXED, 'value' => 500]);

        $this->assertEqualsWithDelta(200.0, $this->coupons->discountFor($coupon, 200), 0.01);
    }

    public function test_order_below_the_minimum_amount_is_rejected(): void
    {
        $coupon = $this->coupon(['min_order_amount' => 500]);

        $this->expectException(RuntimeException::class);
        $this->coupons->validate($this->shop, $coupon->code, $this->customer, 300);
    }

    public function test_a_coupon_cannot_be_used_more_than_its_per_customer_limit(): void
    {
        $coupon = $this->coupon(['usage_limit_per_customer' => 1]);

        \App\Models\CouponRedemption::create([
            'coupon_id' => $coupon->id,
            'shop_id' => $this->shop->id,
            'customer_id' => $this->customer->id,
            'order_id' => $this->fakeOrder($coupon)->id,
            'discount_amount' => 10,
        ]);

        $this->expectException(RuntimeException::class);
        $this->coupons->validate($this->shop, $coupon->code, $this->customer, 1000);
    }

    public function test_a_coupon_outside_its_date_window_is_rejected(): void
    {
        $expired = $this->coupon(['code' => 'EXPIRED', 'expires_at' => now()->subDay()]);

        $this->expectException(RuntimeException::class);
        $this->coupons->validate($this->shop, $expired->code, $this->customer, 1000);
    }

    public function test_a_valid_coupon_passes_validation(): void
    {
        $coupon = $this->coupon(['min_order_amount' => 100]);

        $result = $this->coupons->validate($this->shop, $coupon->code, $this->customer, 500);

        $this->assertSame($coupon->id, $result->id);
    }

    /** A minimal order row, just so a redemption has something to point at. */
    private function fakeOrder(Coupon $coupon): \App\Models\Order
    {
        return \App\Models\Order::create([
            'shop_id' => $this->shop->id,
            'customer_id' => $this->customer->id,
            'order_number' => 'ORD-TEST-'.random_int(1000, 9999),
            'status' => \App\Models\Order::PENDING,
            'payment_method' => 'cod',
            'payment_status' => 'pending',
            'subtotal' => 100,
            'grand_total' => 90,
            'ship_recipient_name' => 'Test',
            'ship_mobile' => '9000000000',
            'ship_address_line1' => 'Test Address',
            'placed_at' => now(),
        ]);
    }
}
