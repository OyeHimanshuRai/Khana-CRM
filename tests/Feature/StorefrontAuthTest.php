<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Storefront registration and sign-in: mobile-or-email plus password,
 * scoped to one shop's `customer` guard session (config/auth.php).
 */
class StorefrontAuthTest extends TestCase
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

    public function test_a_customer_can_register_with_only_a_mobile_number(): void
    {
        $response = $this->post(route('shop.register.store', $this->shopA), [
            'name' => 'Mobile Only Farmer',
            'mobile' => '9123456780',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('customers', [
            'shop_id' => $this->shopA->id,
            'mobile' => '9123456780',
            'email' => null,
        ]);
    }

    public function test_a_customer_can_register_with_only_an_email(): void
    {
        $response = $this->post(route('shop.register.store', $this->shopA), [
            'name' => 'Email Only Farmer',
            'email' => 'emailonly@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('customers', [
            'shop_id' => $this->shopA->id,
            'email' => 'emailonly@example.test',
        ]);
    }

    public function test_registration_requires_a_mobile_or_an_email(): void
    {
        $response = $this->post(route('shop.register.store', $this->shopA), [
            'name' => 'No Contact',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertSessionHasErrors('mobile');
    }

    public function test_a_customer_can_sign_in_by_mobile_or_email(): void
    {
        $customer = Customer::create([
            'shop_id' => $this->shopA->id,
            'code' => Customer::nextCode($this->shopA->id),
            'name' => 'Sign In Customer',
            'mobile' => '9988776655',
            'email' => 'signin@example.test',
            'password' => Hash::make('secret123'),
            'type' => 'retail',
            'is_active' => true,
        ]);

        $this->post(route('shop.login.store', $this->shopA), [
            'login' => $customer->mobile,
            'password' => 'secret123',
        ])->assertRedirect();

        $this->assertAuthenticatedAs($customer, 'customer');

        auth('customer')->logout();

        $this->post(route('shop.login.store', $this->shopA), [
            'login' => $customer->email,
            'password' => 'secret123',
        ])->assertRedirect();

        $this->assertAuthenticatedAs($customer, 'customer');
    }

    public function test_a_shop_a_customer_cannot_sign_in_on_shop_bs_storefront(): void
    {
        $customer = Customer::create([
            'shop_id' => $this->shopA->id,
            'code' => Customer::nextCode($this->shopA->id),
            'name' => 'Shop A Customer',
            'mobile' => '9000011111',
            'password' => Hash::make('secret123'),
            'type' => 'retail',
            'is_active' => true,
        ]);

        $response = $this->post(route('shop.login.store', $this->shopB), [
            'login' => $customer->mobile,
            'password' => 'secret123',
        ]);

        $response->assertSessionHasErrors('login');
        $this->assertGuest('customer');
    }

    public function test_a_shop_a_customer_session_is_rejected_on_shop_bs_account_pages(): void
    {
        $customer = Customer::create([
            'shop_id' => $this->shopA->id,
            'code' => Customer::nextCode($this->shopA->id),
            'name' => 'Cross Shop Customer',
            'mobile' => '9000022222',
            'password' => Hash::make('secret123'),
            'type' => 'retail',
            'is_active' => true,
        ]);

        $response = $this->actingAs($customer, 'customer')
            ->get(route('shop.orders.index', $this->shopB));

        $response->assertRedirect(route('shop.login', $this->shopB));
        $this->assertGuest('customer');
    }
}
