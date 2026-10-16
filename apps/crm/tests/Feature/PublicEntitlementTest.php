<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\RestaurantTable;
use App\Models\Shop;
use App\Models\User;
use App\Support\CurrentShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Khana\Kit\Tenancy\Entitlements;
use Tests\Concerns\RunsTwoOutlets;
use Tests\Concerns\TalksToErp;
use Tests\TestCase;

/**
 * Guests cannot order from an outlet that has not got CRM.
 *
 * The back office has always been gated on what Accounts sold the outlet
 * (CrmEntitlementTest). These are the three doors a guest or an integration
 * comes in by - the QR sticker, the storefront and the API - in each state an
 * outlet's CRM can be in:
 *
 *   trial, active, grace   open
 *   suspended              closed to guests; the API may read, not order
 *   ended, never bought    closed
 *
 * and on an install that does not sign people in through Accounts, where
 * there is nothing to have been sold and everything stays open, as before.
 */
class PublicEntitlementTest extends TestCase
{
    use RefreshDatabase, RunsTwoOutlets, TalksToErp;

    private const CLOSED = 'Online ordering is not available at this restaurant right now';

    private Shop $shop;

    private Product $dish;

    private RestaurantTable $table;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');
        CurrentShop::forget();

        $this->throughAccounts();

