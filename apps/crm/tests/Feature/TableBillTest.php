<?php

namespace Tests\Feature;

use App\Models\Floor;
use App\Models\Invoice;
use App\Models\Modifier;
use App\Models\ModifierOption;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\RestaurantTable;
use App\Models\Shop;
use App\Models\StockMovement;
use App\Models\TableSession;
use App\Models\User;
use App\Services\TableBillService;
use App\Services\TableCartService;
use App\Services\TableOrderService;
use App\Services\TableQrService;
use App\Support\CurrentShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Tests\TestCase;

/**
 * Settling a table (§6).
 *
 * The properties worth testing hardest are the ones that cost a restaurant
 * money when they are wrong:
 *
 *   1. A split bills each part once and only once. `settled_quantity` is the
 *      whole defence against a table paying twice for the same plate, or
 *      walking out with half of it unbilled.
 *
 *   2. The bill charges what the guest was shown. Re-pricing at the till would
 *      let a menu change between the order and the bill charge a figure
 *      nobody agreed to.
 *
 *   3. A dish bills when there is no stock of it. A restaurant has no count of
 *      Butter Naan, and "not enough stock" is never the right answer to
 *      somebody trying to pay for food they have eaten.
 */
class TableBillTest extends TestCase
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

    private function dish(array $overrides = []): Product
    {
        $name = $overrides['name'] ?? 'Dal Makhani';

        $product = new Product(array_merge([
            'name' => $name,
            'slug' => Product::uniqueSlug($name),
            'sku' => Product::generateSku($name),
            'selling_price' => 280,
            'is_active' => true,
            // The default for anything a restaurant cooks. The tests that care
            // about the other kind say so.
            'is_made_to_order' => true,
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

    private function seat(RestaurantTable $table): TableSession
    {
        $this->get('/t/'.$table->activeQr->token)->assertRedirect(route('table.show'));

        return TableSession::allShops()
            ->live()
            ->where('restaurant_table_id', $table->id)
            ->firstOrFail();
    }

    /**
     * A sitting that has eaten the dishes given.
     *
     * @param  array<int, array{0: Product, 1?: int}>  $dishes
     */
    private function ate(array $dishes, string $code = 'GF-04'): TableSession
    {
        $session = $this->seat($this->table($code));
        $cart = app(TableCartService::class);

        foreach ($dishes as $row) {
            $cart->add($session, $row[0], null, $row[1] ?? 1);
        }

        app(TableOrderService::class)->place($session);

        return $session->fresh();
    }

    /* ------------------------------------------------------------ actors */

    /** Somebody on the till: the table screen and the money, nothing else. */
    private function cashier(array $extra = []): User
    {
        $user = User::query()->firstWhere('email', 'cashier@example.test');

        if ($user === null) {
            $user = User::create([
                'tenant_id' => $this->shop()->tenant_id,
                'name' => 'Till',
                'email' => 'cashier@example.test',
                'password' => 'cashier-password-1',
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
            'pos.tables.view', 'pos.tables.settle', 'pos.tables.print',
        ], $extra));

        return $user->fresh();
    }

    /* ----------------------------------------------------------- the bill */

    public function test_settling_a_table_raises_one_invoice_and_closes_the_sitting(): void
    {
        $session = $this->ate([[$this->dish(['name' => 'Butter Naan', 'selling_price' => 60]), 3]]);

        $invoice = app(TableBillService::class)->settle($session, [
            'payments' => [['method' => 'cash', 'amount' => 180]],
        ]);

        $this->assertSame(180.0, (float) $invoice->grand_total);
        $this->assertSame(180.0, (float) $invoice->paid_total);

        // The link is on the invoice, so a split can be several off one table.
        $this->assertSame($session->id, (int) $invoice->table_session_id);

        $session = $session->fresh();

        $this->assertSame(TableSession::CLOSED, $session->status);
        $this->assertSame('Settled', $session->closed_reason);

        /*
         | Cleaning, never straight to available. A party has just left it, and
         | a plan that offered it to the next guests before anybody wiped it
         | down would be lying about the room.
         */
        $this->assertSame(RestaurantTable::CLEANING, $session->table->status);
    }

    public function test_every_round_of_a_sitting_lands_on_one_bill(): void
    {
        $naan = $this->dish(['name' => 'Butter Naan', 'selling_price' => 60]);
        $session = $this->ate([[$naan, 2]]);

        // A second round, the way §3.11 means it.
        app(TableCartService::class)->add($session, $naan, null, 1);
        app(TableOrderService::class)->place($session);

        $this->assertSame(2, $session->orders()->count());

        // Paid, because a bill with nothing tendered is a credit sale and
        // InvoiceService rightly wants a named account to owe on. Credit is
        // its own test, further down.
        $invoice = app(TableBillService::class)->settle($session, [
            'payments' => [['method' => 'cash', 'amount' => 180]],
        ]);

        $this->assertSame(180.0, (float) $invoice->grand_total);
        $this->assertSame(1, $session->fresh()->invoices()->count());
    }

    /**
     * The bill says what the guest ate, not what the product is called.
     */
    public function test_the_bill_line_carries_the_size_and_the_add_ons(): void
    {
        $pizza = $this->dish(['name' => 'Margherita', 'selling_price' => 200]);

        $variant = new ProductVariant([
            'product_id' => $pizza->id,
            'name' => '7 inch',
            'price' => 220,
            'is_default' => true,
            'is_active' => true,
        ]);
        $variant->save();

        $modifier = new Modifier([
            'shop_id' => $this->shop()->id,
            'name' => 'Extras',
            'min_select' => 0,
            'max_select' => 2,
            'is_active' => true,
        ]);
        $modifier->save();
        $modifier->products()->attach($pizza->id);

        $option = new ModifierOption([
            'modifier_id' => $modifier->id,
            'name' => 'Cheese burst',
            'price' => 70,
            'is_active' => true,
        ]);
        $option->save();

        $session = $this->seat($this->table());

        app(TableCartService::class)->add($session, $pizza, $variant->id, 1, [$option->id]);
        app(TableOrderService::class)->place($session);

        $invoice = app(TableBillService::class)->settle($session->fresh(), [
            'payments' => [['method' => 'cash', 'amount' => 290]],
        ]);

        $line = $invoice->items()->firstOrFail();

        $this->assertSame('Margherita (7 inch) — Cheese burst', $line->product_name);
        // 220 for the size, 70 for the add-on - the figure on the phone.
        $this->assertSame(290.0, (float) $line->line_total);
    }

    /**
     * The one that keeps a menu change from rewriting a meal already eaten.
     */
    public function test_the_bill_charges_what_the_guest_was_shown(): void
    {
        $dish = $this->dish(['name' => 'Dal Makhani', 'selling_price' => 280]);
        $session = $this->ate([[$dish, 1]]);

        // The kitchen re-prices at nine. The table ordered at eight.
        $dish->forceFill(['selling_price' => 400])->save();

        $invoice = app(TableBillService::class)->settle($session, [
            'payments' => [['method' => 'cash', 'amount' => 280]],
        ]);

        $this->assertSame(280.0, (float) $invoice->grand_total);
    }

    /* ----------------------------------------------------------- the split */

    public function test_a_split_by_item_leaves_the_rest_on_the_table(): void
    {
        $pizza = $this->dish(['name' => 'Margherita', 'selling_price' => 300]);
        $soda = $this->dish(['name' => 'Lime Soda', 'selling_price' => 80]);

        $session = $this->ate([[$pizza, 1], [$soda, 2]]);

        $bills = app(TableBillService::class);
        $lines = $bills->outstanding($session);

        $pizzaLine = $lines->firstWhere('product_id', $pizza->id);

        $first = $bills->settle($session, [
            'lines' => [['order_item_id' => $pizzaLine->id, 'quantity' => 1]],
            'payments' => [['method' => 'card', 'amount' => 300]],
        ]);

        $this->assertSame(300.0, (float) $first->grand_total);

        // The sitting is billed, not closed: somebody still owes for the sodas.
        $session = $session->fresh();
        $this->assertSame(TableSession::BILLED, $session->status);
        $this->assertSame(160.0, $bills->summary($session)['unbilled']);

        $second = $bills->settle($session, [
            'payments' => [['method' => 'cash', 'amount' => 160]],
        ]);

        $this->assertSame(160.0, (float) $second->grand_total);
        $this->assertSame(TableSession::CLOSED, $session->fresh()->status);
        $this->assertSame(2, $session->fresh()->invoices()->count());
    }

    /** "Three of the five beers are mine" - what a flag could not express. */
    public function test_a_split_by_quantity_divides_one_line(): void
    {
        $beer = $this->dish(['name' => 'Kingfisher', 'selling_price' => 200]);
        $session = $this->ate([[$beer, 5]]);

        $bills = app(TableBillService::class);
        $line = $bills->outstanding($session)->firstOrFail();

        $bills->settle($session, [
            'lines' => [['order_item_id' => $line->id, 'quantity' => 3]],
            'payments' => [['method' => 'cash', 'amount' => 600]],
        ]);

        $this->assertSame(3.0, (float) $line->fresh()->settled_quantity);
        $this->assertSame(2.0, $line->fresh()->unsettledQuantity());
        $this->assertSame(400.0, $bills->summary($session->fresh())['unbilled']);
    }

    public function test_billing_more_than_is_left_is_refused(): void
    {
        $beer = $this->dish(['name' => 'Kingfisher', 'selling_price' => 200]);
        $session = $this->ate([[$beer, 2]]);

        $bills = app(TableBillService::class);
        $line = $bills->outstanding($session)->firstOrFail();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is left to bill');

        $bills->settle($session, [
            'lines' => [['order_item_id' => $line->id, 'quantity' => 5]],
        ]);
    }

    public function test_a_line_from_another_table_cannot_be_billed_here(): void
    {
        $dish = $this->dish();

        $theirs = $this->ate([[$dish, 1]], 'GF-04');
        $mine = $this->ate([[$dish, 1]], 'GF-05');

        $bills = app(TableBillService::class);
        $theirLine = $bills->outstanding($theirs)->firstOrFail();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not outstanding on this table');

        $bills->settle($mine, [
            'lines' => [['order_item_id' => $theirLine->id, 'quantity' => 1]],
        ]);
    }

    public function test_a_settled_table_cannot_be_billed_again(): void
    {
        $session = $this->ate([[$this->dish(), 1]]);

        $bills = app(TableBillService::class);
        $bills->settle($session, ['payments' => [['method' => 'cash', 'amount' => 280]]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already been settled');

        $bills->settle($session->fresh());
    }

    /* ------------------------------------------------------------- stock */

    /**
     * The failure that would make this feature useless in practice.
     */
    public function test_a_dish_bills_when_there_is_no_stock_of_it(): void
    {
        $dish = $this->dish(['name' => 'Butter Naan', 'selling_price' => 60, 'is_made_to_order' => true]);

        $session = $this->ate([[$dish, 3]]);

        $invoice = app(TableBillService::class)->settle($session, [
            'payments' => [['method' => 'cash', 'amount' => 180]],
        ]);

        $this->assertSame(180.0, (float) $invoice->grand_total);

        // And nothing moved, because there was never a count of it to move.
        $this->assertSame(
            0,
            StockMovement::allShops()->where('product_id', $dish->id)->count(),
        );
    }

    /**
     * The contrast, and the reason this is a flag rather than a rule.
     *
     * A bottle of water really is stock, and selling one the shop does not
     * have is still refused.
     */
    public function test_a_stocked_product_with_no_stock_is_still_refused(): void
    {
        $water = $this->dish([
            'name' => 'Bottled Water',
            'selling_price' => 20,
            'is_made_to_order' => false,
        ]);

        $session = $this->ate([[$water, 1]]);

        // Paid in full, so the refusal under test is the stock one and not
        // the credit-sale one.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Not enough stock');

        app(TableBillService::class)->settle($session, [
            'payments' => [['method' => 'cash', 'amount' => 20]],
        ]);
    }

    /* --------------------------------------------------- moving and merging */

    public function test_merging_moves_every_ticket_and_frees_the_table(): void
    {
        $dish = $this->dish(['name' => 'Butter Naan', 'selling_price' => 60]);

        $from = $this->ate([[$dish, 2]], 'GF-04');
        $into = $this->ate([[$dish, 1]], 'GF-05');

        app(TableBillService::class)->merge($from, $into);

        $from = $from->fresh();
        $into = $into->fresh();

        $this->assertSame(TableSession::CLOSED, $from->status);
        $this->assertStringContainsString('Merged into', (string) $from->closed_reason);
        $this->assertSame(RestaurantTable::CLEANING, $from->table->status);

        $this->assertSame(0, $from->orders()->count());
        $this->assertSame(2, $into->orders()->count());

        $this->assertSame(180.0, app(TableBillService::class)->summary($into)['unbilled']);
    }

    /**
     * A filed invoice names a sitting. Moving its food afterwards would leave
     * a tax document describing somebody else's table.
     */
    public function test_a_table_that_has_been_billed_cannot_be_merged_away(): void
    {
        $dish = $this->dish(['name' => 'Butter Naan', 'selling_price' => 60]);

        $from = $this->ate([[$dish, 2]], 'GF-04');
        $into = $this->ate([[$dish, 1]], 'GF-05');

        $bills = app(TableBillService::class);
        $line = $bills->outstanding($from)->firstOrFail();

        $bills->settle($from, [
            'lines' => [['order_item_id' => $line->id, 'quantity' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 60]],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already been billed');

        $bills->merge($from->fresh(), $into);
    }

    /**
     * The number stays. It has been called out across a kitchen.
     */
    public function test_moving_a_ticket_keeps_its_number(): void
    {
        $dish = $this->dish();

        $from = $this->ate([[$dish, 1]], 'GF-04');
        $into = $this->ate([[$dish, 1]], 'GF-05');

        $order = $from->orders()->firstOrFail();
        $number = $order->order_number;

        app(TableBillService::class)->moveOrder($order, $into);

        $order = $order->fresh();

        $this->assertSame($into->id, (int) $order->table_session_id);
        $this->assertSame($number, $order->order_number);
    }

    public function test_a_ticket_cannot_move_to_the_table_it_is_already_on(): void
    {
        $session = $this->ate([[$this->dish(), 1]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already on this table');

        app(TableBillService::class)->moveOrder($session->orders()->firstOrFail(), $session);
    }

    /* --------------------------------------------------------- the walkout */

    public function test_a_walkout_clears_the_table_and_is_logged(): void
    {
        $session = $this->ate([[$this->dish(['selling_price' => 280]), 1]]);

        app(TableBillService::class)->abandon($session, 'Walked out');

        $session = $session->fresh();

        $this->assertSame(TableSession::CLOSED, $session->status);
        $this->assertSame('Walked out', $session->closed_reason);

        // No invoice, because nothing was billed - which is the whole reason
        // this needs a right of its own.
        $this->assertSame(0, $session->invoices()->count());
    }

    public function test_a_table_with_a_bill_cannot_be_written_off(): void
    {
        $session = $this->ate([[$this->dish(['selling_price' => 280]), 2]]);

        $bills = app(TableBillService::class);
        $line = $bills->outstanding($session)->firstOrFail();

        $bills->settle($session, [
            'lines' => [['order_item_id' => $line->id, 'quantity' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 280]],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has a bill against it');

        $bills->abandon($session->fresh(), 'Changed my mind');
    }

    /* ---------------------------------------------------------- the screens */

    public function test_the_table_list_shows_what_is_owed(): void
    {
        $this->ate([[$this->dish(['name' => 'Butter Naan', 'selling_price' => 60]), 3]]);

        $this->actingAs($this->cashier())
            ->get('/admin/table-bills')
            ->assertOk()
            ->assertSee('GF-04')
            ->assertSee('180.00');
    }

    public function test_the_bill_screen_lists_the_rounds(): void
    {
        $session = $this->ate([[$this->dish(['name' => 'Butter Naan', 'selling_price' => 60]), 3]]);

        $this->actingAs($this->cashier())
            ->get('/admin/table-bills/'.$session->id)
            ->assertOk()
            ->assertSee('Butter Naan')
            ->assertSee('180.00');
    }

    public function test_a_cashier_settles_over_http(): void
    {
        $session = $this->ate([[$this->dish(['name' => 'Butter Naan', 'selling_price' => 60]), 3]]);

        $this->actingAs($this->cashier())
            ->postJson('/admin/table-bills/'.$session->id.'/settle', [
                'payments' => [['method' => 'cash', 'amount' => 180]],
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.remaining', 0);

        $this->assertSame(TableSession::CLOSED, $session->fresh()->status);
        $this->assertSame(1, Invoice::allShops()->where('table_session_id', $session->id)->count());
    }

    /* ------------------------------------------------------ the gatekeepers */

    /** A bill with nothing tendered is a credit sale whatever it is called. */
    public function test_settling_without_taking_money_needs_the_credit_right(): void
    {
        $session = $this->ate([[$this->dish(), 1]]);

        $this->actingAs($this->cashier())
            ->postJson('/admin/table-bills/'.$session->id.'/settle', [])
            ->assertForbidden();

        $this->assertSame(TableSession::OPEN, $session->fresh()->status);
    }

    public function test_a_discount_needs_the_override_right(): void
    {
        $session = $this->ate([[$this->dish(['selling_price' => 280]), 1]]);

        $this->actingAs($this->cashier())
            ->postJson('/admin/table-bills/'.$session->id.'/settle', [
                'invoice_discount' => 50,
                'payments' => [['method' => 'cash', 'amount' => 230]],
            ])
            ->assertForbidden();
    }

    public function test_moving_a_ticket_needs_its_own_right(): void
    {
        $from = $this->ate([[$this->dish(), 1]], 'GF-04');
        $into = $this->ate([[$this->dish(['name' => 'Raita']), 1]], 'GF-05');

        $this->actingAs($this->cashier())
            ->putJson('/admin/table-bills/'.$from->id.'/merge', ['into' => $into->id])
            ->assertForbidden();

        $this->assertSame(TableSession::OPEN, $from->fresh()->status);
    }

    public function test_clearing_a_table_unpaid_needs_its_own_right(): void
    {
        $session = $this->ate([[$this->dish(), 1]]);

        $this->actingAs($this->cashier())
            ->putJson('/admin/table-bills/'.$session->id.'/write-off', ['reason' => 'Nope'])
            ->assertForbidden();

        $this->assertSame(TableSession::OPEN, $session->fresh()->status);
    }

    public function test_the_reason_for_a_walkout_is_required(): void
    {
        $session = $this->ate([[$this->dish(), 1]]);

        $this->actingAs($this->cashier(['pos.tables.write_off']))
            ->putJson('/admin/table-bills/'.$session->id.'/write-off', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    /**
     * A branch with no dining room has no tables to bill, so the screen goes
     * with the module rather than standing there able only to list nothing.
     */
    public function test_a_shop_with_no_dining_has_no_table_billing(): void
    {
        $this->shop()->forceFill(['modules' => ['retail', 'inventory', 'pos']])->save();
        CurrentShop::forget();

        $this->actingAs($this->cashier())
            ->get('/admin/table-bills')
            ->assertForbidden();
    }

    public function test_without_the_right_the_screen_is_closed(): void
    {
        $stranger = User::create([
            'tenant_id' => $this->shop()->tenant_id,
            'name' => 'Stranger',
            'email' => 'nobody@example.test',
            'password' => 'stranger-password-1',
            'is_admin' => true,
        ]);

        $stranger->shops()->syncWithoutDetaching([$this->shop()->id => ['is_default' => true]]);
        $stranger->forceFill([
            'current_shop_id' => $this->shop()->id,
            'all_shops_view' => false,
        ])->save();

        $this->actingAs($stranger)->get('/admin/table-bills')->assertForbidden();
    }

    /* ---------------------------------------------------------- the kitchen */

    /**
     * Said, not enforced.
     *
     * A guest asking for the bill while the last dish is on the pass is a
     * normal Tuesday. A rule here would get in the way of somebody leaving.
     */
    public function test_food_still_in_the_kitchen_is_counted_but_does_not_block(): void
    {
        $session = $this->ate([[$this->dish(['name' => 'Butter Naan', 'selling_price' => 60]), 2]]);

        $bills = app(TableBillService::class);

        $this->assertSame(2.0, $bills->summary($session)['in_kitchen']);

        $invoice = $bills->settle($session, [
            'payments' => [['method' => 'cash', 'amount' => 120]],
        ]);

        $this->assertSame(120.0, (float) $invoice->grand_total);
    }

    /* ------------------------------------------------- taking the order (§6) */

    /**
     * A captain with a pad puts food on the same table a phone does.
     */
    public function test_a_captain_takes_an_order_and_sends_it_to_the_kitchen(): void
    {
        $naan = $this->dish(['name' => 'Butter Naan', 'selling_price' => 60]);

        $session = $this->seat($this->table());
        $captain = $this->cashier(['pos.tables.order']);

        $this->actingAs($captain)
            ->postJson('/admin/table-bills/'.$session->id.'/cart', [
                'product_id' => $naan->id,
                'quantity' => 3,
                'note' => 'Well done',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        // On the pad, not yet on the bill: nothing is cooked and nothing owed.
        $this->assertSame(1, $session->cartItems()->count());
        $this->assertSame(0.0, app(TableBillService::class)->summary($session)['unbilled']);

        $this->actingAs($captain)
            ->postJson('/admin/table-bills/'.$session->id.'/send')
            ->assertOk()
            ->assertJsonPath('success', true);

        $session = $session->fresh();

        $this->assertSame(0, $session->cartItems()->count());
        $this->assertSame(1, $session->orders()->count());
        $this->assertSame(180.0, app(TableBillService::class)->summary($session)['unbilled']);

        // And it is a kitchen ticket like any other.
        $line = $session->orders()->firstOrFail()->items()->firstOrFail();

        $this->assertSame(Order::PENDING, $line->kitchen_status);
        $this->assertSame('Well done', $line->note);
    }

    /**
     * The same rules, because it is the same cart.
     *
     * A captain who could add a dish without naming its size is a table billed
     * for a Full and served a Half.
     */
    public function test_a_captain_is_held_to_the_same_rules_as_a_phone(): void
    {
        $pizza = $this->dish(['name' => 'Margherita', 'selling_price' => 200]);

        $variant = new ProductVariant([
            'product_id' => $pizza->id,
            'name' => '7 inch',
            'price' => 220,
            'is_default' => true,
            'is_active' => true,
        ]);
        $variant->save();

        $session = $this->seat($this->table());

        $this->actingAs($this->cashier(['pos.tables.order']))
            ->postJson('/admin/table-bills/'.$session->id.'/cart', [
                'product_id' => $pizza->id,
                'quantity' => 1,
            ])
            // ApiResponse::error answers 422, the same as every other refusal.
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame(0, $session->cartItems()->count());
    }

    public function test_a_line_can_be_taken_off_the_pad_before_it_is_sent(): void
    {
        $naan = $this->dish(['name' => 'Butter Naan', 'selling_price' => 60]);

        $session = $this->seat($this->table());
        $captain = $this->cashier(['pos.tables.order']);

        $this->actingAs($captain)
            ->postJson('/admin/table-bills/'.$session->id.'/cart', [
                'product_id' => $naan->id,
                'quantity' => 2,
            ])
            ->assertOk();

        $line = $session->cartItems()->firstOrFail();

        $this->actingAs($captain)
            ->deleteJson('/admin/table-bills/'.$session->id.'/cart/'.$line->id)
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(0, $session->cartItems()->count());
    }

    public function test_taking_an_order_needs_its_own_right(): void
    {
        $naan = $this->dish(['name' => 'Butter Naan', 'selling_price' => 60]);
        $session = $this->seat($this->table());

        // A cashier settles; taking the order is the floor's job.
        $this->actingAs($this->cashier())
            ->postJson('/admin/table-bills/'.$session->id.'/cart', [
                'product_id' => $naan->id,
                'quantity' => 1,
            ])
            ->assertForbidden();

        $this->assertSame(0, $session->cartItems()->count());
    }

    /* ------------------------------------------------------ the order list */

    /**
     * Every channel writes to `orders`, so the history screen shows all four
     * and the filter is what narrows it back to one.
     */
    public function test_a_dine_in_ticket_appears_in_the_order_history(): void
    {
        $session = $this->ate([[$this->dish(['name' => 'Butter Naan', 'selling_price' => 60]), 2]]);
        $order = $session->orders()->firstOrFail();

        $reader = $this->cashier(['sales.orders.view']);

        $this->actingAs($reader)
            ->get('/admin/orders')
            ->assertOk()
            ->assertSee($order->order_number)
            ->assertSee('Dine-in');

        // And the filter actually filters.
        $this->actingAs($reader)
            ->get('/admin/orders?type=online')
            ->assertOk()
            ->assertDontSee($order->order_number);
    }

    /** A ticket nobody made and nobody ate is not owed for. */
    public function test_a_cancelled_ticket_is_not_billed(): void
    {
        $session = $this->ate([[$this->dish(['selling_price' => 280]), 1]]);

        $session->orders()->firstOrFail()->forceFill(['status' => Order::CANCELLED])->save();

        $this->assertSame(0.0, app(TableBillService::class)->summary($session->fresh())['unbilled']);
    }
}
