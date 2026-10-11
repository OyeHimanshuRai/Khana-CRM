<?php

namespace Tests\Feature;

use App\Events\ShopBoardChanged;
use App\Models\Floor;
use App\Models\Order;
use App\Models\Product;
use App\Models\RestaurantTable;
use App\Models\Shop;
use App\Models\Tenant;
use App\Models\User;
use App\Services\KitchenService;
use App\Services\TableQrService;
use App\Support\CurrentShop;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Pushing changes to screens that are already open (§8, §9, §14).
 *
 * Two things are worth testing here and they are not the obvious one.
 *
 * The obvious one - "does a message arrive" - is Reverb's job and needs a
 * running socket server to mean anything.
 *
 * What this file tests instead is:
 *
 *   1. Who may listen. A channel is the one place in this system where the
 *      route middleware that normally enforces shop scoping does not run, so
 *      the authorisation callback is the whole defence. Getting it wrong
 *      means one restaurant's tickets arriving live on another's screen,
 *      silently, with nothing in a log.
 *
 *   2. That none of it is load-bearing. With broadcasting off - the default -
 *      every path must behave exactly as it did before any of this existed.
 */
class RealtimeTest extends TestCase
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

    private function otherShop(): Shop
    {
        return Shop::query()->create([
            'tenant_id' => $this->shop()->tenant_id,
            'name' => 'Second Branch',
            'code' => 'SB',
            'slug' => 'second-branch',
            'is_active' => true,
        ]);
    }

    private function staff(?Shop $shop = null): User
    {
        $shop ??= $this->shop();

        $user = User::factory()->create([
            'tenant_id' => $shop->tenant_id,
            'is_admin' => true,
        ]);

        $user->shops()->syncWithoutDetaching([$shop->id => ['is_default' => true]]);
        $user->forceFill([
            'current_shop_id' => $shop->id,
            'all_shops_view' => false,
        ])->save();

        return $user->fresh();
    }

    private function table(): RestaurantTable
    {
        $floor = Floor::create([
            'shop_id' => $this->shop()->id,
            'name' => 'Ground Floor',
            'code' => 'GF',
            'is_active' => true,
        ]);

        $table = RestaurantTable::create([
            'shop_id' => $floor->shop_id,
            'floor_id' => $floor->id,
            'name' => '4',
            'code' => 'GF-04',
            'capacity' => 4,
            'status' => RestaurantTable::AVAILABLE,
            'is_active' => true,
        ]);

        app(TableQrService::class)->issue($table);

        return $table->fresh(['activeQr']);
    }

    private function dish(): Product
    {
        return Product::create([
            'name' => 'Dal Makhani',
            'slug' => Product::uniqueSlug('Dal Makhani'),
            'sku' => Product::generateSku('Dal Makhani'),
            'selling_price' => 280,
            'is_active' => true,
        ]);
    }

    /* ----------------------------------------------------- the channel */

    public function test_a_channel_only_admits_somebody_who_can_reach_that_branch(): void
    {
        $mine = $this->shop();
        $theirs = $this->otherShop();

        $user = $this->staff($mine);
        $this->actingAs($user);
        CurrentShop::forget();

        $callback = $this->channelCallback('shop.{shopId}');

        $this->assertTrue($callback($user, $mine->id));

        // The failure this guards against is one restaurant's tickets, order
        // values and guest names arriving live on another's screen.
        $this->assertFalse((bool) $callback($user, $theirs->id));
    }

    public function test_a_channel_refuses_a_branch_in_another_company_outright(): void
    {
        $other = Tenant::create([
            'name' => 'Rival Foods', 'code' => 'RIVAL', 'slug' => 'rival-foods',
        ]);

        $theirShop = Shop::query()->create([
            'tenant_id' => $other->id,
            'name' => 'Rival Kitchen',
            'code' => 'RK',
            'slug' => 'rival-kitchen',
            'is_active' => true,
        ]);

        $user = $this->staff();
        $this->actingAs($user);
        CurrentShop::forget();

        $callback = $this->channelCallback('shop.{shopId}');

        $this->assertFalse((bool) $callback($user, $theirShop->id));
    }

    public function test_the_auth_endpoint_is_closed_to_anybody_not_signed_in(): void
    {
        /*
         | Configured for real before asking.
         |
         | Under the default `null` driver this endpoint is a no-op that
         | answers an empty 200 - it hands out no signature, so nothing can
         | subscribe with it. Testing that would prove nothing about the
         | configuration a restaurant actually runs sockets on, so the test
         | switches the driver on first.
         */
        $this->useReverb();

        $this->post('/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => 'private-shop.'.$this->shop()->id,
        ])->assertStatus(403);
    }

    public function test_the_auth_endpoint_refuses_a_branch_the_caller_cannot_reach(): void
    {
        $this->useReverb();

        $theirs = $this->otherShop();

        $this->actingAs($this->staff($this->shop()));
        CurrentShop::forget();

        // A signed-in person, a real channel, somebody else's branch. This is
        // the request an attacker actually makes.
        $this->post('/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => 'private-shop.'.$theirs->id,
        ])->assertStatus(403);
    }

    public function test_the_auth_endpoint_signs_for_a_branch_the_caller_can_reach(): void
    {
        $this->useReverb();

        $mine = $this->shop();

        $this->actingAs($this->staff($mine));
        CurrentShop::forget();

        $response = $this->post('/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => 'private-shop.'.$mine->id,
        ]);

        $response->assertOk();

        // The signature is what the browser sends back to Reverb to join.
        $this->assertNotEmpty($response->json('auth'));
    }

    /**
     * Switch broadcasting on, the way a restaurant running sockets has it.
     *
     * The re-require is not decoration. Channels are registered against
     * whichever broadcaster was current when the application booted - the
     * `null` one, under the default config - and changing the driver
     * afterwards builds a fresh broadcaster that knows about no channels at
     * all. Without this line every one of these tests would get its 403 from
     * "no channel matched" rather than from the authorisation rule, and would
     * pass while proving nothing.
     */
    private function useReverb(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
        ]);

        require base_path('routes/channels.php');
    }

    /* ------------------------------------------------------- the event */

    public function test_the_event_goes_to_one_branchs_private_channel(): void
    {
        $event = new ShopBoardChanged(7, 'order.placed');

        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame('private-shop.7', (string) $channels[0]);
        $this->assertSame('board.changed', $event->broadcastAs());
    }

    public function test_the_payload_carries_nothing_worth_leaking(): void
    {
        $payload = (new ShopBoardChanged(7, 'kitchen.ticket'))->broadcastWith();

        // The screen re-fetches its own fragment through the normal,
        // authorised route. A payload with no order value, no guest name and
        // no dish in it cannot leak any of them.
        $this->assertSame(['reason', 'at'], array_keys($payload));
        $this->assertSame('kitchen.ticket', $payload['reason']);
    }

    /* ------------------------------------------------ the dispatch points */

    public function test_a_guest_order_nudges_the_kitchen_screen(): void
    {
        Event::fake([ShopBoardChanged::class]);

        $table = $this->table();

        $this->get('/t/'.$table->activeQr->token);
        $this->post('/t/cart', ['product_id' => $this->dish()->id]);
        $this->post('/t/order');

        Event::assertDispatched(
            ShopBoardChanged::class,
            fn (ShopBoardChanged $e) => $e->shopId === $this->shop()->id && $e->reason === 'order.placed',
        );
    }

    public function test_bumping_a_ticket_nudges_the_kitchen_screen(): void
    {
        $table = $this->table();

        $this->get('/t/'.$table->activeQr->token);
        $this->post('/t/cart', ['product_id' => $this->dish()->id]);
        $this->post('/t/order');

        $order = Order::allShops()->latest('id')->firstOrFail();

        Event::fake([ShopBoardChanged::class]);

        app(KitchenService::class)->bumpTicket($order);

        Event::assertDispatched(
            ShopBoardChanged::class,
            fn (ShopBoardChanged $e) => $e->shopId === $this->shop()->id && $e->reason === 'kitchen.ticket',
        );
    }

    /* ------------------------------------------------- not load-bearing */

    public function test_everything_works_with_broadcasting_switched_off(): void
    {
        // The default, and the only possible configuration on shared hosting
        // that forbids a long-running process.
        config(['broadcasting.default' => 'null']);

        $table = $this->table();

        $this->get('/t/'.$table->activeQr->token);
        $this->post('/t/cart', ['product_id' => $this->dish()->id]);
        $this->post('/t/order')->assertRedirect(route('table.orders'));

        $order = Order::allShops()->latest('id')->firstOrFail();

        app(KitchenService::class)->bumpTicket($order);

        $this->assertSame(1, Order::allShops()->count());
    }

    public function test_the_kitchen_screen_renders_no_socket_config_by_default(): void
    {
        config(['broadcasting.default' => 'null']);

        $user = $this->staff();
        $user->givePermissionTo(['dashboard.overview.view', 'kitchen.tickets.view']);

        $this->actingAs($user->fresh());
        CurrentShop::forget();

        // The client script returns immediately without this block, so its
        // absence is what guarantees "poll only".
        $this->get(route('admin.kitchen.index'))
            ->assertOk()
            ->assertDontSee('realtime-config');
    }

    /* ----------------------------------------------------------- helper */

    /**
     * The authorisation callback registered for a channel.
     *
     * Reached through the broadcaster rather than by re-declaring the rule,
     * so this tests what routes/channels.php actually registered.
     */
    private function channelCallback(string $name): callable
    {
        $channels = Broadcast::getFacadeRoot()->getChannels();

        $this->assertArrayHasKey($name, $channels, "no channel registered as {$name}");

        return $channels[$name];
    }
}
