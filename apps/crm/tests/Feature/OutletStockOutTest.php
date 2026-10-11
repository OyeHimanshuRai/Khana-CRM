<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Support\CurrentShop;
use App\Support\StockOuts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Khana\Kit\Events\Inbox\InboxEvent;
use Khana\Kit\Support\Ulid;
use Khana\Kit\Tenancy\Entitlements;
use Tests\Concerns\RunsTwoOutlets;
use Tests\Concerns\TalksToErp;
use Tests\TestCase;

/**
 * Sold-out from stock is one outlet's truth, not the business's.
 *
 * ERP says a dish ran out with erp.availability.changed, and the event names
 * one outlet. Two outlets of one business share a menu, so the proof is
 * always the same shape: Riverside runs out, and the first outlet's guests,
 * cashier and API client go on selling the dish.
 *
 * Every stock-out here arrives the way a real one does - a signed batch
 * posted to /internal/events - and is read back where somebody is told
 * whether they can order: the guest's menu and cart, the POS grid, the API,
 * the storefront, and the admin's own list.
 */
class OutletStockOutTest extends TestCase
{
    use RefreshDatabase, RunsTwoOutlets, TalksToErp;

    /** The seeded outlet. Stock never runs out here in these tests. */
    private Shop $main;

    /** A second outlet of the same business. Stock runs out here. */
    private Shop $riverside;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');
        CurrentShop::forget();

        $this->main = Shop::query()->orderBy('id')->firstOrFail();
        $this->riverside = $this->anotherOutlet($this->main);

        $this->linkToAccounts($this->main);
        $this->linkToAccounts($this->riverside);

