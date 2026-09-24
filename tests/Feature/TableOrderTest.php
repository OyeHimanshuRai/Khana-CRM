<?php

namespace Tests\Feature;

use App\Models\Floor;
use App\Models\Modifier;
use App\Models\ModifierOption;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\RestaurantTable;
use App\Models\Shop;
use App\Models\TableCartItem;
use App\Models\TableSession;
use App\Services\TableCartService;
use App\Services\TableOrderService;
use App\Services\TableQrService;
use App\Services\TableSessionService;
use App\Support\CurrentShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Tests\TestCase;

/**
 * A guest ordering from their phone (§3.5 - §3.11).
 *
 * The rules worth testing hardest are the ones a hand-posted form would
 * otherwise walk straight through: a size must be named where a dish has
 * sizes, an add-on must belong to the dish and satisfy its question, and
 * nothing sells that sold out while the guest was reading.
 *
 * And the one that makes the bill add up: every round from a table joins the
 * same sitting.
 */
class TableOrderTest extends TestCase
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

    private function table(): RestaurantTable
    {
        $floor = new Floor([
            'shop_id' => $this->shop()->id,
            'name' => 'Ground Floor',
            'code' => 'GF',
            'is_active' => true,
        ]);
        $floor->save();

        $table = new RestaurantTable([
            'shop_id' => $floor->shop_id,
            'floor_id' => $floor->id,
            'name' => '4',
            'code' => 'GF-04',
            'capacity' => 4,
            'status' => RestaurantTable::AVAILABLE,
            'is_active' => true,
        ]);
        $table->save();

        app(TableQrService::class)->issue($table);

        return $table->fresh(['activeQr']);
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

    /** Scan the sticker, the way a phone does. */
    private function seat(RestaurantTable $table): TableSession
    {
        $this->get('/t/'.$table->activeQr->token)->assertRedirect(route('table.show'));

        return TableSession::allShops()->live()->firstOrFail();
    }

    /* ------------------------------------------------------------ the cart */

    public function test_a_guest_adds_a_dish_and_sees_it_in_the_cart(): void
    {
        $table = $this->table();
        $dish = $this->dish();

        $this->seat($table);

        $this->post('/t/cart', ['product_id' => $dish->id])
            ->assertRedirect();

        $this->get('/t/cart')
            ->assertOk()
            ->assertSee('Dal Makhani')
            ->assertSee('280.00');
    }

    /**
     * Three taps on a plain naan should read as three naans, not three rows.
     */
    public function test_adding_the_same_thing_twice_bumps_the_quantity(): void
    {
        $table = $this->table();
        $dish = $this->dish();
        $session = $this->seat($table);

        $this->post('/t/cart', ['product_id' => $dish->id]);
        $this->post('/t/cart', ['product_id' => $dish->id, 'quantity' => 2]);

        $this->assertSame(1, $session->cartItems()->count());
        $this->assertSame(3, (int) $session->cartItems()->sum('quantity'));
    }

    /** A different note is a genuinely different thing for the kitchen. */
    public function test_a_different_note_gets_its_own_line(): void
    {
        $table = $this->table();
        $dish = $this->dish();
        $session = $this->seat($table);

        $this->post('/t/cart', ['product_id' => $dish->id]);
        $this->post('/t/cart', ['product_id' => $dish->id, 'note' => 'No cream']);

        $this->assertSame(2, $session->cartItems()->count());
    }

    /**
     * The check that stops a table being billed for a Full and served a Half.
     */
    public function test_a_dish_with_sizes_refuses_an_order_with_no_size(): void
    {
        $table = $this->table();
        $dish = $this->dish(['name' => 'Chicken Biryani']);

        ProductVariant::query()->create([
            'product_id' => $dish->id, 'name' => 'Half', 'price' => 260,
        ]);
        ProductVariant::query()->create([
            'product_id' => $dish->id, 'name' => 'Full', 'price' => 440, 'is_default' => true,
        ]);

        $session = $this->seat($table);

        $this->post('/t/cart', ['product_id' => $dish->id])
            ->assertSessionHas('table_error');

        $this->assertSame(0, $session->cartItems()->count());
    }

    public function test_the_size_chosen_is_what_is_priced(): void
    {
        $table = $this->table();
        $dish = $this->dish(['name' => 'Chicken Biryani', 'selling_price' => 440]);

        $half = ProductVariant::query()->create([
            'product_id' => $dish->id, 'name' => 'Half', 'price' => 260,
        ]);

        $session = $this->seat($table);

        $this->post('/t/cart', [
            'product_id' => $dish->id,
            'product_variant_id' => $half->id,
        ]);

        $line = $session->cartItems()->with(['product', 'variant'])->firstOrFail();

        $this->assertSame(260.0, $line->unitPrice('dine_in', $session->shop_id));
    }

    /* --------------------------------------------------------- the add-ons */

    /**
     * @return array{0: Product, 1: Modifier, 2: ModifierOption, 3: ModifierOption}
     */
    private function pizzaWithCrust(): array
    {
        $pizza = $this->dish(['name' => 'Margherita', 'selling_price' => 320]);

        $crust = new Modifier([
            'shop_id' => $this->shop()->id,
            'name' => 'Choose your crust',
            'min_select' => 1,
            'max_select' => 1,
            'is_active' => true,
        ]);
        $crust->save();

        $thin = ModifierOption::query()->create([
            'modifier_id' => $crust->id, 'name' => 'Thin crust', 'price' => 0, 'sort_order' => 0,
        ]);
        $burst = ModifierOption::query()->create([
            'modifier_id' => $crust->id, 'name' => 'Cheese burst', 'price' => 70, 'sort_order' => 1,
        ]);

        $crust->products()->sync([$pizza->id => ['sort_order' => 0]]);

        return [$pizza, $crust, $thin, $burst];
    }

    public function test_an_add_on_is_added_to_the_line_price(): void
    {
        [$pizza, , , $burst] = $this->pizzaWithCrust();

        $table = $this->table();
        $session = $this->seat($table);

        $this->post('/t/cart', [
            'product_id' => $pizza->id,
            'options' => [$burst->id],
        ]);

        $line = $session->cartItems()->with(['product', 'variant'])->firstOrFail();

        $this->assertSame(390.0, $line->unitPrice('dine_in', $session->shop_id));
    }

    /** A cart posted by hand must not buy a pizza with no crust. */
    public function test_a_required_question_must_be_answered(): void
    {
        [$pizza] = $this->pizzaWithCrust();

        $table = $this->table();
        $session = $this->seat($table);

        $this->post('/t/cart', ['product_id' => $pizza->id, 'options' => []])
            ->assertSessionHas('table_error');

        $this->assertSame(0, $session->cartItems()->count());
    }

    public function test_a_pick_one_question_refuses_two(): void
    {
        [$pizza, , $thin, $burst] = $this->pizzaWithCrust();

        $table = $this->table();
        $session = $this->seat($table);

        $this->post('/t/cart', [
            'product_id' => $pizza->id,
            'options' => [$thin->id, $burst->id],
        ])->assertSessionHas('table_error');

        $this->assertSame(0, $session->cartItems()->count());
    }

    /** An option from another dish's question is not on this dish. */
    public function test_an_option_from_another_dish_is_refused(): void
    {
        [$pizza] = $this->pizzaWithCrust();

        $other = new Modifier([
            'shop_id' => $this->shop()->id,
            'name' => 'Sweetness',
            'min_select' => 0,
            'max_select' => 1,
            'is_active' => true,
        ]);
        $other->save();

        $sugar = ModifierOption::query()->create([
            'modifier_id' => $other->id, 'name' => 'No sugar', 'price' => 0,
        ]);

        $table = $this->table();
        $session = $this->seat($table);

        $this->post('/t/cart', [
            'product_id' => $pizza->id,
            'options' => [$sugar->id],
        ])->assertSessionHas('table_error');

        $this->assertSame(0, $session->cartItems()->count());
    }

    /* ----------------------------------------------------- what is sellable */

    public function test_a_sold_out_dish_cannot_be_added(): void
    {
        $table = $this->table();
        $dish = $this->dish(['is_sold_out' => true]);
        $session = $this->seat($table);

        $this->post('/t/cart', ['product_id' => $dish->id])
            ->assertSessionHas('table_error');

        $this->assertSame(0, $session->cartItems()->count());
    }

    /**
     * A dish can sell out between a guest choosing it and tapping Order. The
     * check at placement is the one that matters.
     */
    public function test_a_dish_that_sells_out_after_it_was_added_blocks_the_order(): void
    {
        $table = $this->table();
        $dish = $this->dish();
        $session = $this->seat($table);

        $this->post('/t/cart', ['product_id' => $dish->id]);

        $dish->forceFill(['is_sold_out' => true])->save();

        $this->post('/t/order')->assertSessionHas('table_error');

        $this->assertSame(0, Order::allShops()->count());
        // And the cart is untouched, so they can remove it and send the rest.
        $this->assertSame(1, $session->cartItems()->count());
    }

    /* ---------------------------------------------------------- the order */

    public function test_sending_the_cart_makes_an_order_and_empties_it(): void
    {
        $table = $this->table();
        $dish = $this->dish();
        $session = $this->seat($table);

        $this->post('/t/cart', ['product_id' => $dish->id, 'quantity' => 2]);

        $this->post('/t/order', ['guest_name' => 'Rahul'])
            ->assertRedirect(route('table.orders'));

        $order = Order::allShops()->firstOrFail();

        $this->assertSame(Order::DINE_IN, $order->order_type);
        $this->assertSame(Order::PENDING, $order->status);
        $this->assertSame($session->id, $order->table_session_id);
        $this->assertSame('GF-04/1', $order->order_number);
        $this->assertSame(560.0, (float) $order->grand_total);
        $this->assertSame(0, $session->cartItems()->count());
    }

    /** A dine-in guest need not sign in, and has not chosen how to pay. */
    public function test_a_dine_in_order_has_no_customer_and_no_payment_method(): void
    {
        $table = $this->table();
        $dish = $this->dish();
        $this->seat($table);

        $this->post('/t/cart', ['product_id' => $dish->id]);
        $this->post('/t/order');

        $order = Order::allShops()->firstOrFail();

        $this->assertNull($order->customer_id);
        $this->assertNull($order->payment_method);
    }

    /** §3.11: later rounds join the same sitting and the same bill. */
    public function test_a_second_round_joins_the_same_sitting(): void
    {
        $table = $this->table();
        $dish = $this->dish();
        $session = $this->seat($table);

        $this->post('/t/cart', ['product_id' => $dish->id]);
        $this->post('/t/order');

        $this->post('/t/cart', ['product_id' => $dish->id]);
        $this->post('/t/order');

        $orders = $session->orders()->get();

        $this->assertCount(2, $orders);
        $this->assertSame(['GF-04/2', 'GF-04/1'], $orders->pluck('order_number')->all());
        $this->assertSame(560.0, (float) $orders->sum('grand_total'));
    }

    /**
     * The bug that took the guest's cart down with a 500.
     *
     * Ticket numbers used to count the sitting's own rounds, which restart at
     * one for every new party - while `orders` is unique on (shop_id,
     * order_number) for ever. So the second party at a table sent "GF-04/1",
     * the index refused the insert, and the guest got a database error from
     * /t/cart with their food never reaching the kitchen. It was not an edge
     * case: every table in the building broke on its second sitting.
     */
    public function test_a_later_sitting_at_the_same_table_gets_its_own_numbers(): void
    {
        $table = $this->table();
        $dish = $this->dish();

        $first = $this->seat($table);
        $this->post('/t/cart', ['product_id' => $dish->id]);
        $this->post('/t/order');
        $this->post('/t/cart', ['product_id' => $dish->id]);
        $this->post('/t/order');

        // They pay and leave; the next party sits down at the same table.
        app(TableSessionService::class)->close($first->fresh(), 'Settled');
        $this->flushSession();

        $second = $this->seat($table);
        $this->post('/t/cart', ['product_id' => $dish->id]);
        $this->post('/t/order')->assertRedirect(route('table.orders'));

        $this->assertNotSame($first->id, $second->id);

        $numbers = Order::allShops()->orderBy('id')->pluck('order_number')->all();

        $this->assertSame(['GF-04/1', 'GF-04/2', 'GF-04/3'], $numbers);
        $this->assertCount(3, array_unique($numbers));
    }

    /** Names and prices are copied, so a re-priced menu cannot rewrite a bill. */
    public function test_the_order_line_snapshots_what_was_charged(): void
    {
        [$pizza, , , $burst] = $this->pizzaWithCrust();

        $table = $this->table();
        $this->seat($table);

        $this->post('/t/cart', ['product_id' => $pizza->id, 'options' => [$burst->id]]);
        $this->post('/t/order');

        // The menu moves on afterwards.
        $pizza->forceFill(['name' => 'Margherita (new recipe)', 'selling_price' => 420])->save();
        $burst->forceFill(['price' => 90])->save();

        $item = Order::allShops()->firstOrFail()->items()->with('modifiers')->firstOrFail();

        $this->assertSame('Margherita', $item->product_name);
        $this->assertSame(390.0, (float) $item->unit_price);
        $this->assertSame('Cheese burst', $item->modifiers->first()->option_name);
        $this->assertSame(70.0, (float) $item->modifiers->first()->price);
    }

    public function test_an_empty_cart_cannot_be_sent(): void
    {
        $table = $this->table();
        $this->seat($table);

        $this->post('/t/order')->assertSessionHas('table_error');

        $this->assertSame(0, Order::allShops()->count());
    }

    /**
     * Once the bill is raised, adding to it silently would have the guest pay
     * for something the printed total did not include.
     */
    public function test_a_billed_sitting_takes_no_more_orders(): void
    {
        $table = $this->table();
        $dish = $this->dish();
        $session = $this->seat($table);

        $this->post('/t/cart', ['product_id' => $dish->id]);

        app(TableSessionService::class)->bill($session);

        $this->post('/t/cart', ['product_id' => $dish->id])->assertSessionHas('table_error');
        $this->post('/t/order')->assertSessionHas('table_error');

        $this->assertSame(0, Order::allShops()->count());
    }

    /* ---------------------------------------------------------- the pages */

    public function test_the_order_page_shows_every_round_and_a_running_total(): void
    {
        $table = $this->table();
        $dish = $this->dish();
        $this->seat($table);

        $this->post('/t/cart', ['product_id' => $dish->id]);
        $this->post('/t/order');

        $this->get('/t/orders')
            ->assertOk()
            ->assertSee('GF-04/1')
            ->assertSee('Placed')
            ->assertSee('Running total');
    }

    public function test_the_menu_carries_an_add_button(): void
    {
        $table = $this->table();
        $this->dish();
        $this->seat($table);

        $this->get('/t')
            ->assertOk()
            ->assertSee('Dal Makhani')
            ->assertSee(route('table.cart.add'), false);
    }

    public function test_the_cart_bar_appears_only_once_something_is_in_it(): void
    {
        $table = $this->table();
        $dish = $this->dish();
        $this->seat($table);

        /*
         | Rendered but hidden, not absent. The menu has no other way through
         | to the order, so a guest whose first dish went in over fetch - with
         | no reload to build the bar - would have a toast and nowhere to go.
         | The bar is in the markup from the start and the script unhides it.
         */
        $this->get('/t')->assertOk()->assertSee('data-cart-bar hidden', false);

        $this->post('/t/cart', ['product_id' => $dish->id]);

        $this->get('/t')->assertOk()
            ->assertSee('View order')
            ->assertDontSee('data-cart-bar hidden', false);
    }

    /* ---------------------------------------------------------- the guard */

    /**
     * Every phone in the restaurant can reach these endpoints. A line belongs
     * to the sitting it is edited from.
     */
    public function test_a_line_on_another_table_cannot_be_touched(): void
    {
        $mine = $this->table();
        $dish = $this->dish();

        // Somebody else's table, with something in its cart.
        $theirFloor = Floor::query()->firstOrFail();
        $theirTable = new RestaurantTable([
            'shop_id' => $theirFloor->shop_id,
            'floor_id' => $theirFloor->id,
            'name' => '9',
            'code' => 'GF-09',
            'capacity' => 2,
            'is_active' => true,
        ]);
        $theirTable->save();

        $theirSession = app(TableSessionService::class)->openFor($theirTable);

        $theirLine = TableCartItem::query()->create([
            'shop_id' => $theirSession->shop_id,
            'table_session_id' => $theirSession->id,
            'product_id' => $dish->id,
            'quantity' => 1,
        ]);

        // Now sit at my own table and try to reach theirs.
        $this->seat($mine);

        $this->delete('/t/cart/'.$theirLine->id)->assertSessionHas('table_error');

        $this->assertDatabaseHas('table_cart_items', ['id' => $theirLine->id]);
    }

    public function test_the_cart_needs_a_sitting(): void
    {
        $this->get('/t/cart')->assertRedirect(route('table.expired'));
        $this->post('/t/cart', ['product_id' => 1])->assertRedirect(route('table.expired'));
        $this->post('/t/order')->assertRedirect(route('table.expired'));
    }

    /* --------------------------------------------------------- the kitchen */

    public function test_a_ticket_climbs_the_kitchen_ladder(): void
    {
        $table = $this->table();
        $dish = $this->dish();
        $this->seat($table);

        $this->post('/t/cart', ['product_id' => $dish->id]);
        $this->post('/t/order');

        $order = Order::allShops()->firstOrFail();
        $service = app(TableOrderService::class);

        $service->advance($order);
        $this->assertSame(Order::CONFIRMED, $order->fresh()->status);
        $this->assertNotNull($order->fresh()->accepted_at);

        $service->advance($order->fresh());
        $this->assertSame(Order::PREPARING, $order->fresh()->status);

        $service->advance($order->fresh());
        $this->assertSame(Order::READY, $order->fresh()->status);
        $this->assertNotNull($order->fresh()->ready_at);

        $service->advance($order->fresh());
        $this->assertSame(Order::SERVED, $order->fresh()->status);
    }

    /**
     * A ticket sent back a stage is a real thing in a kitchen, but it is a
     * correction with a reason - allowing it quietly here would make the
     * preparation-time report meaningless.
     */
    public function test_a_ticket_does_not_go_backwards(): void
    {
        $table = $this->table();
        $dish = $this->dish();
        $this->seat($table);

        $this->post('/t/cart', ['product_id' => $dish->id]);
        $this->post('/t/order');

        $order = Order::allShops()->firstOrFail();
        $service = app(TableOrderService::class);

        $service->advance($order, Order::READY);

        $this->expectException(RuntimeException::class);

        $service->advance($order->fresh(), Order::PREPARING);
    }

    /* ---------------------------------------------------------- the service */

    public function test_the_cart_is_shared_by_every_phone_at_the_table(): void
    {
        $table = $this->table();
        $dish = $this->dish();

        $session = $this->seat($table);
        $this->post('/t/cart', ['product_id' => $dish->id]);

        // A second phone scans the same sticker.
        $this->flushSession();
        $this->get('/t/'.$table->activeQr->token);

        $this->get('/t/cart')->assertOk()->assertSee('Dal Makhani');

        $this->assertSame(1, $session->cartItems()->count());
    }

    public function test_a_quantity_of_zero_removes_the_line(): void
    {
        $table = $this->table();
        $dish = $this->dish();
        $session = $this->seat($table);

        $this->post('/t/cart', ['product_id' => $dish->id]);

        $line = $session->cartItems()->firstOrFail();

        $this->put('/t/cart/'.$line->id, ['quantity' => 0]);

        $this->assertSame(0, $session->cartItems()->count());
    }

    /** The service refuses what the page should never have offered. */
    public function test_the_service_refuses_a_size_from_another_dish(): void
    {
        $table = $this->table();
        $biryani = $this->dish(['name' => 'Biryani']);
        $other = $this->dish(['name' => 'Pizza']);

        ProductVariant::query()->create(['product_id' => $biryani->id, 'name' => 'Full', 'price' => 440]);
        $theirs = ProductVariant::query()->create([
            'product_id' => $other->id, 'name' => '11 inch', 'price' => 480,
        ]);

        $session = $this->seat($table);

        $this->expectException(RuntimeException::class);

        app(TableCartService::class)->add(
            session: $session,
            product: $biryani,
            variantId: $theirs->id,
        );
    }
}