        $this->shop = Shop::query()->orderBy('id')->firstOrFail();
        $this->dish = $this->dishNamed('Masala Dosa', 140, ['is_published' => true]);
        $this->table = $this->tableAt($this->shop, 'GF-11');
    }

    protected function tearDown(): void
    {
        CurrentShop::forget();

        parent::tearDown();
    }

    private function throughAccounts(bool $yes = true): void
    {
        config([
            'khana.sso.accounts_url' => $yes ? 'https://accounts.khana.test' : null,
            'khana.sso.client_id' => $yes ? 'crm-browser' : null,
        ]);
    }

    private function neverBought(Shop $shop): void
    {
        DB::table('khana_entitlements')->where('outlet_id', $shop->fresh()->ulid)->delete();
        app(Entitlements::class)->forget();
    }

    /** @param  list<string>  $abilities */
    private function apiAt(Shop $shop, array $abilities = ['menu:read', 'orders:write']): array
    {
        $user = $this->staffAt($shop, []);

        return ['Authorization' => 'Bearer '.$user->createToken('aggregator', $abilities)->plainTextToken];
    }

    private function apiOrder(array $headers): TestResponse
    {
        return $this->postJson('/api/v1/orders', [
            'order_type' => Order::DELIVERY,
            'items' => [['product_id' => $this->dish->id, 'quantity' => 1]],
        ], $headers);
    }

    /* ------------------------------------------------------------- open */

    public function test_an_outlet_on_trial_active_or_grace_takes_orders_everywhere(): void
    {
        foreach (['trial', 'active', 'grace'] as $state) {
            $this->entitle($this->shop, $state);

            $sitting = $this->seatAt($this->table);
            $this->get('/t')->assertOk()->assertSee('Masala Dosa');
            $this->post('/t/cart', ['product_id' => $this->dish->id])->assertSessionMissing('table_error');
            $this->assertGreaterThan(0, $sitting->cartItems()->count(), "a guest orders while {$state}");

            $this->get(route('shop.home', $this->shop))->assertOk();
            $this->get(route('shop.product', [$this->shop, $this->dish]))->assertOk()->assertSee('Add to cart');

            $api = $this->apiAt($this->shop);
            $this->getJson('/api/v1/menu', $api)->assertOk();
            $this->apiOrder($api)->assertStatus(201);
            $this->asNobody();
        }
    }

    /* ------------------------------------------------------------ closed */

    public function test_a_suspended_outlet_takes_no_orders_from_guests(): void
    {
        // A party already sat down before the outlet was suspended.
        $sitting = $this->seatAt($this->table);

        $this->entitle($this->shop, 'suspended');

        $this->get('/t/'.$this->table->activeQr->token)->assertForbidden()->assertSee(self::CLOSED);
        $this->get('/t')->assertForbidden()->assertSee(self::CLOSED)->assertDontSee('Masala Dosa');
        $this->get('/t/cart')->assertForbidden();

        $this->postJson('/t/cart', ['product_id' => $this->dish->id])
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', fn (string $message) => str_contains($message, self::CLOSED));
        $this->post('/t/order')->assertForbidden();

        $this->assertSame(0, $sitting->cartItems()->count());
        $this->assertSame(0, Order::allShops()->count());

        $this->get(route('shop.home', $this->shop))->assertForbidden()->assertSee(self::CLOSED);
        $this->get(route('shop.product', [$this->shop, $this->dish]))->assertForbidden();
        $this->post(route('shop.cart.add', $this->shop), ['product_id' => $this->dish->id])->assertForbidden();
    }

    /** The API belongs to staff, so it gets their rule: read, but not order. */
    public function test_a_suspended_outlets_api_may_read_but_not_order(): void
    {
        $this->entitle($this->shop, 'suspended');

        $api = $this->apiAt($this->shop);

        $this->getJson('/api/v1/menu', $api)->assertOk();

        $this->apiOrder($api)
            ->assertForbidden()
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'suspended'));

        $this->assertSame(0, Order::allShops()->count());
    }

    public function test_an_ended_outlet_is_closed_to_guests_and_to_its_api(): void
    {
        $this->entitle($this->shop, 'ended');

        $this->get('/t/'.$this->table->activeQr->token)->assertForbidden()->assertSee(self::CLOSED);
        $this->get(route('shop.catalog', $this->shop))->assertForbidden()->assertSee(self::CLOSED);

        $api = $this->apiAt($this->shop);

        $this->getJson('/api/v1/menu', $api)
            ->assertForbidden()
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'does not have Restro One CRM'));

        // A script that did not ask for JSON is still answered in JSON.
        $this->get('/api/v1/menu', $api)
            ->assertForbidden()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonStructure(['message']);

        $this->apiOrder($api)->assertForbidden();
        $this->assertSame(0, Order::allShops()->count());
    }

    public function test_an_outlet_that_never_bought_crm_is_closed(): void
    {
        $this->neverBought($this->shop);

        $this->get('/t/'.$this->table->activeQr->token)->assertForbidden()->assertSee(self::CLOSED);
        $this->get(route('shop.home', $this->shop))->assertForbidden();
        $this->getJson('/api/v1/menu', $this->apiAt($this->shop))->assertForbidden();
    }

    /* ----------------------------------------------- one branch, not all */

    public function test_one_lapsed_outlet_does_not_close_the_others(): void
    {
        $riverside = $this->anotherOutlet($this->shop);
        $riversideTable = $this->tableAt($riverside, 'RV-11');

        $this->entitle($this->shop, 'ended');

        $sitting = $this->seatAt($riversideTable);
        $this->post('/t/cart', ['product_id' => $this->dish->id])->assertSessionMissing('table_error');
        $this->assertSame(1, $sitting->cartItems()->count());

        $this->get(route('shop.home', $riverside))->assertOk();
        $this->apiOrder($this->apiAt($riverside))->assertStatus(201);

        $this->get('/t/'.$this->table->activeQr->token)->assertForbidden();
    }

    /* -------------------------------------------------- what is left open */

    public function test_the_pages_that_take_no_order_stay_open(): void
    {
        $this->seatAt($this->table);

        $this->entitle($this->shop, 'ended');

        // The rating after the meal, the home-screen manifest, the dead ends
        // - and the browser's word that money was paid, which is recorded
        // whatever the plan says.
        $this->get(route('table.feedback'))->assertOk();
        $this->get(route('table.manifest'))->assertOk();
        $this->get(route('table.expired'))->assertOk();
        $this->postJson(route('table.pay.confirm'), ['reference' => 'nothing-paid'])->assertNotFound();

        // And a sticker nobody issued is still just not found.
        $this->get('/t/'.str_repeat('A', 32))->assertNotFound();
    }

    /** The reason to look at a lapsed outlet's API is to find out why. */
    public function test_a_super_admins_token_is_never_refused(): void
    {
        $this->entitle($this->shop, 'ended');

        $admin = User::role(User::SUPER_ADMIN)->firstOrFail();

        $this->getJson('/api/v1/me', [
            'Authorization' => 'Bearer '.$admin->createToken('platform', ['menu:read'])->plainTextToken,
        ])->assertOk();
    }

    /* ------------------------------------------------- no Accounts at all */

    /**
     * An install that does not sign people in through Accounts has nothing
     * that could have sold an outlet CRM, and takes orders as it always did -
     * whatever is, or is not, in the entitlement table.
     */
    public function test_with_no_accounts_configured_everything_stays_open(): void
    {
        $this->throughAccounts(false);

        foreach (['ended', 'suspended', null] as $state) {
            $state === null ? $this->neverBought($this->shop) : $this->entitle($this->shop, $state);

            $sitting = $this->seatAt($this->table);
            $this->get('/t')->assertOk()->assertSee('Masala Dosa');
            $this->post('/t/cart', ['product_id' => $this->dish->id])->assertSessionMissing('table_error');
            $this->assertGreaterThan(0, $sitting->cartItems()->count());

            $this->get(route('shop.home', $this->shop))->assertOk();

            $api = $this->apiAt($this->shop);
            $this->getJson('/api/v1/menu', $api)->assertOk();
            $this->apiOrder($api)->assertStatus(201);
            $this->asNobody();
        }
    }
}
