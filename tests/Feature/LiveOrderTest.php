<?php

namespace Tests\Feature;

use App\Models\Floor;
use App\Models\Order;
use App\Models\Product;
use App\Models\RestaurantTable;
use App\Models\Shop;
use App\Models\TableSession;
use App\Models\User;
use App\Services\TableCartService;
use App\Services\TableOrderService;
use App\Services\TableQrService;
use App\Support\CurrentShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Live orders (§4, §5).
 *
 * The manager's screen, not the cook's. The thing worth testing is what it
 * holds that the kitchen display does not: a takeaway order nobody routed to
 * a station has no kitchen lines, never appears on the board, and is still
 * very much something somebody is standing at a counter waiting for.
 */
class LiveOrderTest extends TestCase
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

    private function dish(string $name = 'Butter Naan'): Product
    {
        $product = new Product([
            'name' => $name,
            'slug' => Product::uniqueSlug($name),
            'sku' => Product::generateSku($name),
            'selling_price' => 60,
            'is_active' => true,
            'is_made_to_order' => true,
        ]);

        $product->save();

        return $product;
    }

    private function table(string $code = 'GF-04'): RestaurantTable
    {
        $floor = Floor::query()->firstOrCreate(
            ['shop_id' => $this->shop()->id, 'code' => 'GF'],
            ['name' => 'Ground Floor', 'is_active' => true],
        );

        $table = new RestaurantTable([
            'shop_id' => $floor->shop_id,
            'floor_id' => $floor->id,
            'name' => substr($code, 3),
            'code' => $code,
            'capacity' => 4,
            'status' => RestaurantTable::AVAILABLE,
            'is_active' => true,
        ]);
        $table->save();

        app(TableQrService::class)->issue($table);

        return $table->fresh(['activeQr']);
    }

    private function dineIn(int $quantity = 2): Order
    {
        $table = $this->table();

        $this->get('/t/'.$table->activeQr->token)->assertRedirect(route('table.show'));

        $session = TableSession::allShops()
            ->live()
            ->where('restaurant_table_id', $table->id)
            ->firstOrFail();

        app(TableCartService::class)->add($session, $this->dish(), null, $quantity);

        return app(TableOrderService::class)->place($session);
    }

    /**
     * A counter order with no kitchen lines at all - the case this screen
     * exists to cover and the board cannot.
     */
    private function takeaway(): Order
    {
        $order = new Order([
            'shop_id' => $this->shop()->id,
            'order_type' => Order::TAKEAWAY,
            'order_number' => 'TA-0001',
            'status' => Order::PENDING,
            'payment_status' => Order::PAYMENT_PENDING,
            'guest_name' => 'Counter walk-in',
            'subtotal' => 240,
            'grand_total' => 240,
            'placed_at' => now(),
        ]);

        $order->save();

        return $order;
    }

    private function manager(array $permissions = []): User
    {
        $user = User::query()->firstWhere('email', 'manager@example.test');

        if ($user === null) {
            $user = User::create([
                'tenant_id' => $this->shop()->tenant_id,
                'name' => 'Duty Manager',
                'email' => 'manager@example.test',
                'password' => 'manager-password-1',
                'is_admin' => true,
            ]);

            $user->shops()->syncWithoutDetaching([$this->shop()->id => ['is_default' => true]]);
            $user->forceFill([
                'current_shop_id' => $this->shop()->id,
                'all_shops_view' => false,
            ])->save();
        }

        $user->givePermissionTo(array_merge(['dashboard.overview.view'], $permissions));

        return $user->fresh();
    }

    /* ------------------------------------------------------------ the list */

    public function test_every_channel_in_flight_is_on_one_screen(): void
    {
        $dine = $this->dineIn();
        $counter = $this->takeaway();

        $this->actingAs($this->manager(['sales.orders.view']))
            ->get('/admin/live-orders')
            ->assertOk()
            ->assertSee($dine->order_number)
            ->assertSee($counter->order_number)
            ->assertSee('Dine-in')
            ->assertSee('Takeaway');
    }

    /**
     * The reason this is not the kitchen display.
     *
     * A takeaway with no kitchen lines is invisible to the board and still
     * has somebody standing at a counter waiting for it.
     */
    public function test_an_order_with_no_kitchen_lines_still_appears(): void
    {
        $counter = $this->takeaway();

        $this->assertSame(0, $counter->items()->whereNotNull('kitchen_status')->count());

        $this->actingAs($this->manager(['sales.orders.view']))
            ->get('/admin/live-orders')
            ->assertOk()
            ->assertSee($counter->order_number);
    }

    public function test_a_finished_order_leaves_the_screen(): void
    {
        $order = $this->dineIn();

        $this->actingAs($this->manager(['sales.orders.view']))
            ->get('/admin/live-orders')
            ->assertOk()
            ->assertSee($order->order_number);

        $order->forceFill(['status' => Order::SERVED])->save();

        $this->actingAs($this->manager(['sales.orders.view']))
            ->get('/admin/live-orders')
            ->assertOk()
            ->assertDontSee($order->order_number);
    }

    public function test_a_cancelled_order_leaves_the_screen_but_is_counted(): void
    {
        $order = $this->dineIn();

        $order->forceFill(['status' => Order::CANCELLED, 'cancelled_at' => now()])->save();

        $this->actingAs($this->manager(['sales.orders.view']))
            ->get('/admin/live-orders')
            ->assertOk()
            ->assertDontSee($order->order_number)
            // "Three cancelled since this morning" is exactly what a manager
            // wants to notice.
            ->assertSee('Cancelled today');
    }

    public function test_the_channel_filter_narrows_it(): void
    {
        $dine = $this->dineIn();
        $counter = $this->takeaway();

        $this->actingAs($this->manager(['sales.orders.view']))
            ->get('/admin/live-orders?type=takeaway')
            ->assertOk()
            ->assertSee($counter->order_number)
            ->assertDontSee($dine->order_number);
    }

    public function test_the_oldest_order_is_first(): void
    {
        $old = $this->takeaway();
        $old->forceFill(['order_number' => 'TA-OLD', 'placed_at' => now()->subHour()])->save();

        $new = $this->dineIn();

        $html = $this->actingAs($this->manager(['sales.orders.view']))
            ->get('/admin/live-orders')
            ->assertOk()
            ->getContent();

        $this->assertLessThan(
            strpos($html, $new->order_number),
            strpos($html, 'TA-OLD'),
            'A manager scans for the exception, so the longest wait has to be at the top.',
        );
    }

    public function test_the_fragment_is_what_the_screen_polls(): void
    {
        $order = $this->dineIn();

        $this->actingAs($this->manager(['sales.orders.view']))
            ->withHeader('X-Fragment', '1')
            ->get('/admin/live-orders')
            ->assertOk()
            ->assertSee($order->order_number)
            ->assertDontSee('<!DOCTYPE', false);
    }

    public function test_the_screen_is_closed_without_the_right(): void
    {
        $this->actingAs($this->manager())->get('/admin/live-orders')->assertForbidden();
    }
}
