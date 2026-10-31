<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Shop;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Separate doors.
 *
 * /admin/login is the platform owner's (Super Admin) alone; /login is for
 * every restaurant that bought a plan - its owner and staff. Same accounts
 * table and back office, but each door refuses the other's accounts, and
 * each sends its people back to it when they sign out.
 *
 * A restaurant's guests are a third kind of account on a different guard
 * (`customer`, /shop/{slug}/login); signing staff out must not sign them out,
 * nor the other way round.
 */
class SeparateLoginsTest extends TestCase
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

    private function owner(): User
    {
        $owner = User::create([
            'tenant_id' => $this->shop->tenant_id, 'name' => 'Kumar', 'email' => 'owner@kumar.test',
            'password' => 'owner-password-1', 'is_admin' => true,
        ]);
        $owner->shops()->attach($this->shop->id, ['is_default' => true]);
        $owner->givePermissionTo('dashboard.overview.view');

        return $owner;
    }

    private function guest(): Customer
    {
        return Customer::create([
            'shop_id' => $this->shop->id, 'code' => Customer::nextCode($this->shop->id),
            'name' => 'Asha Guest', 'mobile' => '9000000001', 'email' => 'asha@guest.test',
            'password' => Hash::make('guest-password-1'), 'type' => 'retail', 'is_active' => true,
        ]);
    }

    /* ---------------------------------------------------------- the pages */

    public function test_the_two_sign_in_pages_are_different(): void
    {
        $this->get('/login')->assertOk()
            ->assertSee('Sign in to your restaurant')
            ->assertSee(route('register'), false)
            ->assertSee('aria-label="Primary"', false)
            ->assertSee('aria-label="Explore"', false)
            ->assertSee(route('landing.how'), false)
            ->assertSee(route('landing.features'), false)
            ->assertSee(route('landing.billing'), false)
            ->assertSee(route('landing.faq'), false)
            ->assertSee('<meta name="robots" content="noindex,nofollow">', false)
            ->assertSee('<link rel="canonical" href="http://localhost/login">', false)
            ->assertDontSee('<select', false)
            ->assertDontSee('Platform admin sign in');

        // The platform's door does not advertise the restaurants' one, nor
        // the other way round: each is a page only its own people are sent to.
        $this->get('/superadmin/login')->assertOk()
            ->assertSee('Platform admin sign in')
            ->assertDontSee('Sign in to your restaurant')
            ->assertDontSee(route('register'), false);
    }

    public function test_the_public_pages_send_restaurants_to_their_door(): void
    {
        $this->get('/')->assertSee(route('login'), false)->assertDontSee(route('admin.login'), false);
        $this->get('/register')
            ->assertSee(route('login'), false)
            ->assertDontSee(route('admin.login'), false)
            ->assertDontSee('Super Admin login');
    }

    /* ---------------------------------------------------- who signs in where */

    public function test_a_restaurant_owner_signs_in_at_login(): void
    {
        $this->owner();

        $this->postJson('/login', ['email' => 'owner@kumar.test', 'password' => 'owner-password-1'])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertAuthenticated('web');
        $this->get(route('admin.dashboard'))->assertOk();
    }

    public function test_a_restaurant_owner_is_turned_away_from_the_platform_door(): void
    {
        $this->owner();

        $response = $this->postJson('/superadmin/login', ['email' => 'owner@kumar.test', 'password' => 'owner-password-1'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        // ...and is told where their door is.
        $this->assertStringContainsString(route('login'), (string) $response->json('errors.email.0'));
        $this->assertGuest('web');
    }

    public function test_the_super_admin_signs_in_at_admin_login_only(): void
    {
        $this->postJson('/login', ['email' => 'admin@erp.test', 'password' => 'password'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
        $this->assertGuest('web');

        $this->postJson('/superadmin/login', ['email' => 'admin@erp.test', 'password' => 'password'])->assertOk();
        $this->assertAuthenticated('web');
    }

    public function test_a_wrong_password_says_nothing_about_which_door(): void
    {
        $this->owner();

        $response = $this->postJson('/superadmin/login', ['email' => 'owner@kumar.test', 'password' => 'not-it'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password')
            ->assertJsonMissingValidationErrors('email');

        $this->assertStringNotContainsString(route('login'), (string) $response->json('errors.password.0'));
    }

    public function test_a_storefront_guest_cannot_use_either_door(): void
    {
        $this->guest();

        $this->postJson('/login', ['email' => 'asha@guest.test', 'password' => 'guest-password-1'])->assertStatus(422);
        $this->postJson('/superadmin/login', ['email' => 'asha@guest.test', 'password' => 'guest-password-1'])->assertStatus(422);

        $this->assertGuest('web');
    }

    public function test_a_signed_out_visit_to_the_back_office_goes_to_the_restaurant_door(): void
    {
        $this->get('/superadmin/products')->assertRedirect(route('login'));
    }

    /* ------------------------------------------------------------ logout */

    public function test_each_is_signed_out_back_to_their_own_door(): void
    {
        $this->owner();
        $this->postJson('/login', ['email' => 'owner@kumar.test', 'password' => 'owner-password-1'])->assertOk();
        $this->post(route('admin.logout'))->assertRedirect(route('login'));

        $this->app['auth']->forgetGuards();

        $this->postJson('/superadmin/login', ['email' => 'admin@erp.test', 'password' => 'password'])->assertOk();
        $this->post(route('admin.logout'))->assertRedirect(route('admin.login'));
    }

    /**
     * Staff and a storefront guest signed in for real in one browser session
     * - so these exercise the session itself rather than a guard's memory.
     */
    private function signBothIn(): void
    {
        $this->owner();
        $this->guest();

        $this->postJson('/login', ['email' => 'owner@kumar.test', 'password' => 'owner-password-1'])->assertOk();
        $this->postJson(route('shop.login.store', $this->shop), ['login' => '9000000001', 'password' => 'guest-password-1'])
            ->assertOk();

        $this->assertAuthenticated('web');
        $this->assertAuthenticated('customer');
    }

    public function test_signing_the_guest_out_keeps_the_staff_signed_in(): void
    {
        $this->signBothIn();

        $this->post(route('shop.logout', $this->shop))->assertRedirect(route('shop.home', $this->shop));
        $this->app['auth']->forgetGuards();

        $this->get(route('shop.account.edit', $this->shop))->assertRedirect(route('shop.login', $this->shop));
        $this->get(route('admin.dashboard'))->assertOk();
    }

    public function test_signing_the_staff_out_keeps_the_guest_signed_in(): void
    {
        $this->signBothIn();

        $this->post(route('admin.logout'))->assertRedirect(route('login'));
        $this->app['auth']->forgetGuards();

        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
        $this->get(route('shop.account.edit', $this->shop))->assertOk();
    }

    public function test_a_staff_session_does_not_open_a_guests_account(): void
    {
        $this->actingAs($this->owner());

        $this->get(route('shop.account.edit', $this->shop))->assertRedirect(route('shop.login', $this->shop));
    }

    /* ------------------------------------------------ storefront binding */

    public function test_a_storefront_url_finds_the_dish_of_that_restaurant(): void
    {
        $tenant = Tenant::create(['name' => 'Rival Foods', 'code' => 'RIVAL', 'slug' => 'rival-foods']);
        $rival = Shop::query()->create([
            'tenant_id' => $tenant->id, 'name' => 'Rival Kitchen', 'code' => 'RK', 'slug' => 'rival-kitchen', 'is_active' => true,
        ]);

        foreach ([[$this->shop, 'Our Paneer'], [$rival, 'Rival Paneer']] as [$shop, $name]) {
            $product = new Product([
                'name' => $name, 'slug' => 'paneer-tikka', 'sku' => 'PT-'.$shop->id,
                'selling_price' => 200, 'is_active' => true, 'is_published' => true,
            ]);
            $product->tenant_id = $shop->tenant_id;
            $product->save();
        }

        $this->get(route('shop.product', [$rival, 'paneer-tikka']))
            ->assertOk()
            ->assertSee('Rival Paneer')
            ->assertDontSee('Our Paneer');
    }
}
