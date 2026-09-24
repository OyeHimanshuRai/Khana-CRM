<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Support\CurrentShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The API, for a captain's app or a delivery aggregator (§2, §15, §21).
 *
 * Three properties matter more than the endpoints themselves:
 *
 *   1. Abilities are the security model. A token minted to read the menu must
 *      not be able to close a dish or cancel an order, whatever it sends.
 *
 *   2. Prices come from the menu, never from the caller. An integrator that
 *      could name its own price could bill a restaurant's customer a rupee
 *      for a biryani.
 *
 *   3. Retries are safe. Aggregators retry and networks drop; the same order
 *      posted twice must not put two tickets in a kitchen.
 */
class ApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');

        $this->seed();

        CurrentShop::forget();
    }

    protected function tearDown(): void
    {
        CurrentShop::forget();

        parent::tearDown();
    }

    /* ---------------------------------------------------------- fixtures */

    private function shop(): Shop
    {
        return Shop::query()->orderBy('id')->firstOrFail();
    }

    private function integrator(): User
    {
        $user = User::factory()->create([
            'tenant_id' => $this->shop()->tenant_id,
            'is_admin' => true,
        ]);

        $user->shops()->syncWithoutDetaching([$this->shop()->id => ['is_default' => true]]);
        $user->forceFill([
            'current_shop_id' => $this->shop()->id,
            'all_shops_view' => false,
        ])->save();

        return $user->fresh();
    }

    /**
     * A token with the given abilities, and the headers to use it.
     *
     * @param  array<int, string>  $abilities
     * @return array<string, string>
     */
    private function tokenHeaders(array $abilities, ?User $user = null): array
    {
        $user ??= $this->integrator();

        $token = $user->createToken('test integration', $abilities)->plainTextToken;

        return [
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ];
    }

    private function dish(string $name = 'Dal Makhani', float $price = 280): Product
    {
        return Product::create([
            'name' => $name,
            'slug' => Product::uniqueSlug($name),
            'sku' => Product::generateSku($name),
            'selling_price' => $price,
            'is_active' => true,
            'is_made_to_order' => true,
        ]);
    }

    /* ------------------------------------------------------------- doors */

    public function test_a_call_with_no_token_is_told_what_to_send(): void
    {
        $response = $this->getJson('/api/v1/me');

        $response->assertStatus(401);
        // "Your session has expired" means nothing to a script that never had
        // a session.
        $this->assertStringContainsString('Authorization: Bearer', $response->json('message'));
    }

    public function test_me_answers_with_the_branch_and_the_abilities(): void
    {
        $headers = $this->tokenHeaders(['menu:read']);

        $response = $this->getJson('/api/v1/me', $headers);

        $response->assertOk();
        $this->assertSame($this->shop()->name, $response->json('shop'));
        $this->assertSame(['menu:read'], $response->json('abilities'));
    }

    public function test_an_ability_a_token_does_not_hold_is_refused(): void
    {
        $dish = $this->dish();
        $headers = $this->tokenHeaders(['menu:read']);

        /*
         | A real dish, not a made-up id: route-model binding runs before the
         | ability check, so a missing id answers 404 and the test would pass
         | without ever reaching the thing it is meant to prove.
         */
        $this->putJson("/api/v1/menu/{$dish->id}/availability", ['is_sold_out' => true], $headers)
            ->assertStatus(403);

        // And the dish is untouched.
        $this->assertFalse((bool) $dish->fresh()->is_sold_out);

        $this->postJson('/api/v1/orders', [], $headers)->assertStatus(403);
        $this->getJson('/api/v1/tables', $headers)->assertStatus(403);
    }

    /* -------------------------------------------------------------- menu */

    public function test_the_menu_is_returned_without_the_restaurants_own_business(): void
    {
        $this->dish();

        $response = $this->getJson('/api/v1/menu', $this->tokenHeaders(['menu:read']));

        $response->assertOk();

        $row = $response->json('data.0');

        $this->assertSame('Dal Makhani', $row['name']);
        $this->assertEqualsWithDelta(280, $row['price'], 0.01);

        /*
         | Cost, margin, stock and supplier are the restaurant's own business.
         | An API that returned them because it was convenient is a leak
         | nobody notices until a competitor is reading it.
         */
        $this->assertArrayNotHasKey('average_cost', $row);
        $this->assertArrayNotHasKey('on_hand', $row);
        $this->assertArrayNotHasKey('cost_price', $row);
    }

    public function test_a_dish_can_be_closed_and_reopened_with_the_write_ability(): void
    {
        $dish = $this->dish();
        $headers = $this->tokenHeaders(['menu:read', 'menu:write']);

        $this->putJson("/api/v1/menu/{$dish->id}/availability", ['is_sold_out' => true], $headers)
            ->assertOk()
            ->assertJsonPath('data.is_sold_out', true);

        $this->assertTrue((bool) $dish->fresh()->is_sold_out);
    }

    public function test_the_menu_can_be_synced_incrementally(): void
    {
        $old = $this->dish('Old dish');
        $old->forceFill(['updated_at' => now()->subDays(5)])->save();

        $this->dish('New dish');

        $response = $this->getJson(
            '/api/v1/menu?since='.now()->subDay()->toDateString(),
            $this->tokenHeaders(['menu:read']),
        );

        // An aggregator holding a thousand dishes should not pull all of them
        // every five minutes.
        $names = collect($response->json('data'))->pluck('name')->all();

        $this->assertContains('New dish', $names);
        $this->assertNotContains('Old dish', $names);
    }

    /* ------------------------------------------------------------ orders */

    public function test_an_order_is_priced_from_the_menu_not_from_the_payload(): void
    {
        $dish = $this->dish('Biryani', 400);
        $headers = $this->tokenHeaders(['orders:write', 'orders:read']);

        $response = $this->postJson('/api/v1/orders', [
            'order_type' => Order::DELIVERY,
            'guest_name' => 'Aggregator guest',
            'items' => [[
                'product_id' => $dish->id,
                'quantity' => 2,
                // Whatever a caller sends here is ignored.
                'unit_price' => 1,
                'price' => 1,
            ]],
        ], $headers);

        $response->assertStatus(201);

        // Two at four hundred, from the menu.
        $this->assertEqualsWithDelta(800, $response->json('data.total'), 0.01);
    }

    public function test_the_same_reference_posted_twice_makes_one_order(): void
    {
        $dish = $this->dish();
        $headers = $this->tokenHeaders(['orders:write', 'orders:read']);

        $payload = [
            'order_type' => Order::DELIVERY,
            'external_reference' => 'SWIGGY-7781',
            'items' => [['product_id' => $dish->id, 'quantity' => 1]],
        ];

        $first = $this->postJson('/api/v1/orders', $payload, $headers);
        $first->assertStatus(201);

        // Aggregators retry and networks drop. A duplicate ticket in a kitchen
        // is a dish that gets made twice.
        $second = $this->postJson('/api/v1/orders', $payload, $headers);

        $second->assertOk();
        $this->assertTrue($second->json('duplicate'));
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, Order::allShops()->count());
    }

    public function test_a_sold_out_dish_is_refused_with_its_name(): void
    {
        $dish = $this->dish('Gajar Halwa');
        $dish->forceFill(['is_sold_out' => true])->save();

        $response = $this->postJson('/api/v1/orders', [
            'order_type' => Order::DELIVERY,
            'items' => [['product_id' => $dish->id, 'quantity' => 1]],
        ], $this->tokenHeaders(['orders:write']));

        $response->assertStatus(409);
        $this->assertStringContainsString('Gajar Halwa', $response->json('message'));
    }

    public function test_a_dish_that_is_not_on_the_menu_is_refused(): void
    {
        $this->postJson('/api/v1/orders', [
            'order_type' => Order::DELIVERY,
            'items' => [['product_id' => 99999, 'quantity' => 1]],
        ], $this->tokenHeaders(['orders:write']))->assertStatus(422);
    }

    public function test_an_api_order_reaches_the_kitchen_like_any_other(): void
    {
        $dish = $this->dish();

        $this->postJson('/api/v1/orders', [
            'order_type' => Order::DELIVERY,
            'items' => [['product_id' => $dish->id, 'quantity' => 1]],
        ], $this->tokenHeaders(['orders:write']))->assertStatus(201);

        $order = Order::allShops()->firstOrFail();

        // The ticket is on the board, and its number says where it came from.
        $this->assertStringStartsWith('API-', $order->order_number);
        $this->assertSame(Order::PENDING, $order->items()->first()->kitchen_status);
    }

    public function test_an_order_carries_its_timeline(): void
    {
        $dish = $this->dish();
        $headers = $this->tokenHeaders(['orders:write', 'orders:read']);

        $created = $this->postJson('/api/v1/orders', [
            'order_type' => Order::DELIVERY,
            'items' => [['product_id' => $dish->id, 'quantity' => 1]],
        ], $headers);

        $response = $this->getJson('/api/v1/orders/'.$created->json('data.id'), $headers);

        $response->assertOk();
        $this->assertNotEmpty($response->json('data.timeline'));
    }

    public function test_a_status_change_is_idempotent(): void
    {
        $dish = $this->dish();
        $headers = $this->tokenHeaders(['orders:write', 'orders:read']);

        $created = $this->postJson('/api/v1/orders', [
            'order_type' => Order::DELIVERY,
            'items' => [['product_id' => $dish->id, 'quantity' => 1]],
        ], $headers);

        $id = $created->json('data.id');

        $this->putJson("/api/v1/orders/{$id}/status", ['status' => Order::CANCELLED, 'reason' => 'guest cancelled'], $headers)
            ->assertOk();

        // A retried call is a no-op, not an error.
        $this->putJson("/api/v1/orders/{$id}/status", ['status' => Order::CANCELLED], $headers)
            ->assertOk();

        $this->assertSame(Order::CANCELLED, Order::allShops()->findOrFail($id)->status);
    }

    public function test_an_integrator_cannot_set_any_status_it_likes(): void
    {
        $dish = $this->dish();
        $headers = $this->tokenHeaders(['orders:write', 'orders:read']);

        $created = $this->postJson('/api/v1/orders', [
            'order_type' => Order::DELIVERY,
            'items' => [['product_id' => $dish->id, 'quantity' => 1]],
        ], $headers);

        // An API that could set any status would let an aggregator tell a
        // restaurant its own food was ready.
        $this->putJson('/api/v1/orders/'.$created->json('data.id').'/status', [
            'status' => 'ready',
        ], $headers)->assertStatus(422);
    }

    /* ------------------------------------------------------------ tables */

    public function test_the_floor_is_readable_with_the_right_ability(): void
    {
        $this->getJson('/api/v1/tables', $this->tokenHeaders(['tables:read']))
            ->assertOk()
            ->assertJsonStructure(['data']);
    }

    /* ------------------------------------------------------- the tokens */

    public function test_a_token_is_shown_once_and_never_again(): void
    {
        $user = $this->integrator();
        $user->givePermissionTo([
            'dashboard.overview.view',
            'settings.api_tokens.view',
            'settings.api_tokens.create',
        ]);

        $this->actingAs($user->fresh());
        CurrentShop::forget();

        $response = $this->postJson(route('admin.api-tokens.store'), [
            'name' => 'Swiggy',
            'abilities' => ['menu:read', 'orders:write'],
        ]);

        $response->assertOk();
        $this->assertNotEmpty($response->json('data.token'));

        // Sanctum stores a hash, so the list can never show it again - which
        // is why the screen says to copy it now.
        $this->get(route('admin.api-tokens.index'))
            ->assertOk()
            ->assertDontSee($response->json('data.token'));
    }

    public function test_an_ability_nobody_offers_cannot_be_minted(): void
    {
        $user = $this->integrator();
        $user->givePermissionTo(['dashboard.overview.view', 'settings.api_tokens.create']);

        $this->actingAs($user->fresh());
        CurrentShop::forget();

        // A closed list, so a typo cannot mint something with powers no route
        // expects.
        $this->postJson(route('admin.api-tokens.store'), [
            'name' => 'Too much',
            'abilities' => ['*'],
        ])->assertStatus(422);
    }
}
