<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Shop;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\WishlistItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * What a shopper does on the storefront after the first click: change the
 * cart, keep a wishlist, save addresses, edit their account, try a coupon.
 * Every action is also tried by somebody who is not signed in, and by a
 * customer of another shop.
 */
class StorefrontCustomerAreaTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');
        $this->seed();
        $this->shop = Shop::query()->orderBy('id')->firstOrFail();
    }

    private function product(string $name = 'Shop Item'): Product
    {
        static $n = 0;
        $n++;

        return Product::create([
            'name' => "{$name} {$n}", 'slug' => Product::uniqueSlug("{$name} {$n}"), 'sku' => Product::generateSku("SHP{$n}"),
            'unit_id' => Unit::where('code', 'PCS')->value('id'), 'tax_rate_id' => TaxRate::where('rate', 18)->value('id'),
            'purchase_price' => 100, 'mrp' => 200, 'selling_price' => 180, 'tax_inclusive' => true,
            'is_active' => true, 'is_published' => true,
        ]);
    }

    private function customer(?Shop $shop = null): Customer
    {
        static $n = 0;
        $n++;
        $shop ??= $this->shop;

        return Customer::create([
            'shop_id' => $shop->id, 'code' => Customer::nextCode($shop->id), 'name' => "Shopper {$n}",
            'mobile' => '9700000'.str_pad((string) $n, 3, '0', STR_PAD_LEFT), 'email' => "shopper{$n}@example.test",
            'password' => Hash::make('password123'), 'type' => 'retail', 'is_active' => true,
        ]);
    }

    private function signedIn(?Customer $customer = null): Customer
    {
        $customer ??= $this->customer();
        $this->actingAs($customer, 'customer');

        return $customer;
    }

    /* --------------------------------------------------------------- cart */

    public function test_the_cart_quantity_can_be_changed_and_an_item_removed(): void
    {
        $product = $this->product();
        $this->post(route('shop.cart.add', $this->shop), ['product_id' => $product->id, 'quantity' => 1]);

        $this->patch(route('shop.cart.update', [$this->shop, $product]), ['quantity' => 4])->assertRedirect();
        $this->get(route('shop.cart', $this->shop))->assertOk()->assertSee($product->name);

        $this->delete(route('shop.cart.remove', [$this->shop, $product]))->assertRedirect();
        $this->get(route('shop.cart', $this->shop))->assertDontSee($product->name);
    }

    public function test_a_negative_cart_quantity_is_refused(): void
    {
        $product = $this->product();
        $this->post(route('shop.cart.add', $this->shop), ['product_id' => $product->id, 'quantity' => 1]);

        $this->patchJson(route('shop.cart.update', [$this->shop, $product]), ['quantity' => -3])->assertStatus(422);
    }

    /* ----------------------------------------------------------- wishlist */

    public function test_a_product_can_be_wished_for_and_un_wished(): void
    {
        $customer = $this->signedIn();
        $product = $this->product();

        $this->postJson(route('shop.wishlist.toggle', [$this->shop, $product]))->assertOk();
        $this->assertTrue(WishlistItem::query()->where('customer_id', $customer->id)->where('product_id', $product->id)->exists());
        $this->get(route('shop.wishlist.index', $this->shop))->assertOk()->assertSee($product->name);

        $this->postJson(route('shop.wishlist.toggle', [$this->shop, $product]))->assertOk();
        $this->assertFalse(WishlistItem::query()->where('customer_id', $customer->id)->where('product_id', $product->id)->exists());
    }

    public function test_a_guest_cannot_use_the_wishlist_or_the_account_pages(): void
    {
        $product = $this->product();

        $this->post(route('shop.wishlist.toggle', [$this->shop, $product]))->assertRedirect();
        $this->get(route('shop.wishlist.index', $this->shop))->assertRedirect();
        $this->get(route('shop.account.edit', $this->shop))->assertRedirect();
        $this->get(route('shop.account.addresses.index', $this->shop))->assertRedirect();
        $this->get(route('shop.orders.index', $this->shop))->assertRedirect();
        $this->assertSame(0, WishlistItem::query()->count());
    }

    /* ----------------------------------------------------------- address */

    private function address(array $over = []): array
    {
        return array_merge([
            'label' => 'Home', 'recipient_name' => 'Asha Menon', 'mobile' => '9811100003',
            'address_line1' => '12 MG Road', 'city' => 'Pune', 'state' => 'Maharashtra', 'pincode' => '411001',
        ], $over);
    }

    public function test_addresses_are_added_changed_and_removed(): void
    {
        $customer = $this->signedIn();

        $this->post(route('shop.account.addresses.store', $this->shop), $this->address())->assertRedirect();
        $address = Address::query()->where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame('12 MG Road', $address->address_line1);

        $this->put(route('shop.account.addresses.update', [$this->shop, $address]), $this->address(['address_line1' => '14 MG Road']))->assertRedirect();
        $this->assertSame('14 MG Road', $address->fresh()->address_line1);

        $this->delete(route('shop.account.addresses.destroy', [$this->shop, $address]))->assertRedirect();
        $this->assertNull(Address::query()->find($address->id));
    }

    public function test_an_address_needs_a_name_a_phone_and_a_street(): void
    {
        $this->signedIn();

        $this->post(route('shop.account.addresses.store', $this->shop), [])
            ->assertSessionHasErrors(['recipient_name', 'mobile', 'address_line1']);
        $this->assertSame(0, Address::query()->count());
    }

    public function test_nobody_can_change_or_delete_another_shoppers_address(): void
    {
        $owner = $this->customer();
        $address = Address::create(array_merge($this->address(), ['customer_id' => $owner->id, 'shop_id' => $this->shop->id]));

        $this->signedIn($this->customer());

        $this->put(route('shop.account.addresses.update', [$this->shop, $address]), $this->address(['address_line1' => 'Hijacked']))->assertNotFound();
        $this->delete(route('shop.account.addresses.destroy', [$this->shop, $address]))->assertNotFound();

        $this->assertSame('12 MG Road', $address->fresh()->address_line1);
    }

    /* ------------------------------------------------------------ account */

    public function test_a_shopper_edits_their_own_details(): void
    {
        $customer = $this->signedIn();

        $this->put(route('shop.account.update', $this->shop), ['name' => 'Asha M. Menon', 'mobile' => $customer->mobile, 'email' => $customer->email])
            ->assertRedirect();

        $this->assertSame('Asha M. Menon', $customer->fresh()->name);
    }

    public function test_a_blank_name_is_refused(): void
    {
        $customer = $this->signedIn();

        $this->put(route('shop.account.update', $this->shop), ['name' => '', 'mobile' => $customer->mobile])
            ->assertSessionHasErrors('name');
    }

    /* ------------------------------------------------------------- coupon */

    public function test_a_coupon_is_applied_to_the_basket_and_a_wrong_one_is_refused(): void
    {
        // Made before anyone signs in: a shopper cannot create the shop's coupons.
        Coupon::create([
            'shop_id' => $this->shop->id, 'code' => 'TEN', 'type' => 'percent', 'value' => 10, 'min_order_amount' => 0,
            'usage_limit' => 100, 'starts_at' => now()->subDay(), 'expires_at' => now()->addDay(), 'is_active' => true,
        ]);

        $this->signedIn();
        $product = $this->product();
        $this->post(route('shop.cart.add', $this->shop), ['product_id' => $product->id, 'quantity' => 2]);

        $this->postJson(route('shop.checkout.coupon', $this->shop), ['code' => 'TEN'])
            ->assertOk()->assertJsonPath('success', true);

        $this->postJson(route('shop.checkout.coupon', $this->shop), ['code' => 'NOSUCHCODE'])->assertStatus(422);
        $this->postJson(route('shop.checkout.coupon', $this->shop), [])->assertStatus(422);
    }

    public function test_an_expired_coupon_is_refused(): void
    {
        Coupon::create([
            'shop_id' => $this->shop->id, 'code' => 'OLD', 'type' => 'fixed', 'value' => 50, 'min_order_amount' => 0,
            'starts_at' => now()->subDays(10), 'expires_at' => now()->subDay(), 'is_active' => true,
        ]);

        $this->signedIn();
        $product = $this->product();
        $this->post(route('shop.cart.add', $this->shop), ['product_id' => $product->id, 'quantity' => 1]);

        $this->postJson(route('shop.checkout.coupon', $this->shop), ['code' => 'OLD'])->assertStatus(422);
    }

    /* -------------------------------------------------------- registration */

    public function test_the_register_and_login_pages_open_for_a_guest(): void
    {
        $this->get(route('shop.register', $this->shop))->assertOk();
        $this->get(route('shop.login', $this->shop))->assertOk();
    }

    public function test_a_signed_in_shopper_is_sent_away_from_register_and_login(): void
    {
        $this->signedIn();

        $this->get(route('shop.register', $this->shop))->assertRedirect();
        $this->get(route('shop.login', $this->shop))->assertRedirect();
    }
}