        $this->main = $this->main->fresh();
        $this->riverside = $this->riverside->fresh();
    }

    protected function tearDown(): void
    {
        CurrentShop::forget();

        parent::tearDown();
    }

    /** @return list<array{int, int}> the rows held for one outlet, as [dish, size] */
    private function rowsAt(Shop $shop): array
    {
        return DB::table('product_stock_outs')
            ->where('shop_id', $shop->id)
            ->orderBy('product_id')
            ->orderBy('product_variant_id')
            ->get()
            ->map(fn (object $row) => [(int) $row->product_id, (int) $row->product_variant_id])
            ->all();
    }

    /** @param  array<int, string>  $abilities */
    private function tokenAt(Shop $shop, array $abilities): array
    {
        $user = $this->staffAt($shop, []);

        return [
            'Authorization' => 'Bearer '.$user->createToken('outlet integration', $abilities)->plainTextToken,
            'Accept' => 'application/json',
        ];
    }

    /* ------------------------------------------------------ what is kept */

    public function test_a_stock_out_is_kept_for_the_outlet_it_names_and_the_staff_switches_are_left_alone(): void
    {
        $dish = $this->dishNamed('Paneer Tikka');
        $dish->forceFill(['sold_out_until' => now()->addHour()])->save();

        $event = $this->stockEvent($this->riverside, $dish, false);
        $this->deliverFromErp($event)->assertOk()->assertJson(['stored' => 1]);

        $this->assertSame(InboxEvent::APPLIED, InboxEvent::query()->where('event_id', $event['id'])->value('status'));
        $this->assertSame([[$dish->id, 0]], $this->rowsAt($this->riverside));
        $this->assertSame([], $this->rowsAt($this->main));

        $fresh = $dish->fresh();

        $this->assertFalse($fresh->is_sold_out, 'the hand flag is staff\'s, and stock does not throw it');
        $this->assertNotNull($fresh->sold_out_until, 'nor does stock touch its timer');

        $this->assertTrue($fresh->isSoldOut(null, $this->riverside->id));
        $this->assertSame('Out of stock', $fresh->unavailableReason(null, $this->riverside->id));
        $this->assertFalse($fresh->isOrderable(null, $this->riverside->id));

        $this->assertFalse($fresh->isSoldOut(null, $this->main->id));
        $this->assertTrue($fresh->isOrderable(null, $this->main->id));
        $this->assertNull($fresh->unavailableReason(null, $this->main->id));

        // No outlet in particular - the consolidated view - has no stock of
        // its own to ask: only the staff flag answers.
        $this->assertFalse($fresh->isSoldOut());

        $this->comesBackAt($this->riverside, $dish);

        $this->assertSame([], $this->rowsAt($this->riverside));
        $this->assertTrue($dish->fresh()->isOrderable(null, $this->riverside->id));
    }

    public function test_a_dish_staff_took_off_is_off_everywhere_and_stock_coming_back_does_not_bring_it_back(): void
    {
        $dish = $this->dishNamed('Prawn Curry', 320, ['is_sold_out' => true]);

        $this->comesBackAt($this->riverside, $dish);

        $this->assertTrue($dish->fresh()->is_sold_out);
        $this->assertSame('Sold out', $dish->fresh()->unavailableReason(null, $this->riverside->id));
        $this->assertSame('Sold out', $dish->fresh()->unavailableReason(null, $this->main->id));
    }

    public function test_an_outlet_crm_has_no_shop_for_is_logged_and_passed_over(): void
    {
        Log::spy();

        $dish = $this->dishNamed('Dal Tadka');

        // Accounts has told CRM about the outlet, and it owns CRM - but
        // nobody has signed in to it yet, so CRM has made no shop for it.
        $outlet = Ulid::generate();
        $tenant = DB::table('tenants')->where('id', $this->main->tenant_id)->value('ulid');

        DB::table('khana_outlets')->insert([
            'id' => $outlet, 'tenant_id' => $tenant, 'name' => 'Airport', 'code' => 'AIR',
            'address' => json_encode(['line1' => '1 Road', 'line2' => null, 'city' => 'Delhi', 'pincode' => null]),
            'state_code' => '07', 'timezone' => 'Asia/Kolkata', 'day_cutoff' => '04:00',
            'status' => 'active', 'changed_at' => '2026-09-26 00:00:00',
        ]);

        foreach (['crm', 'erp'] as $product) {
            DB::table('khana_entitlements')->insert([
                'tenant_id' => $tenant, 'outlet_id' => $outlet, 'product' => $product, 'state' => 'active',
                'plan' => $product, 'limits' => '{}', 'changed_at' => '2026-09-26 00:00:00',
            ]);
        }

        app(Entitlements::class)->forget();

        $unknown = $this->stockEvent($this->riverside, $dish, false);
        $unknown['outlet_id'] = $outlet;
        $after = $this->stockEvent($this->riverside, $dish, false);

        $this->deliverFromErp($unknown, $after)->assertOk()->assertJson(['stored' => 2]);

        // Handled and passed over - not left waiting, not failed.
        $this->assertSame(InboxEvent::APPLIED, InboxEvent::query()->where('event_id', $unknown['id'])->value('status'));
        $this->assertSame(InboxEvent::APPLIED, InboxEvent::query()->where('event_id', $after['id'])->value('status'));
        $this->assertSame(1, DB::table('product_stock_outs')->count());
        $this->assertSame([[$dish->id, 0]], $this->rowsAt($this->riverside));

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message) => str_contains($message, "Skipped erp.availability.changed {$unknown['id']}")
                && str_contains($message, "no outlet {$outlet}"))
            ->once();
    }

    /* ------------------------------------------- the guest at the table */

    public function test_a_guest_at_the_other_outlet_still_orders_the_dish(): void
    {
        $dish = $this->dishNamed('Paneer Tikka', 280);
        $riversideTable = $this->tableAt($this->riverside, 'RV-01');
        $mainTable = $this->tableAt($this->main, 'GF-01');

        $this->runsOutAt($this->riverside, $dish);

        // Riverside: shown, marked, and refused if posted by hand.
        $riversideSitting = $this->seatAt($riversideTable);

        $this->get('/t')->assertOk()->assertSee('Paneer Tikka')->assertSee('Out of stock');
        $this->post('/t/cart', ['product_id' => $dish->id])
            ->assertSessionHas('table_error', 'Out of stock');
        $this->assertSame(0, $riversideSitting->cartItems()->count());

        // The first outlet: on the menu, into the cart, to the kitchen.
        $mainSitting = $this->seatAt($mainTable);

        $this->get('/t')->assertOk()->assertSee('Paneer Tikka')->assertDontSee('Out of stock');
        $this->post('/t/cart', ['product_id' => $dish->id])->assertSessionMissing('table_error');
        $this->assertSame(1, $mainSitting->cartItems()->count());

        $this->post('/t/order')->assertRedirect(route('table.orders'));
        $this->assertSame(1, Order::allShops()->where('shop_id', $this->main->id)->count());
    }

    /** The check when the order is sent is the one that matters. */
    public function test_a_dish_that_runs_out_after_it_was_added_blocks_that_outlets_order_only(): void
    {
        $dish = $this->dishNamed('Fish Fry', 260);
        $riversideTable = $this->tableAt($this->riverside, 'RV-02');
        $mainTable = $this->tableAt($this->main, 'GF-02');

        $riversideSitting = $this->seatAt($riversideTable);
        $this->post('/t/cart', ['product_id' => $dish->id])->assertSessionMissing('table_error');

        $mainSitting = $this->seatAt($mainTable);
        $this->post('/t/cart', ['product_id' => $dish->id])->assertSessionMissing('table_error');

        $this->runsOutAt($this->riverside, $dish);

        // Riverside's cart keeps the line, marks it, and will not send it.
        $this->seatAt($riversideTable);
        $this->get('/t/cart')->assertOk()->assertSee('Out of stock');
        $this->post('/t/order')->assertSessionHas('table_error');
        $this->assertSame(0, Order::allShops()->where('shop_id', $this->riverside->id)->count());
        $this->assertSame(1, $riversideSitting->cartItems()->count());

        $this->seatAt($mainTable);
        $this->get('/t/cart')->assertOk()->assertDontSee('Out of stock');
        $this->post('/t/order')->assertRedirect(route('table.orders'));
        $this->assertSame(1, Order::allShops()->where('shop_id', $this->main->id)->count());
        $this->assertSame(0, $mainSitting->cartItems()->count());
    }

    /* ----------------------------------------------------------- the till */

    public function test_the_other_outlets_pos_still_offers_the_dish(): void
    {
        $dish = $this->dishNamed('Paneer Tikka', 280);

        $this->runsOutAt($this->riverside, $dish);

        $riverside = $this->actingAs($this->staffAt($this->riverside, ['pos.terminal.view']))
            ->get('/admin/pos')->assertOk()->getContent();

        $this->assertStringContainsString('data-off="Out of stock"', $riverside);

        $this->asNobody();

        $main = $this->actingAs($this->staffAt($this->main, ['pos.terminal.view']))
            ->get('/admin/pos')->assertOk()->getContent();

        $this->assertStringContainsString('Paneer Tikka', $main);
        $this->assertStringNotContainsString('data-off="Out of stock"', $main);
    }

    /** A captain taking the order at the table is told the same thing. */
    public function test_the_captains_pad_follows_the_outlet_the_table_is_in(): void
    {
        $dish = $this->dishNamed('Biryani', 300);
        $this->sizeOf($dish, 'Half', 180, default: true);
        $full = $this->sizeOf($dish, 'Full', 320);

        $this->runsOutAt($this->riverside, $dish, $full);

        $sitting = $this->seatAt($this->tableAt($this->riverside, 'RV-03'));
        $this->asNobody();

        $captain = $this->staffAt($this->riverside, ['pos.tables.view', 'pos.tables.order']);

        $this->actingAs($captain)
            ->get('/admin/table-bills/'.$sitting->id.'/order')
            ->assertOk()
            ->assertSee('Full')
            ->assertSee('not available');

        $this->actingAs($captain)
            ->postJson('/admin/table-bills/'.$sitting->id.'/cart', [
                'product_id' => $dish->id, 'product_variant_id' => $full->id, 'quantity' => 1,
            ])
            ->assertJsonPath('success', false);

        $this->assertSame(0, $sitting->cartItems()->count());
    }

    /* ------------------------------------------------------------ the API */

    public function test_the_other_outlets_api_client_still_sells_the_dish(): void
    {
        $dish = $this->dishNamed('Gajar Halwa', 120);

        $this->runsOutAt($this->riverside, $dish);

        $riverside = $this->tokenAt($this->riverside, ['menu:read', 'orders:write']);

        $this->getJson('/api/v1/menu/'.$dish->id, $riverside)
            ->assertOk()
            ->assertJsonPath('data.is_sold_out', true)
            ->assertJsonPath('data.is_available', false);

        $refused = $this->postJson('/api/v1/orders', [
            'order_type' => Order::TAKEAWAY,
            'items' => [['product_id' => $dish->id, 'quantity' => 1]],
        ], $riverside);

        $refused->assertStatus(409);
        $this->assertStringContainsString('Gajar Halwa', $refused->json('message'));

        $this->asNobody();

        $main = $this->tokenAt($this->main, ['menu:read', 'orders:write']);

        $this->getJson('/api/v1/menu/'.$dish->id, $main)
            ->assertOk()
            ->assertJsonPath('data.is_sold_out', false)
            ->assertJsonPath('data.is_available', true);

        $this->postJson('/api/v1/orders', [
            'order_type' => Order::TAKEAWAY,
            'items' => [['product_id' => $dish->id, 'quantity' => 1]],
        ], $main)->assertStatus(201);

        $this->assertSame(1, Order::allShops()->where('shop_id', $this->main->id)->count());
        $this->assertSame(0, Order::allShops()->where('shop_id', $this->riverside->id)->count());
    }

    /**
     * The API's own switch is the one staff throw by hand. It does not put
     * back a dish stock took off - an integration pushing "available" on a
     * timer would undo every stock-out - and its answer says so.
     */
    public function test_the_api_switch_does_not_override_stock(): void
    {
        $dish = $this->dishNamed('Malai Kofta', 240);

        $this->runsOutAt($this->riverside, $dish);

        $this->putJson('/api/v1/menu/'.$dish->id.'/availability', ['is_sold_out' => false], $this->tokenAt($this->riverside, ['menu:write']))
            ->assertOk()
            ->assertJsonPath('data.is_sold_out', true);

        $this->assertSame([[$dish->id, 0]], $this->rowsAt($this->riverside));
        $this->assertFalse($dish->fresh()->is_sold_out);
    }

    /* ------------------------------------------------------ the storefront */

    public function test_the_other_outlets_storefront_still_offers_the_dish(): void
    {
        $dish = $this->dishNamed('Masala Dosa', 140, ['is_published' => true]);

        $this->runsOutAt($this->riverside, $dish);

        $this->get(route('shop.product', [$this->riverside, $dish]))
            ->assertOk()
            ->assertSee('Out of stock')
            ->assertDontSee('In stock');

        $this->get(route('shop.catalog', $this->riverside))->assertOk()->assertSee('Out of stock');

        $this->get(route('shop.product', [$this->main, $dish]))
            ->assertOk()
            ->assertSee('In stock')
            ->assertDontSee('Out of stock');

        $this->get(route('shop.catalog', $this->main))->assertOk()->assertDontSee('Out of stock');
    }

    /* ------------------------------------------------------------- sizes */

    public function test_a_stock_out_for_one_size_takes_off_that_size_at_that_outlet_only(): void
    {
        $dish = $this->dishNamed('Biryani', 300);
        $half = $this->sizeOf($dish, 'Half', 180, default: true);
        $full = $this->sizeOf($dish, 'Full', 320);

        $this->runsOutAt($this->riverside, $dish, $full);

        $this->assertSame([[$dish->id, $full->id]], $this->rowsAt($this->riverside));
        $this->assertTrue($full->fresh()->is_available, 'the staff switch for the size is left alone');

        $this->assertFalse($full->fresh()->isAvailableAt($this->riverside->id));
        $this->assertTrue($half->fresh()->isAvailableAt($this->riverside->id));
        $this->assertTrue($full->fresh()->isAvailableAt($this->main->id));
        $this->assertFalse($dish->fresh()->isSoldOut(null, $this->riverside->id), 'a Half can still be had');
        $this->assertSame(['Full'], $dish->fresh()->sizesOutOfStock($this->riverside->id));

        // At the table: the Full is refused at Riverside and nowhere else.
        $riversideSitting = $this->seatAt($this->tableAt($this->riverside, 'RV-04'));

        $this->get('/t')->assertOk()->assertSee('Biryani')->assertSee('Not available');
        $this->post('/t/cart', ['product_id' => $dish->id, 'product_variant_id' => $full->id])
            ->assertSessionHas('table_error', 'Full is not available right now.');
        $this->post('/t/cart', ['product_id' => $dish->id, 'product_variant_id' => $half->id])
            ->assertSessionMissing('table_error');
        $this->assertSame(1, $riversideSitting->cartItems()->count());

        $mainSitting = $this->seatAt($this->tableAt($this->main, 'GF-04'));

        $this->get('/t')->assertOk()->assertDontSee('Not available');
        $this->post('/t/cart', ['product_id' => $dish->id, 'product_variant_id' => $full->id])
            ->assertSessionMissing('table_error');
        $this->assertSame(1, $mainSitting->cartItems()->count());

        // And through the API.
        $riverside = $this->tokenAt($this->riverside, ['menu:read', 'orders:write']);

        $sizes = collect($this->getJson('/api/v1/menu/'.$dish->id, $riverside)->assertOk()->json('data.variants'))
            ->pluck('is_available', 'name')->all();

        $this->assertSame(['Half' => true, 'Full' => false], $sizes);

        $this->postJson('/api/v1/orders', [
            'order_type' => Order::TAKEAWAY,
            'items' => [['product_id' => $dish->id, 'variant_id' => $full->id, 'quantity' => 1]],
        ], $riverside)->assertStatus(409);

        $this->asNobody();

        $this->postJson('/api/v1/orders', [
            'order_type' => Order::TAKEAWAY,
            'items' => [['product_id' => $dish->id, 'variant_id' => $full->id, 'quantity' => 1]],
        ], $this->tokenAt($this->main, ['orders:write']))->assertStatus(201);
    }

    public function test_a_dish_whose_every_size_is_out_is_out(): void
    {
        $dish = $this->dishNamed('Biryani', 300);
        $half = $this->sizeOf($dish, 'Half', 180, default: true);
        $full = $this->sizeOf($dish, 'Full', 320);

        $this->runsOutAt($this->riverside, $dish, $half);

        $this->assertFalse($dish->fresh()->isSoldOut(null, $this->riverside->id));

        $this->runsOutAt($this->riverside, $dish, $full);

        $this->assertTrue($dish->fresh()->isSoldOut(null, $this->riverside->id));
        $this->assertSame('Out of stock', $dish->fresh()->unavailableReason(null, $this->riverside->id));
        $this->assertFalse($dish->fresh()->isSoldOut(null, $this->main->id));

        $this->seatAt($this->tableAt($this->riverside, 'RV-05'));
        $this->get('/t')->assertOk()->assertSee('Out of stock');

        // One size comes back, and so does the dish.
        $this->comesBackAt($this->riverside, $dish, $half);

        $this->assertFalse($dish->fresh()->isSoldOut(null, $this->riverside->id));
    }

    /** "Every size staff have left on" - a size they switched off is theirs. */
    public function test_sizes_staff_switched_off_do_not_count_towards_every_size(): void
    {
        $dish = $this->dishNamed('Pizza', 300);
        $small = $this->sizeOf($dish, 'Small', 200, default: true);
        $large = $this->sizeOf($dish, 'Large', 400);

        $small->forceFill(['is_available' => false])->save();

        // Nothing from stock yet: every size off by hand is not "out of stock".
        $this->assertFalse($dish->fresh()->isOutOfStock($this->riverside->id));

        $this->runsOutAt($this->riverside, $dish, $large);

        // The only size staff left on is the one stock cannot cover.
        $this->assertTrue($dish->fresh()->isOutOfStock($this->riverside->id));
        $this->assertFalse($dish->fresh()->isOutOfStock($this->main->id));
    }

    /* ------------------------------------------------- staff can override */

    public function test_staff_can_put_a_dish_back_against_stock_at_their_own_outlet(): void
    {
        $dish = $this->dishNamed('Paneer Tikka', 280);
        $half = $this->sizeOf($dish, 'Half', 160, default: true);
        $full = $this->sizeOf($dish, 'Full', 280);

        $this->runsOutAt($this->riverside, $dish, $half);
        $this->runsOutAt($this->riverside, $dish, $full);
        $this->runsOutAt($this->main, $dish, $full);

        $mark = $this->outboxMark();

        $response = $this->actingAs($this->staffAt($this->riverside, ['inventory.products.view', 'inventory.products.edit']))
            ->putJson("/admin/products/{$dish->id}/sold-out")
            ->assertOk()
            ->assertJsonPath('data.is_sold_out', false);

        // Said in plain words: it is back, and it will not stay back for ever.
        $this->assertStringContainsString('back on the menu here', $response->json('message'));
        $this->assertStringContainsString('Stock still says it has run out', $response->json('message'));
        $this->assertStringContainsString('tonight', $response->json('message'));

        $this->assertSame([], $this->rowsAt($this->riverside), 'the dish and its sizes are all put back');
        $this->assertSame([[$dish->id, $full->id]], $this->rowsAt($this->main), 'another outlet\'s stock is its own');
        $this->assertTrue($dish->fresh()->isOrderable(null, $this->riverside->id));
        $this->assertTrue($full->fresh()->isAvailableAt($this->riverside->id));

        $this->assertCount(0, $this->outboxSince($mark), 'an override is not sent to ERP');

        // Until ERP speaks about the dish again.
        $this->runsOutAt($this->riverside, $dish, $half);
        $this->runsOutAt($this->riverside, $dish, $full);

        $this->assertFalse($dish->fresh()->isOrderable(null, $this->riverside->id));
    }

    public function test_one_tap_clears_both_reasons(): void
    {
        $dish = $this->dishNamed('Prawn Curry', 320, ['is_sold_out' => true]);

        $this->runsOutAt($this->riverside, $dish);

        $this->actingAs($this->staffAt($this->riverside, ['inventory.products.view', 'inventory.products.edit']))
            ->putJson("/admin/products/{$dish->id}/sold-out")
            ->assertOk()
            ->assertJsonPath('data.is_sold_out', false);

        $this->assertFalse($dish->fresh()->is_sold_out);
        $this->assertSame([], $this->rowsAt($this->riverside));
        $this->assertTrue($dish->fresh()->isOrderable(null, $this->riverside->id));
    }

    public function test_taking_a_dish_off_by_hand_is_still_the_flag_and_leaves_stock_rows_alone(): void
    {
        $dish = $this->dishNamed('Biryani', 300);
        $this->sizeOf($dish, 'Half', 180, default: true);
        $full = $this->sizeOf($dish, 'Full', 320);

        // Only the Full is out at Riverside: the dish itself is on sale.
        $this->runsOutAt($this->riverside, $dish, $full);

        $chef = $this->staffAt($this->riverside, ['inventory.products.view', 'inventory.products.edit']);

        $this->actingAs($chef)->putJson("/admin/products/{$dish->id}/sold-out")
            ->assertOk()
            ->assertJsonPath('data.is_sold_out', true)
            ->assertJsonPath('message', '"Biryani" is off the menu.');

        $this->assertTrue($dish->fresh()->is_sold_out);
        $this->assertSame([[$dish->id, $full->id]], $this->rowsAt($this->riverside));

        // Undoing their own mark is not an override of stock: the Full,
        // which never made the dish sold out, stays out.
        $this->actingAs($chef)->putJson("/admin/products/{$dish->id}/sold-out")
            ->assertOk()
            ->assertJsonPath('data.is_sold_out', false)
            ->assertJsonPath('message', '"Biryani" is back on the menu.');

        $this->assertFalse($dish->fresh()->is_sold_out);
        $this->assertSame([[$dish->id, $full->id]], $this->rowsAt($this->riverside));
    }

    public function test_the_menu_list_tells_the_two_reasons_apart(): void
    {
        $this->dishNamed('Butter Chicken', 340, ['is_sold_out' => true]);
        $paneer = $this->dishNamed('Paneer Tikka', 280);
        $biryani = $this->dishNamed('Biryani', 300);
        $this->sizeOf($biryani, 'Half', 180, default: true);
        $full = $this->sizeOf($biryani, 'Full', 320);

        $this->runsOutAt($this->riverside, $paneer);
        $this->runsOutAt($this->riverside, $biryani, $full);

        $riverside = $this->actingAs($this->staffAt($this->riverside, ['inventory.products.view', 'inventory.products.edit']))
            ->get('/admin/products?per_page=100&q=')
            ->assertOk()
            ->assertSee('Sold out')
            ->assertSee('Out of stock')
            ->assertSee('Full out of stock')
            ->getContent();

        $this->assertSame(1, substr_count($riverside, '>Out of stock</span>'));

        $this->actingAs($this->staffAt($this->riverside, ['inventory.products.view']))
            ->get('/admin/products/'.$paneer->id)
            ->assertOk()
            ->assertSee('Out of stock here');

        $this->asNobody();

        // The first outlet sees the hand flag, which is the whole business's,
        // and none of Riverside's stock.
        $this->actingAs($this->staffAt($this->main, ['inventory.products.view']))
            ->get('/admin/products?per_page=100')
            ->assertOk()
            ->assertSee('Sold out')
            ->assertDontSee('Out of stock')
            ->assertDontSee('out of stock');
    }

    /* ------------------------------------------------------------ no echo */

    public function test_nothing_about_sold_out_goes_back_to_erp(): void
    {
        $dish = $this->dishNamed('Malai Kofta', 240);
        $half = $this->sizeOf($dish, 'Half', 140, default: true);

        $mark = $this->outboxMark();

        $this->runsOutAt($this->riverside, $dish);
        $this->runsOutAt($this->riverside, $dish, $half);
        $this->comesBackAt($this->riverside, $dish, $half);
        StockOuts::reconcile($this->main, [
            ['item_id' => $dish->ulid, 'variant_id' => null, 'available' => false, 'cost_paise' => null],
        ]);

        $chef = $this->staffAt($this->riverside, ['inventory.products.view', 'inventory.products.edit']);

        // Back against stock, off by hand, and back again.
        foreach ([1, 2, 3] as $tap) {
            $this->actingAs($chef)->putJson("/admin/products/{$dish->id}/sold-out")->assertOk();
        }

        $this->assertCount(0, $this->outboxSince($mark), 'sold-out, by stock or by hand, is not in crm.menu.item_upserted');
    }

    /* ---------------------------------------------- the nightly reconcile */

    public function test_reconcile_makes_an_outlets_rows_match_what_erp_holds(): void
    {
        $paneer = $this->dishNamed('Paneer Tikka', 280);
        $dal = $this->dishNamed('Dal Makhani', 220);
        $biryani = $this->dishNamed('Biryani', 300);
        $half = $this->sizeOf($biryani, 'Half', 180, default: true);
        $full = $this->sizeOf($biryani, 'Full', 320);

        // What CRM holds: paneer out (its "back" event was lost), the Half out.
        $this->runsOutAt($this->riverside, $paneer);
        $this->runsOutAt($this->riverside, $biryani, $half);
        $this->runsOutAt($this->main, $paneer);

        $counts = StockOuts::reconcile($this->riverside, [
            ['item_id' => $paneer->ulid, 'variant_id' => null, 'available' => true, 'cost_paise' => 8125],
            ['item_id' => $dal->ulid, 'variant_id' => null, 'available' => false, 'cost_paise' => null],
            ['item_id' => $biryani->ulid, 'variant_id' => $half->ulid, 'available' => false, 'cost_paise' => 6000],
            ['item_id' => $biryani->ulid, 'variant_id' => $full->ulid, 'available' => false, 'cost_paise' => 11000],
            // Ids CRM does not know are passed over.
            ['item_id' => Ulid::generate(), 'variant_id' => null, 'available' => false, 'cost_paise' => null],
            ['item_id' => $dal->ulid, 'variant_id' => Ulid::generate(), 'available' => false, 'cost_paise' => null],
            // A size named under the wrong dish is not that dish's size.
            ['item_id' => $paneer->ulid, 'variant_id' => $full->ulid, 'available' => false, 'cost_paise' => null],
        ]);

        $this->assertSame(['added' => 2, 'removed' => 1], $counts);
        $this->assertEqualsCanonicalizing(
            [[$dal->id, 0], [$biryani->id, $half->id], [$biryani->id, $full->id]],
            $this->rowsAt($this->riverside),
        );
        $this->assertSame([[$paneer->id, 0]], $this->rowsAt($this->main), 'one outlet at a time');

        // Read back at once, not from what was remembered before.
        $this->assertFalse($paneer->fresh()->isSoldOut(null, $this->riverside->id));
        $this->assertTrue($dal->fresh()->isSoldOut(null, $this->riverside->id));
        $this->assertTrue($biryani->fresh()->isSoldOut(null, $this->riverside->id));

        // A second run with the same answer changes nothing.
        $this->assertSame(['added' => 0, 'removed' => 0], StockOuts::reconcile($this->riverside, [
            ['item_id' => $dal->ulid, 'variant_id' => null, 'available' => false, 'cost_paise' => null],
            ['item_id' => $biryani->ulid, 'variant_id' => $half->ulid, 'available' => false, 'cost_paise' => 6000],
            ['item_id' => $biryani->ulid, 'variant_id' => $full->ulid, 'available' => false, 'cost_paise' => 11000],
        ]));

        // And an empty one clears the outlet.
        $this->assertSame(['added' => 0, 'removed' => 3], StockOuts::reconcile($this->riverside, []));
        $this->assertSame([], $this->rowsAt($this->riverside));
    }

    public function test_reconcile_re_applies_stock_over_a_staff_override(): void
    {
        $dish = $this->dishNamed('Paneer Tikka', 280);

        $this->runsOutAt($this->riverside, $dish);
        StockOuts::putBack($this->riverside->id, $dish->id);

        $this->assertTrue($dish->fresh()->isOrderable(null, $this->riverside->id));

        $this->assertSame(['added' => 1, 'removed' => 0], StockOuts::reconcile($this->riverside, [
            ['item_id' => $dish->ulid, 'variant_id' => null, 'available' => false, 'cost_paise' => null],
        ]));

        $this->assertFalse($dish->fresh()->isOrderable(null, $this->riverside->id));
    }

    public function test_the_dishes_out_at_an_outlet_are_listed_by_name(): void
    {
        $paneer = $this->dishNamed('Paneer Tikka', 280);
        $biryani = $this->dishNamed('Biryani', 300);
        $half = $this->sizeOf($biryani, 'Half', 180, default: true);
        $full = $this->sizeOf($biryani, 'Full', 320);
        $gone = $this->dishNamed('Old Special', 100);

        $this->runsOutAt($this->riverside, $paneer);
        $this->runsOutAt($this->riverside, $biryani, $full);
        $this->runsOutAt($this->riverside, $biryani, $half);
        $this->runsOutAt($this->riverside, $gone);

        // Archived dishes are on nobody's menu, whatever stock says.
        $gone->delete();

        $this->assertSame(
            [
                ['name' => 'Biryani', 'sizes' => ['Half', 'Full']],
                ['name' => 'Paneer Tikka', 'sizes' => []],
            ],
            StockOuts::dishesOutAt($this->riverside->id)->all(),
        );

        $this->assertSame([], StockOuts::dishesOutAt($this->main->id)->all());

        // The same two methods, called on an instance.
        $this->assertCount(2, app(StockOuts::class)->dishesOutAt($this->riverside->id));
    }

    public function test_the_home_screen_lists_what_stock_has_taken_off_the_menu_here(): void
    {
        // A Suite install knows where ERP lives; the card is drawn for a linked
        // outlet. The list itself reads CRM's own table, not ERP.
        config(['khana.urls.erp' => 'http://erp.suite.test']);

        $paneer = $this->dishNamed('Paneer Tikka', 280);
        $biryani = $this->dishNamed('Biryani', 300);
        $half = $this->sizeOf($biryani, 'Half', 180, default: true);

        $this->runsOutAt($this->riverside, $paneer);
        $this->runsOutAt($this->riverside, $biryani, $half);

        // A cashier who may not see the margin still sees what the kitchen
        // cannot serve, named, and the size when only a size is out.
        $this->actingAs($this->staffAt($this->riverside, []))
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Sold out by stock')
            ->assertSee('Paneer Tikka')
            ->assertSee('Biryani')
            ->assertSee('Half');

        $this->asNobody();

        // The other outlet has nothing off, so there is no card to draw.
        $this->actingAs($this->staffAt($this->main, []))
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertDontSee('Sold out by stock');
    }

    /* --------------------------------------------------------- read once */

    public function test_a_whole_menu_asks_the_table_once(): void
    {
        $dishes = collect(range(1, 12))->map(fn (int $n) => $this->dishNamed('Dish '.$n, 100 + $n));
        $biryani = $this->dishNamed('Biryani', 300);
        $full = $this->sizeOf($biryani, 'Full', 320, default: true);

        $this->runsOutAt($this->riverside, $dishes[3]);
        $this->runsOutAt($this->riverside, $biryani, $full);

        $menu = Product::query()->orderBy('id')->get();

        DB::enableQueryLog();

        $out = $menu->filter(fn (Product $dish) => $dish->isOutOfStock($this->riverside->id))->pluck('name')->all();

        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $this->assertEqualsCanonicalizing(['Dish 4', 'Biryani'], $out);
        $this->assertCount(1, $queries->filter(fn (string $sql) => str_contains($sql, 'product_stock_outs')));
        $this->assertLessThanOrEqual(2, $queries->count(), 'the rows, and the sizes of the dishes with a size out - no query per dish');
    }
}
