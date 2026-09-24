<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Floor;
use App\Models\KitchenStation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\RestaurantTable;
use App\Models\Shop;
use App\Models\TableSession;
use App\Models\User;
use App\Services\KitchenRouter;
use App\Services\KitchenService;
use App\Services\TableCartService;
use App\Services\TableOrderService;
use App\Services\ReportService;
use App\Services\TableQrService;
use App\Support\CurrentShop;
use App\Support\ReportFilters;
use Illuminate\Http\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Tests\TestCase;

/**
 * The kitchen display and the routing behind it (§9).
 *
 * Two properties carry most of the weight here, and both are about a ticket
 * that lives in two rooms at once:
 *
 *   1. A station only ever moves its own lines. A bar that could mark the
 *      tandoor's kebab ready is a runner taking half a table's food out.
 *
 *   2. The ticket's status is the least-advanced of its lines. Drinks poured
 *      and a kebab still on is a ticket that is Preparing, and it turns Ready
 *      only when the last station lets go of it.
 *
 * The third is routing itself: a dish overrides its section, a section
 * overrides its parent, and anything nobody filed still gets cooked.
 */
class KitchenTest extends TestCase
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

    private function station(string $name, string $code, array $overrides = []): KitchenStation
    {
        $station = new KitchenStation(array_merge([
            'shop_id' => $this->shop()->id,
            'name' => $name,
            'code' => $code,
            'prep_minutes' => 15,
            'is_active' => true,
        ], $overrides));

        $station->save();

        if ($overrides['is_default'] ?? false) {
            $station->makeDefault();
        }

        return $station->fresh();
    }

    private function dish(array $overrides = []): Product
    {
        $name = $overrides['name'] ?? 'Dal Makhani';

        $product = new Product(array_merge([
            'name' => $name,
            'slug' => Product::uniqueSlug($name),
            'sku' => Product::generateSku($name),
            'selling_price' => 280,
            'is_active' => true,
        ], $overrides));

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

    /** Scan the sticker, the way a phone does. */
    private function seat(RestaurantTable $table): TableSession
    {
        $this->get('/t/'.$table->activeQr->token)->assertRedirect(route('table.show'));

        return TableSession::allShops()->live()->firstOrFail();
    }

    /**
     * A table that has sent one ticket holding the dishes given.
     *
     * @param  array<int, Product>  $dishes
     */
    private function ticket(array $dishes, string $code = 'GF-04'): Order
    {
        $session = $this->seat($this->table($code));

        $cart = app(TableCartService::class);

        foreach ($dishes as $dish) {
            $cart->add($session, $dish);
        }

        return app(TableOrderService::class)->place($session);
    }

    /* ------------------------------------------------------------ actors */

    /**
     * Somebody who works the line: the board and the bump, nothing else.
     *
     * Deliberately without `recall`, so the tests that need it have to say so
     * and the ones that do not prove it is actually withheld.
     */
    private function cook(array $extra = []): User
    {
        $user = User::query()->firstWhere('email', 'cook@example.test');

        if ($user === null) {
            $user = User::create([
                'tenant_id' => $this->shop()->tenant_id,
                'name' => 'Line Cook',
                'email' => 'cook@example.test',
                'password' => 'cook-password-1',
                'is_admin' => true,
            ]);

            $user->shops()->syncWithoutDetaching([$this->shop()->id => ['is_default' => true]]);
            $user->forceFill([
                'current_shop_id' => $this->shop()->id,
                'all_shops_view' => false,
            ])->save();
        }

        $user->givePermissionTo(array_merge([
            'dashboard.overview.view',
            'kitchen.tickets.view', 'kitchen.tickets.advance', 'kitchen.tickets.print',
            'kitchen.stations.view',
        ], $extra));

        return $user->fresh();
    }

    /* ----------------------------------------------------------- routing */

    public function test_a_dish_follows_its_category(): void
    {
        $tandoor = $this->station('Tandoor', 'TAN');
        $this->station('Main Kitchen', 'MK', ['is_default' => true]);

        $breads = Category::create([
            'name' => 'Indian Breads',
            'slug' => 'indian-breads',
            'kitchen_station_id' => $tandoor->id,
            'is_active' => true,
        ]);

        $naan = $this->dish(['name' => 'Butter Naan', 'category_id' => $breads->id]);

        $this->assertSame(
            $tandoor->id,
            (new KitchenRouter())->stationIdFor($naan, $this->shop()->id),
        );
    }

    public function test_a_sub_section_inherits_its_parent(): void
    {
        $bar = $this->station('Bar', 'BAR');
        $this->station('Main Kitchen', 'MK', ['is_default' => true]);

        $drinks = Category::create([
            'name' => 'Beverages',
            'slug' => 'beverages',
            'kitchen_station_id' => $bar->id,
            'is_active' => true,
        ]);

        // Nothing set on the child at all - this is the whole point of the
        // inheritance: "Beverages -> Bar" covers Hot and Cold without two
        // more rows for somebody to forget.
        $cold = Category::create([
            'name' => 'Cold',
            'slug' => 'cold',
            'parent_id' => $drinks->id,
            'is_active' => true,
        ]);

        $soda = $this->dish(['name' => 'Fresh Lime Soda', 'category_id' => $cold->id]);

        $router = new KitchenRouter();

        $this->assertSame($bar->id, $router->stationIdFor($soda, $this->shop()->id));
        $this->assertSame('Bar — from Beverages', $router->explain($soda, $this->shop()->id));
    }

    public function test_a_dish_overrides_its_section(): void
    {
        $tandoor = $this->station('Tandoor', 'TAN');
        $main = $this->station('Main Kitchen', 'MK', ['is_default' => true]);

        $starters = Category::create([
            'name' => 'Starters',
            'slug' => 'starters',
            'kitchen_station_id' => $main->id,
            'is_active' => true,
        ]);

        $tikka = $this->dish([
            'name' => 'Paneer Tikka',
            'category_id' => $starters->id,
            'kitchen_station_id' => $tandoor->id,
        ]);

        $this->assertSame(
            $tandoor->id,
            (new KitchenRouter())->stationIdFor($tikka, $this->shop()->id),
        );
    }

    /**
     * The failure this exists to prevent: a dish added on a Friday by
     * somebody who did not think about stations, cooked by nobody.
     */
    public function test_a_dish_nobody_routed_falls_to_the_default(): void
    {
        $this->station('Tandoor', 'TAN');
        $main = $this->station('Main Kitchen', 'MK', ['is_default' => true]);

        $orphan = $this->dish(['name' => 'Gajar Halwa']);

        $router = new KitchenRouter();

        $this->assertSame($main->id, $router->stationIdFor($orphan, $this->shop()->id));
        $this->assertSame('Main Kitchen — default', $router->explain($orphan, $this->shop()->id));
    }

    /** A one-room kitchen never set any of this up, and still cooks. */
    public function test_a_branch_with_no_stations_routes_to_nothing(): void
    {
        $dish = $this->dish();

        $this->assertNull((new KitchenRouter())->stationIdFor($dish, $this->shop()->id));
    }

    /**
     * A station switched off for the season stops taking new work, and the
     * dishes pointed at it fall through rather than vanishing.
     */
    public function test_a_closed_station_hands_its_dishes_to_the_default(): void
    {
        $bakery = $this->station('Bakery', 'BKY');
        $main = $this->station('Main Kitchen', 'MK', ['is_default' => true]);

        $dish = $this->dish(['name' => 'Chocolate Tart', 'kitchen_station_id' => $bakery->id]);

        $bakery->forceFill(['is_active' => false])->save();

        $this->assertSame(
            $main->id,
            (new KitchenRouter())->stationIdFor($dish->fresh(), $this->shop()->id),
        );
    }

    /* --------------------------------------------------------- placement */

    public function test_placing_an_order_stamps_the_station_on_every_line(): void
    {
        $bar = $this->station('Bar', 'BAR');
        $main = $this->station('Main Kitchen', 'MK', ['is_default' => true]);

        $drinks = Category::create([
            'name' => 'Beverages', 'slug' => 'beverages',
            'kitchen_station_id' => $bar->id, 'is_active' => true,
        ]);

        $order = $this->ticket([
            $this->dish(['name' => 'Fresh Lime Soda', 'category_id' => $drinks->id]),
            $this->dish(['name' => 'Dal Makhani']),
        ]);

        $lines = $order->items()->orderBy('id')->get();

        $this->assertSame($bar->id, (int) $lines[0]->kitchen_station_id);
        $this->assertSame($main->id, (int) $lines[1]->kitchen_station_id);

        // On the board the moment it is sent - this is what puts it in the
        // feed at all.
        $this->assertSame(Order::PENDING, $lines[0]->kitchen_status);
        $this->assertSame(Order::PENDING, $lines[1]->kitchen_status);
    }

    /**
     * The station is a snapshot, like the dish's name and its price.
     *
     * Re-filing a dish at nine must not move a ticket already on a pass.
     */
    public function test_re_routing_a_dish_does_not_move_a_ticket_already_sent(): void
    {
        $bar = $this->station('Bar', 'BAR');
        $main = $this->station('Main Kitchen', 'MK', ['is_default' => true]);

        $dish = $this->dish(['name' => 'Masala Chai', 'kitchen_station_id' => $bar->id]);

        $order = $this->ticket([$dish]);

        $dish->forceFill(['kitchen_station_id' => $main->id])->save();

        $this->assertSame($bar->id, (int) $order->items()->value('kitchen_station_id'));
    }

    /* ------------------------------------------------------------- feed */

    public function test_the_board_shows_only_this_stations_work(): void
    {
        $bar = $this->station('Bar', 'BAR');
        $tandoor = $this->station('Tandoor', 'TAN', ['is_default' => true]);

        $this->ticket([
            $this->dish(['name' => 'Fresh Lime Soda', 'kitchen_station_id' => $bar->id]),
            $this->dish(['name' => 'Butter Naan', 'kitchen_station_id' => $tandoor->id]),
        ]);

        $kitchen = app(KitchenService::class);

        $onBar = $kitchen->feed($bar);
        $onTandoor = $kitchen->feed($tandoor);

        // The same ticket appears on both - it is one job in two rooms - but
        // each board counts only its own lines.
        $this->assertCount(1, $onBar);
        $this->assertCount(1, $onTandoor);

        $this->assertSame(1, $kitchen->counts($bar)[Order::PENDING]);
        $this->assertSame(1, $kitchen->counts($tandoor)[Order::PENDING]);
    }

    /** A web order is not the kitchen's problem until somebody says it is. */
    public function test_an_order_with_no_kitchen_lines_never_reaches_the_board(): void
    {
        $this->station('Main Kitchen', 'MK', ['is_default' => true]);

        $order = $this->ticket([$this->dish()]);

        // Exactly the shape of every row written before this feature existed.
        $order->items()->update(['kitchen_status' => null, 'kitchen_station_id' => null]);

        $this->assertCount(0, app(KitchenService::class)->feed());
    }

    public function test_a_cancelled_ticket_leaves_the_board(): void
    {
        $this->station('Main Kitchen', 'MK', ['is_default' => true]);

        $order = $this->ticket([$this->dish()]);

        $this->assertCount(1, app(KitchenService::class)->feed());

        $order->forceFill(['status' => Order::CANCELLED])->save();

        $this->assertCount(0, app(KitchenService::class)->feed());
    }

    /* ------------------------------------------------------------ bumps */

    public function test_a_station_moves_only_its_own_lines(): void
    {
        $bar = $this->station('Bar', 'BAR');
        $tandoor = $this->station('Tandoor', 'TAN', ['is_default' => true]);

        $order = $this->ticket([
            $this->dish(['name' => 'Fresh Lime Soda', 'kitchen_station_id' => $bar->id]),
            $this->dish(['name' => 'Butter Naan', 'kitchen_station_id' => $tandoor->id]),
        ]);

        app(KitchenService::class)->bumpTicket($order, $bar, Order::READY);

        $lines = $order->items()->orderBy('id')->get();

        $this->assertSame(Order::READY, $lines[0]->kitchen_status);
        $this->assertSame(Order::PENDING, $lines[1]->kitchen_status);
    }

    /**
     * The property the whole line-level design exists for.
     */
    public function test_a_ticket_turns_ready_only_when_the_last_station_lets_go(): void
    {
        $bar = $this->station('Bar', 'BAR');
        $tandoor = $this->station('Tandoor', 'TAN', ['is_default' => true]);

        $order = $this->ticket([
            $this->dish(['name' => 'Fresh Lime Soda', 'kitchen_station_id' => $bar->id]),
            $this->dish(['name' => 'Butter Naan', 'kitchen_station_id' => $tandoor->id]),
        ]);

        $kitchen = app(KitchenService::class);

        $kitchen->bumpTicket($order, $bar, Order::READY);

        // The bar is done and the ticket has not moved: the least-advanced
        // line is still the kebab nobody has picked up.
        $this->assertSame(Order::PENDING, $order->fresh()->status);

        $kitchen->bumpTicket($order, $tandoor, Order::READY);

        $this->assertSame(Order::READY, $order->fresh()->status);
        $this->assertNotNull($order->fresh()->ready_at);
    }

    public function test_the_ticket_climbs_with_its_least_advanced_line(): void
    {
        $bar = $this->station('Bar', 'BAR');
        $tandoor = $this->station('Tandoor', 'TAN', ['is_default' => true]);

        $order = $this->ticket([
            $this->dish(['name' => 'Fresh Lime Soda', 'kitchen_station_id' => $bar->id]),
            $this->dish(['name' => 'Butter Naan', 'kitchen_station_id' => $tandoor->id]),
        ]);

        $kitchen = app(KitchenService::class);

        $kitchen->bumpTicket($order, $bar, Order::CONFIRMED);
        $this->assertSame(Order::PENDING, $order->fresh()->status);

        $kitchen->bumpTicket($order, $tandoor, Order::CONFIRMED);
        $this->assertSame(Order::CONFIRMED, $order->fresh()->status);
        $this->assertNotNull($order->fresh()->accepted_at);
    }

    public function test_a_line_only_moves_forward(): void
    {
        $this->station('Main Kitchen', 'MK', ['is_default' => true]);

        $order = $this->ticket([$this->dish()]);
        $line = $order->items()->firstOrFail();

        $kitchen = app(KitchenService::class);
        $kitchen->bumpLine($line, Order::READY);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('only moves forward');

        $kitchen->bumpLine($line->fresh(), Order::PREPARING);
    }

    /**
     * The bug this catches: with lines already past the target, every one of
     * them is skipped and the caller is told the bump worked.
     */
    public function test_a_bump_that_moves_nothing_is_refused(): void
    {
        $this->station('Main Kitchen', 'MK', ['is_default' => true]);

        $order = $this->ticket([$this->dish()]);

        $kitchen = app(KitchenService::class);
        $kitchen->bumpTicket($order, null, Order::READY);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('waiting to go to');

        $kitchen->bumpTicket($order->fresh(), null, Order::PENDING);
    }

    /** A line bumped straight to Ready was still cooked. */
    public function test_a_line_skipped_to_ready_still_records_when_it_started(): void
    {
        $this->station('Main Kitchen', 'MK', ['is_default' => true]);

        $order = $this->ticket([$this->dish()]);

        app(KitchenService::class)->bumpLine($order->items()->firstOrFail(), Order::READY);

        $line = $order->items()->firstOrFail();

        $this->assertNotNull($line->kitchen_started_at);
        $this->assertNotNull($line->kitchen_ready_at);
    }

    /* ----------------------------------------------------------- recall */

    public function test_a_recall_moves_one_station_back_and_clears_its_stamps(): void
    {
        $bar = $this->station('Bar', 'BAR');
        $tandoor = $this->station('Tandoor', 'TAN', ['is_default' => true]);

        $order = $this->ticket([
            $this->dish(['name' => 'Fresh Lime Soda', 'kitchen_station_id' => $bar->id]),
            $this->dish(['name' => 'Butter Naan', 'kitchen_station_id' => $tandoor->id]),
        ]);

        $kitchen = app(KitchenService::class);
        $kitchen->bumpTicket($order, $bar, Order::READY);
        $kitchen->bumpTicket($order, $tandoor, Order::READY);

        $this->assertSame(Order::READY, $order->fresh()->status);

        $kitchen->recall($order->fresh(), Order::PREPARING, $tandoor, 'Came back cold');

        $lines = $order->items()->orderBy('id')->get();

        // The bar is untouched; only the tandoor went back.
        $this->assertSame(Order::READY, $lines[0]->kitchen_status);
        $this->assertSame(Order::PREPARING, $lines[1]->kitchen_status);

        // The stamp that no longer describes anything is gone; the one that
        // still does is kept.
        $this->assertNull($lines[1]->kitchen_ready_at);
        $this->assertNotNull($lines[1]->kitchen_started_at);

        $this->assertSame(Order::PREPARING, $order->fresh()->status);
        $this->assertNull($order->fresh()->ready_at);
    }

    public function test_a_recall_of_a_ticket_that_has_not_started_is_refused(): void
    {
        $this->station('Main Kitchen', 'MK', ['is_default' => true]);

        $order = $this->ticket([$this->dish()]);

        $this->expectException(RuntimeException::class);

        app(KitchenService::class)->recall($order, Order::PENDING, null, 'Nothing to undo');
    }

    /* ---------------------------------------------------------- the screen */

    public function test_the_board_renders_with_a_ticket_on_it(): void
    {
        $tandoor = $this->station('Tandoor', 'TAN', ['is_default' => true]);

        $order = $this->ticket([$this->dish(['name' => 'Butter Naan'])]);

        $this->actingAs($this->cook())
            ->get('/admin/kitchen')
            ->assertOk()
            ->assertSee($order->order_number)
            ->assertSee('Butter Naan')
            ->assertSee('Tandoor')
            ->assertSee('data-kds-board', false);
    }

    public function test_the_fragment_is_what_the_screen_polls(): void
    {
        $this->station('Main Kitchen', 'MK', ['is_default' => true]);

        $order = $this->ticket([$this->dish()]);

        $this->actingAs($this->cook())
            ->withHeader('X-Fragment', '1')
            ->get('/admin/kitchen')
            ->assertOk()
            ->assertSee($order->order_number)
            // The fragment is the board alone - a poll that dragged the whole
            // page in would rebuild the controls somebody is reaching for.
            ->assertDontSee('<!DOCTYPE', false);
    }

    public function test_a_cook_can_bump_a_ticket_over_http(): void
    {
        $this->station('Main Kitchen', 'MK', ['is_default' => true]);

        $order = $this->ticket([$this->dish()]);

        $this->actingAs($this->cook())
            ->putJson("/admin/kitchen/tickets/{$order->id}/bump", ['to' => Order::CONFIRMED])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(Order::CONFIRMED, $order->fresh()->status);
    }

    /** §9: reopening is for authorised staff, and this is what that means. */
    public function test_a_cook_cannot_recall_a_ticket(): void
    {
        $this->station('Main Kitchen', 'MK', ['is_default' => true]);

        $order = $this->ticket([$this->dish()]);

        app(KitchenService::class)->bumpTicket($order, null, Order::READY);

        $this->actingAs($this->cook())
            ->putJson("/admin/kitchen/tickets/{$order->id}/recall", [
                'to' => Order::PREPARING,
                'reason' => 'Because I can',
            ])
            ->assertForbidden();

        $this->assertSame(Order::READY, $order->fresh()->status);
    }

    public function test_a_recall_without_a_reason_is_refused(): void
    {
        $this->station('Main Kitchen', 'MK', ['is_default' => true]);

        $order = $this->ticket([$this->dish()]);

        app(KitchenService::class)->bumpTicket($order, null, Order::READY);

        $this->actingAs($this->cook(['kitchen.tickets.recall']))
            ->putJson("/admin/kitchen/tickets/{$order->id}/recall", ['to' => Order::PREPARING])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    public function test_the_kot_slip_carries_no_prices(): void
    {
        $this->station('Main Kitchen', 'MK', ['is_default' => true]);

        $order = $this->ticket([$this->dish(['name' => 'Butter Naan', 'selling_price' => 60])]);

        $this->actingAs($this->cook())
            ->get("/admin/kitchen/tickets/{$order->id}/kot")
            ->assertOk()
            ->assertSee('Butter Naan')
            // A KOT is a work order. One that carried totals would be handed
            // to a guest by accident about once a month.
            ->assertDontSee('60.00')
            ->assertDontSee('₹');
    }

    /* ------------------------------------------------------ the gatekeepers */

    public function test_without_the_right_the_board_is_closed(): void
    {
        $this->station('Main Kitchen', 'MK', ['is_default' => true]);

        $stranger = User::create([
            'tenant_id' => $this->shop()->tenant_id,
            'name' => 'Stranger',
            'email' => 'stranger@example.test',
            'password' => 'stranger-password-1',
            'is_admin' => true,
        ]);

        $stranger->shops()->syncWithoutDetaching([$this->shop()->id => ['is_default' => true]]);
        $stranger->forceFill([
            'current_shop_id' => $this->shop()->id,
            'all_shops_view' => false,
        ])->save();

        $this->actingAs($stranger)->get('/admin/kitchen')->assertForbidden();
    }

    /**
     * A bakery counter that sells what is already baked unticks the module,
     * and the screen goes with it - for everybody, including whoever holds
     * the permission.
     */
    public function test_a_shop_with_the_module_off_has_no_kitchen(): void
    {
        $this->station('Main Kitchen', 'MK', ['is_default' => true]);

        $this->shop()->forceFill(['modules' => ['retail', 'inventory', 'dining']])->save();
        CurrentShop::forget();

        $this->actingAs($this->cook())->get('/admin/kitchen')->assertForbidden();
    }

    /**
     * A board mixing two branches' tickets is not a kitchen screen.
     *
     * A cook in one building cannot cook the food in another, and the stations
     * it would list belong to both.
     */
    public function test_the_board_needs_a_single_shop(): void
    {
        $this->station('Main Kitchen', 'MK', ['is_default' => true]);

        /*
         | Two branches, because CurrentShop only consolidates when there is
         | more than one to consolidate - somebody with access to a single
         | shop stays in it whatever their preference says.
         */
        $second = Shop::query()->firstOrCreate(
            ['code' => 'SB'],
            [
                'tenant_id' => $this->shop()->tenant_id,
                'name' => 'Second Branch',
                'slug' => 'second-branch',
                'is_active' => true,
            ],
        );

        $cook = $this->cook();
        $cook->shops()->syncWithoutDetaching([$second->id => ['is_default' => false]]);
        $cook->forceFill(['all_shops_view' => true, 'current_shop_id' => null])->save();

        CurrentShop::forget();

        $this->actingAs($cook->fresh())
            ->get('/admin/kitchen')
            ->assertRedirect(route('admin.dashboard'));
    }

    /* --------------------------------------------------------- the stations */

    public function test_a_branch_keeps_exactly_one_default_station(): void
    {
        $first = $this->station('Main Kitchen', 'MK', ['is_default' => true]);
        $second = $this->station('Tandoor', 'TAN');

        $second->makeDefault();

        $this->assertFalse($first->fresh()->is_default);
        $this->assertTrue($second->fresh()->is_default);

        $this->assertSame(
            1,
            KitchenStation::query()->where('is_default', true)->count(),
        );
    }

    public function test_a_station_with_dishes_routed_to_it_cannot_be_deleted(): void
    {
        $tandoor = $this->station('Tandoor', 'TAN');
        $this->station('Main Kitchen', 'MK', ['is_default' => true]);

        $this->dish(['name' => 'Butter Naan', 'kitchen_station_id' => $tandoor->id]);

        $this->actingAs($this->cook(['kitchen.stations.delete']))
            ->deleteJson("/admin/kitchen-stations/{$tandoor->id}")
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertNotNull($tandoor->fresh());
    }

    public function test_a_station_still_cooking_cannot_be_switched_off(): void
    {
        $tandoor = $this->station('Tandoor', 'TAN');
        $this->station('Main Kitchen', 'MK', ['is_default' => true]);

        $this->ticket([$this->dish(['name' => 'Butter Naan', 'kitchen_station_id' => $tandoor->id])]);

        $this->actingAs($this->cook(['kitchen.stations.edit']))
            ->putJson("/admin/kitchen-stations/{$tandoor->id}/status")
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertTrue($tandoor->fresh()->is_active);
    }

    public function test_the_first_station_a_branch_creates_becomes_its_default(): void
    {
        $this->actingAs($this->cook(['kitchen.stations.create']))
            ->postJson('/admin/kitchen-stations', [
                'shop_id' => $this->shop()->id,
                'name' => 'Main Kitchen',
                'code' => 'mk',
                'prep_minutes' => 18,
                'is_active' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $station = KitchenStation::query()->firstOrFail();

        $this->assertTrue($station->is_default);
        // Upper-cased on the way in: it is printed beside a table code, and
        // "mk" next to "GF-04" reads as two schemes.
        $this->assertSame('MK', $station->code);
    }

    /* ----------------------------------------------------------- the clock */

    public function test_a_late_line_is_counted_against_its_own_station(): void
    {
        // A bar is late after six minutes; a tandoor six minutes into a raan
        // has barely started. One number for both would make the colours on
        // the board mean nothing.
        $bar = $this->station('Bar', 'BAR', ['prep_minutes' => 6]);
        $tandoor = $this->station('Tandoor', 'TAN', ['prep_minutes' => 40, 'is_default' => true]);

        $order = $this->ticket([
            $this->dish(['name' => 'Fresh Lime Soda', 'kitchen_station_id' => $bar->id]),
            $this->dish(['name' => 'Raan', 'kitchen_station_id' => $tandoor->id]),
        ]);

        $order->forceFill(['placed_at' => now()->subMinutes(10)])->save();

        $kitchen = app(KitchenService::class);

        $this->assertSame(1, $kitchen->counts($bar)['late']);
        $this->assertSame(0, $kitchen->counts($tandoor)['late']);
        $this->assertSame(1, $kitchen->counts()['late']);
    }

    /* --------------------------------------------------------- the ladder */

    public function test_the_kitchen_ladder_and_the_web_ladder_stay_apart(): void
    {
        // A dine-in ticket is never packed or shipped. If somebody adds a
        // rung to STATUSES without thinking, this is what says so.
        $this->assertSame(
            [Order::PENDING, Order::CONFIRMED, Order::PREPARING, Order::READY, Order::SERVED],
            Order::KITCHEN_FLOW,
        );

        $this->assertNotContains(Order::PACKING, Order::KITCHEN_FLOW);
        $this->assertNotContains(Order::SHIPPED, Order::KITCHEN_FLOW);
    }

    public function test_an_order_item_reports_its_next_rung(): void
    {
        $line = new OrderItem(['kitchen_status' => Order::PREPARING]);

        $this->assertSame(Order::READY, $line->nextKitchenStatus());

        $done = new OrderItem(['kitchen_status' => Order::SERVED]);

        $this->assertNull($done->nextKitchenStatus());
    }

    /* ---------------------------------------------------------- the report */

    /**
     * The preparation-time report (§9).
     *
     * This test earns its keep twice over. It checks the arithmetic, and it is
     * the only thing that runs the report's SQL on SQLite at all - the app runs
     * on MySQL, the durations need a function whose name differs on every
     * engine, and a report written in one dialect is a report that is never
     * tested. See ReportService::seconds().
     */
    public function test_the_kitchen_report_separates_the_wait_from_the_cooking(): void
    {
        $bar = $this->station('Bar', 'BAR', ['prep_minutes' => 6]);
        $tandoor = $this->station('Tandoor', 'TAN', ['prep_minutes' => 40, 'is_default' => true]);

        $order = $this->ticket([
            $this->dish(['name' => 'Fresh Lime Soda', 'kitchen_station_id' => $bar->id]),
            $this->dish(['name' => 'Raan', 'kitchen_station_id' => $tandoor->id]),
        ]);

        /*
         | Placed twenty minutes ago and accepted five minutes later, so the
         | wait to accept is a real number rather than the zero every bump in
         | the same second would give.
         */
        $order->forceFill([
            'placed_at' => now()->subMinutes(20),
            'accepted_at' => now()->subMinutes(15),
        ])->save();

        $lines = $order->items()->orderBy('id')->get();

        // The bar took two minutes; the tandoor took twelve.
        $lines[0]->forceFill([
            'kitchen_status' => Order::READY,
            'kitchen_started_at' => now()->subMinutes(15),
            'kitchen_ready_at' => now()->subMinutes(13),
        ])->save();

        $lines[1]->forceFill([
            'kitchen_status' => Order::READY,
            'kitchen_started_at' => now()->subMinutes(14),
            'kitchen_ready_at' => now()->subMinutes(2),
        ])->save();

        /*
         | Signed in, because ReportService narrows every report to the shops
         | the *reader* may see. With nobody signed in there is no such list
         | and the report is correctly empty - which is the right behaviour and
         | a confusing way to write a test.
         */
        $this->actingAs($this->cook(['reports.kitchen_report.view']));

        $rows = app(ReportService::class)
            ->kitchenPerformance(ReportFilters::fromRequest(new Request(['preset' => 'this_month'])))
            ->keyBy('station');

        $this->assertSame(2, $rows->count());

        // Both lines sat on the same ticket, so both waited the same five
        // minutes to be accepted - that wait belongs to the ticket.
        $this->assertEqualsWithDelta(300, (float) $rows['Bar']->accept_seconds, 5);
        $this->assertEqualsWithDelta(300, (float) $rows['Tandoor']->accept_seconds, 5);

        // The cooking is the station's own, and the whole point of the report.
        $this->assertEqualsWithDelta(120, (float) $rows['Bar']->cook_seconds, 5);
        $this->assertEqualsWithDelta(720, (float) $rows['Tandoor']->cook_seconds, 5);

        /*
         | Late is judged against each station's own window. The bar was on the
         | pass seven minutes after the ticket was placed against a six-minute
         | allowance; the tandoor took eighteen against forty.
         */
        $this->assertSame(1, (int) $rows['Bar']->late);
        $this->assertSame(0, (int) $rows['Tandoor']->late);
    }

    /** A line the kitchen never finished has no duration, and is not averaged. */
    public function test_an_unfinished_line_is_counted_but_not_averaged(): void
    {
        $this->station('Main Kitchen', 'MK', ['is_default' => true]);

        $order = $this->ticket([$this->dish()]);
        $order->forceFill(['placed_at' => now()->subMinutes(30)])->save();

        $this->actingAs($this->cook(['reports.kitchen_report.view']));

        $row = app(ReportService::class)
            ->kitchenPerformance(ReportFilters::fromRequest(new Request(['preset' => 'this_month'])))
            ->firstOrFail();

        $this->assertSame(1, (int) $row->lines_made);
        $this->assertSame(1, (int) $row->unfinished);

        // Null, not zero. Counting it as zero would make a backed-up kitchen
        // look fast, which is the opposite of what this report is for.
        $this->assertNull($row->cook_seconds);
    }

    public function test_the_report_screen_renders(): void
    {
        $this->station('Main Kitchen', 'MK', ['is_default' => true]);

        $this->ticket([$this->dish(['name' => 'Butter Naan'])]);

        $this->actingAs($this->cook(['reports.kitchen_report.view']))
            ->get('/admin/reports/kitchen?preset=this_month')
            ->assertOk()
            ->assertSee('Kitchen Performance')
            ->assertSee('Main Kitchen')
            ->assertSee('Wait to accept');
    }

    public function test_the_report_is_closed_without_its_own_right(): void
    {
        $this->station('Main Kitchen', 'MK', ['is_default' => true]);

        // A cook holds the board and the bump, and still cannot read a report
        // about how slow the line was.
        $this->actingAs($this->cook())
            ->get('/admin/reports/kitchen?preset=this_month')
            ->assertForbidden();
    }
}
