<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Shop;
use App\Services\CouponService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Tests\TestCase;

/**
 * Cross-shop isolation on the storefront.
 *
 * Order and Coupon carry BelongsToShop for the admin's benefit, but that
 * scope is a no-op under the `customer` guard - see ShopScope's docblock.
 * These tests exist to prove the explicit ::forShop() scoping actually
 * being used everywhere holds, not to re-prove ShopScope itself (that's
 * covered by ShopScopeTest already).
 */
class StorefrontShopIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shopA;

    private Shop $shopB;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');

        $this->seed();

        $this->shopA = Shop::first();
        $this->shopB = Shop::create([
            'name' => 'Second Shop', 'code' => 'SHOP2', 'slug' => 'second-shop',
            'invoice_prefix' => 'INV', 'pos_prefix' => 'POS',
            'currency' => 'INR', 'timezone' => 'Asia/Kolkata', 'is_active' => true,
        ]);
    }

    private function customer(Shop $shop): Customer
    {
        static $counter = 0;
        $counter++;

        return Customer::create([
            'shop_id' => $shop->id,
            'code' => Customer::nextCode($shop->id),
            'name' => 'Isolation Customer '.$counter,
            'mobile' => '94000000'.str_pad((string) $counter, 2, '0', STR_PAD_LEFT),
            'password' => Hash::make('password123'),
            'type' => 'retail',
            'is_active' => true,
        ]);
    }

    private function order(Shop $shop, Customer $customer): Order
    {
        static $counter = 0;
        $counter++;

        return Order::create([
            'shop_id' => $shop->id,
            'customer_id' => $customer->id,
            'order_number' => 'ORD-ISO-'.$counter,
            'status' => Order::PENDING,
            'payment_method' => 'cod',
            'payment_status' => 'pending',
            'subtotal' => 100,
            'grand_total' => 100,
            'ship_recipient_name' => 'Test',
            'ship_mobile' => '9000000000',
            'ship_address_line1' => 'Test Address',
            'placed_at' => now(),
        ]);
    }

    public function test_a_shop_a_customer_cannot_view_shop_bs_order(): void
    {
        $customerA = $this->customer($this->shopA);
        $customerB = $this->customer($this->shopB);
        $orderB = $this->order($this->shopB, $customerB);

        // customerA authenticated, but browsing shopA's own order list for
        // an id that belongs to shopB - customer.shop middleware would
        // already reject shopB's routes outright, so this proves the
        // second, independent layer: OrderController::show()'s own
        // forShop()+customer_id scoping.
        $response = $this->actingAs($customerA, 'customer')
            ->get(route('shop.orders.show', ['shop' => $this->shopA, 'order' => $orderB->id]));

        $response->assertNotFound();
    }

    public function test_identical_coupon_codes_on_two_shops_redeem_independently(): void
    {
        $customerA = $this->customer($this->shopA);
        $customerB = $this->customer($this->shopB);

        $couponA = Coupon::create([
            'shop_id' => $this->shopA->id, 'code' => 'SAME10',
            'type' => Coupon::PERCENT, 'value' => 10, 'is_active' => true,
        ]);
        $couponB = Coupon::create([
            'shop_id' => $this->shopB->id, 'code' => 'SAME10',
            'type' => Coupon::PERCENT, 'value' => 25, 'is_active' => true,
        ]);

        $service = new CouponService();

        $resultA = $service->validate($this->shopA, 'SAME10', $customerA, 500);
        $resultB = $service->validate($this->shopB, 'SAME10', $customerB, 500);

        $this->assertSame($couponA->id, $resultA->id);
        $this->assertSame($couponB->id, $resultB->id);
        $this->assertNotSame($resultA->id, $resultB->id);
    }

    public function test_a_shop_bs_coupon_code_does_not_validate_against_shop_a(): void
    {
        $customerA = $this->customer($this->shopA);

        Coupon::create([
            'shop_id' => $this->shopB->id, 'code' => 'ONLYB',
            'type' => Coupon::PERCENT, 'value' => 10, 'is_active' => true,
        ]);

        $this->expectException(RuntimeException::class);
        (new CouponService())->validate($this->shopA, 'ONLYB', $customerA, 500);
    }
}
