<?php

namespace Tests\Feature;

use App\Events\Outbound\OutletClock;
use App\Models\Floor;
use App\Models\Invoice;
use App\Models\Modifier;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\RestaurantTable;
use App\Models\SalesReturn;
use App\Models\SalesReturnItem;
use App\Models\Shop;
use App\Models\TableSession;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\User;
use App\Services\CashRegisterService;
use App\Services\InvoiceService;
use App\Services\KitchenService;
use App\Services\OrderService;
use App\Services\SalesReturnService;
use App\Services\TableBillService;
use App\Services\TableCartService;
use App\Services\TableOrderService;
use App\Services\TableQrService;
use App\Support\CurrentShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Khana\Kit\Events\OutboxEvent;
use RuntimeException;
use Tests\Concerns\TalksToErp;
use Tests\TestCase;

/**
 * CRM tells ERP what it sold, cooked, took back, changed on the menu and paid
 * out of the drawer (contracts/events/crm.*.json).
 *
 * Every test does the real thing - bills through InvoiceService, bumps
 * through KitchenService, edits through the product form - and then reads
 * the outbox: the right type, for the right outlet and business, carrying
 * what the contract says, and valid against it. Nothing here calls the
 * recorder directly; a hook that stopped firing would fail these.
 */
class ErpEventsTest extends TestCase
{
    use RefreshDatabase, TalksToErp;

    private Shop $shop;

    /** @var array{tenant: string, outlet: string} */
    private array $ids;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');
        CurrentShop::forget();

        $this->shop = Shop::query()->orderBy('id')->firstOrFail();
        $this->ids = $this->linkToAccounts($this->shop);
    }

    protected function tearDown(): void
    {
        CurrentShop::forget();

        parent::tearDown();
    }

    /* ---------------------------------------------------------- fixtures */

    private function dish(string $name, float $price = 100, array $extra = []): Product
    {
        return Product::create(array_merge([
            'name' => $name,
            'slug' => Product::uniqueSlug($name),
            'sku' => Product::generateSku($name),
            'unit_id' => Unit::query()->withoutGlobalScopes()->where('code', 'PCS')->value('id'),
            'selling_price' => $price,
            'tax_inclusive' => true,
            'is_active' => true,
        ], $extra));
    }

    private function gst5(): ?int
    {
        return TaxRate::query()->withoutGlobalScopes()
            ->where('tenant_id', $this->shop->tenant_id)
            ->where('name', 'GST 5%')
            ->value('id');
    }

    /** A dish with two sizes and one add-on question at this outlet. */
    private function pizza(): array
    {
        $pizza = $this->dish('Margherita', 300);
        $small = ProductVariant::create(['product_id' => $pizza->id, 'name' => 'Small', 'price' => 250, 'sort_order' => 0]);
        $large = ProductVariant::create(['product_id' => $pizza->id, 'name' => 'Large', 'price' => 450, 'sort_order' => 1, 'is_default' => true]);

        $extras = Modifier::create([
            'shop_id' => $this->shop->id,
            'name' => 'Extras',
            'min_select' => 0,
            'max_select' => null,
            'is_active' => true,
        ]);
        $cheese = $extras->options()->create(['name' => 'Extra cheese', 'price' => 60, 'is_available' => true]);
        DB::table('modifier_product')->insert(['modifier_id' => $extras->id, 'product_id' => $pizza->id, 'sort_order' => 0]);

        return [$pizza, $small, $large, $cheese];
    }

    private function sell(array $items, string $channel = Invoice::POS): Invoice
    {
        return app(InvoiceService::class)->create([
            'shop' => $this->shop,
            'channel' => $channel,
            'items' => $items,
            'payments' => [['amount' => 100000, 'method' => 'cash']],
        ]);
    }

    private function seatAt(string $code): TableSession
    {
        $floor = Floor::query()->firstOrCreate(
            ['shop_id' => $this->shop->id, 'code' => 'GF'],
            ['name' => 'Ground Floor', 'is_active' => true],
        );

        $table = new RestaurantTable([
            'shop_id' => $this->shop->id,
            'floor_id' => $floor->id,
            'name' => substr($code, 3),
            'code' => $code,
            'capacity' => 4,
            'status' => RestaurantTable::AVAILABLE,
            'is_active' => true,
        ]);
        $table->save();
        app(TableQrService::class)->issue($table);

        $this->get('/t/'.$table->fresh(['activeQr'])->activeQr->token)->assertRedirect(route('table.show'));

        return TableSession::allShops()->live()->latest('id')->firstOrFail();
    }

    private function staff(array $permissions): User
    {
        static $counter = 0;
        $counter++;

        $user = User::create([
            'name' => 'Menu Staff '.$counter,
            'email' => "erpstaff{$counter}@example.test",
            'password' => 'staff-password-1',
            'is_admin' => true,
        ]);

        $user->givePermissionTo($permissions);
        $user->shops()->attach($this->shop->id, ['is_default' => true]);
        $user->forceFill(['current_shop_id' => $this->shop->id])->save();

        return $user;
    }

    private function secondOutlet(): Shop
    {
        return Shop::create([
            'tenant_id' => $this->shop->tenant_id,
            'name' => 'Second Outlet', 'code' => 'SEC', 'slug' => 'second-outlet',
            'invoice_prefix' => 'INV', 'pos_prefix' => 'POS',
            'currency' => 'INR', 'timezone' => 'Asia/Kolkata', 'is_active' => true,
        ]);
    }

    private function onlyEvent(int $mark, string $type): OutboxEvent
    {
        $events = $this->outboxSince($mark, $type);

        $this->assertCount(1, $events, "expected exactly one {$type}");

        return $events->first();
    }

    /* ------------------------------------------------------------- sales */

    public function test_a_counter_sale_is_recorded_for_erp_with_its_money_in_paise(): void
    {
        $cola = $this->dish('Cola 300 ml', 40);
        $wrap = $this->dish('Paneer Wrap', 210, ['tax_rate_id' => $this->gst5()]);
        $mark = $this->outboxMark();

        $invoice = $this->sell([
            ['product_id' => $cola->id, 'quantity' => 2],
            ['product_id' => $wrap->id, 'quantity' => 1],
        ]);

        $event = $this->onlyEvent($mark, 'crm.sale.recorded');
        $this->assertHonoursContract($event);

        $this->assertSame($this->ids['tenant'], $event->tenant_id);
        $this->assertSame($this->ids['outlet'], $event->outlet_id);

        $data = $event->data;
        $this->assertSame($invoice->fresh()->ulid, $data['sale_id']);
        $this->assertSame($invoice->number, $data['invoice_no']);
        $this->assertSame('counter', $data['channel']);
        $this->assertSame(OutletClock::dateOf($this->shop, $invoice->invoiced_at), $data['business_date']);

        $this->assertCount(2, $data['lines']);
        [$first, $second] = $data['lines'];
        $this->assertSame($cola->fresh()->ulid, $first['item_id']);
        $this->assertNull($first['variant_id']);
        $this->assertSame('2.0000', $first['qty']);
        $this->assertSame([], $first['order_line_ids'], 'a counter sale never went through the kitchen');
        $this->assertSame($invoice->items[0]->fresh()->ulid, $first['line_id']);

        // Figures are InvoiceService's, in paise - never recomputed.
        $invoice->refresh();
        $this->assertSame((int) round((float) $invoice->grand_total * 100), $data['totals']['grand_paise']);
        $this->assertSame((int) round((float) $invoice->tax_total * 100), $data['totals']['tax_paise']);
        $this->assertSame((int) round((float) $invoice->subtotal * 100), $data['totals']['net_paise']);
        $this->assertGreaterThan(0, $second['tax_paise'], 'the GST line carries its tax');
        $this->assertSame(
            (int) round((float) $invoice->items[1]->taxable_value * 100),
            $second['net_paise'],
        );
    }

    public function test_a_table_bill_names_its_size_its_add_ons_and_the_kitchen_lines_it_settles(): void
    {
        [$pizza, , $large, $cheese] = $this->pizza();
        $session = $this->seatAt('GF-07');

        app(TableCartService::class)->add($session, $pizza, $large->id, 2, [$cheese->id]);
        $order = app(TableOrderService::class)->place($session);
        $line = $order->items->first();

        $mark = $this->outboxMark();

        // The kitchen: every rung announced, a skipped rung not invented.
        $kitchen = app(KitchenService::class);
        $kitchen->bumpLine($line);
        $kitchen->bumpLine($line->fresh(), Order::READY);

        $advanced = $this->outboxSince($mark, 'crm.kitchen.line_advanced');
        $this->assertSame(['accepted', 'ready'], $advanced->pluck('data.stage')->all());

        foreach ($advanced as $event) {
            $this->assertHonoursContract($event);
            $this->assertSame($this->ids['outlet'], $event->outlet_id);
        }

        $bump = $advanced->last()->data;
        $this->assertSame($order->fresh()->ulid, $bump['order_id']);
        $this->assertSame($line->fresh()->ulid, $bump['line_id']);
        $this->assertSame($pizza->fresh()->ulid, $bump['item_id']);
        $this->assertSame($large->fresh()->ulid, $bump['variant_id']);
        $this->assertSame([$cheese->fresh()->ulid], $bump['option_ids']);
        $this->assertSame('2.0000', $bump['qty']);

        // The bill.
        $mark = $this->outboxMark();
        $invoice = app(TableBillService::class)->settle($session->fresh(), [
            'payments' => [['amount' => 100000, 'method' => 'cash']],
        ]);

        $event = $this->onlyEvent($mark, 'crm.sale.recorded');
        $this->assertHonoursContract($event);

        $sale = $event->data;
        $this->assertSame('dine_in', $sale['channel']);
        $this->assertSame($invoice->ulid, $sale['sale_id']);
        $this->assertCount(1, $sale['lines']);
        $this->assertSame($pizza->fresh()->ulid, $sale['lines'][0]['item_id']);
        $this->assertSame($large->fresh()->ulid, $sale['lines'][0]['variant_id']);
        $this->assertSame([$cheese->fresh()->ulid], $sale['lines'][0]['option_ids']);
        $this->assertSame([$line->fresh()->ulid], $sale['lines'][0]['order_line_ids'], 'ERP must not consume the pizza twice');
    }

    public function test_an_order_billed_when_paid_says_how_it_left_and_what_the_kitchen_cooked(): void
    {
        $biryani = $this->dish('Chicken Biryani', 320);

        $order = app(OrderService::class)->placeExternal([
            'shop_id' => $this->shop->id,
            'order_type' => Order::TAKEAWAY,
            'items' => [['product_id' => $biryani->id, 'quantity' => 2]],
        ]);
        $line = $order->items->first();
        app(KitchenService::class)->bumpLine($line, Order::READY);

        $mark = $this->outboxMark();
        app(OrderService::class)->markPaid($order->fresh(), $this->staff([]), ['amount' => 640, 'method' => 'cash']);

        $event = $this->onlyEvent($mark, 'crm.sale.recorded');
        $this->assertHonoursContract($event);
        $this->assertSame('takeaway', $event->data['channel'], 'the order says how it left, even before it names its invoice');
        $this->assertSame([$line->fresh()->ulid], $event->data['lines'][0]['order_line_ids']);
    }

    public function test_cancelling_a_sale_tells_erp_which_sale_and_why(): void
    {
        $invoice = $this->sell([['product_id' => $this->dish('Lassi', 80)->id, 'quantity' => 1]]);
        $mark = $this->outboxMark();

        app(InvoiceService::class)->cancel($invoice->fresh(), 'Billed to the wrong table');

        $event = $this->onlyEvent($mark, 'crm.sale.cancelled');
        $this->assertHonoursContract($event);
        $this->assertSame($this->ids['outlet'], $event->outlet_id);
        $this->assertSame(['sale_id' => $invoice->fresh()->ulid, 'reason' => 'Billed to the wrong table'], $event->data);
    }

    public function test_an_accepted_return_names_the_sale_lines_and_what_goes_back_on_the_shelf(): void
    {
        $water = $this->dish('Water 1 L', 20);
        $chips = $this->dish('Chips', 30);
        $invoice = $this->sell([
            ['product_id' => $water->id, 'quantity' => 3],
            ['product_id' => $chips->id, 'quantity' => 2],
        ]);

        $return = new SalesReturn([
            'shop_id' => $this->shop->id,
            'invoice_id' => $invoice->id,
            'customer_name' => $invoice->billedTo(),
            'reference' => SalesReturn::nextReference($this->shop),
            'returned_on' => today(),
            'status' => SalesReturn::PENDING,
            'reason_code' => 'not_needed',
            'settlement' => SalesReturn::REFUND,
        ]);
        $return->save();

        foreach ([[0, 1, SalesReturnItem::RESALABLE], [1, 2, SalesReturnItem::DAMAGED]] as [$index, $quantity, $condition]) {
            $source = $invoice->items[$index];

            $return->items()->create([
                'invoice_item_id' => $source->id,
                'product_id' => $source->product_id,
                'product_name' => $source->product_name,
                'quantity' => $quantity,
                'condition' => $condition,
                'unit_price' => (float) $source->taxable_value / (float) $source->quantity,
                'tax_rate' => (float) $source->tax_rate,
                'unit_cost' => (float) $source->unit_cost,
            ]);
        }

        $mark = $this->outboxMark();
        app(SalesReturnService::class)->approve($return->fresh());

        $event = $this->onlyEvent($mark, 'crm.sale.returned');
        $this->assertHonoursContract($event);

        $this->assertSame($return->fresh()->ulid, $event->data['return_id']);
        $this->assertSame($invoice->fresh()->ulid, $event->data['sale_id']);
        $this->assertSame(today()->toDateString(), $event->data['business_date']);
        $this->assertSame([
            ['line_id' => $invoice->items[0]->fresh()->ulid, 'qty' => '1.0000', 'back_to_stock' => true],
            ['line_id' => $invoice->items[1]->fresh()->ulid, 'qty' => '2.0000', 'back_to_stock' => false],
        ], $event->data['lines']);
    }

    /* ----------------------------------------------------------- kitchen */

    public function test_cancelling_an_order_cancels_each_kitchen_line_at_the_rung_it_reached(): void
    {
        $tikka = $this->dish('Paneer Tikka', 280);
        $naan = $this->dish('Butter Naan', 60);

        $order = app(OrderService::class)->placeExternal([
            'shop_id' => $this->shop->id,
            'order_type' => Order::TAKEAWAY,
            'items' => [
                ['product_id' => $tikka->id, 'quantity' => 1],
                ['product_id' => $naan->id, 'quantity' => 3],
            ],
        ]);

        /** @var OrderItem $tikkaLine */
        $tikkaLine = $order->items->firstWhere('product_id', $tikka->id);
        app(KitchenService::class)->bumpLine($tikkaLine, Order::PREPARING);

        $mark = $this->outboxMark();
        app(OrderService::class)->cancel($order->fresh(), 'Guest changed their mind', $this->staff([]));

        $cancelled = $this->outboxSince($mark, 'crm.kitchen.line_cancelled');
        $this->assertCount(2, $cancelled);

        foreach ($cancelled as $event) {
            $this->assertHonoursContract($event);
            $this->assertSame($this->ids['outlet'], $event->outlet_id);
            $this->assertSame($order->fresh()->ulid, $event->data['order_id']);
            $this->assertSame('Guest changed their mind', $event->data['reason']);
        }

        $byItem = $cancelled->keyBy('data.item_id');
        $this->assertSame('preparing', $byItem[$tikka->fresh()->ulid]->data['stage_reached']);
        $this->assertSame('placed', $byItem[$naan->fresh()->ulid]->data['stage_reached']);
        $this->assertSame('3.0000', $byItem[$naan->fresh()->ulid]->data['qty']);

        // And a cancelled ticket cannot be bumped into consuming food after all.
        $this->expectException(RuntimeException::class);
        app(KitchenService::class)->bumpLine($tikkaLine->fresh());
    }

    /* -------------------------------------------------------------- menu */

    public function test_a_menu_edit_is_one_whole_item_per_outlet_with_each_outlets_own_price(): void
    {
        $second = $this->secondOutlet();
        $this->linkToAccounts($second);

        $staff = $this->staff(['inventory.products.edit']);
        $dish = $this->dish('Dal Makhani', 280);
        $mark = $this->outboxMark();

        $this->actingAs($staff)->putJson(route('admin.products.update', $dish), [
            'name' => 'Dal Makhani Special',
            'unit_id' => $dish->unit_id,
            'selling_price' => 300,
            'food_type' => 'jain',
            'options' => [
                ['name' => 'Half', 'price' => 180, 'sku' => 'DAL-H'],
                ['name' => 'Full', 'price' => 300, 'is_default' => true],
            ],
            // This outlet charges more; the other inherits the dish's price.
            'override' => ['selling_price' => 320, 'is_active' => true],
        ])->assertOk()->assertJsonPath('success', true);

        $upserts = $this->outboxSince($mark, 'crm.menu.item_upserted');
        $this->assertCount(2, $upserts, 'one whole item per outlet, not one per row the form wrote');

        $byOutlet = $upserts->keyBy('outlet_id');
        $here = $byOutlet[$this->shop->ulid]->data;
        $there = $byOutlet[$second->fresh()->ulid]->data;

        foreach ($upserts as $event) {
            $this->assertHonoursContract($event);
        }

        $this->assertSame($dish->fresh()->ulid, $here['item_id']);
        $this->assertSame('Dal Makhani Special', $here['name']);
        $this->assertSame('veg', $here['food_type'], 'jain is vegetarian');
        $this->assertSame(32000, $here['price_paise']);
        $this->assertSame(30000, $there['price_paise']);
        $this->assertSame(['Half', 'Full'], array_column($here['sizes'], 'name'));
        $this->assertSame([18000, 30000], array_column($here['sizes'], 'price_paise'));
        $this->assertSame([false, true], array_column($here['sizes'], 'is_default'));
        $this->assertSame('DAL-H', $here['sizes'][0]['sku']);
    }

    public function test_add_ons_are_announced_to_their_own_outlet_only(): void
    {
        $second = $this->secondOutlet();
        $this->linkToAccounts($second);

        [$pizza, , , $cheese] = $this->pizza();
        $mark = $this->outboxMark();

        $cheese->forceFill(['price' => 75])->save();

        $event = $this->onlyEvent($mark, 'crm.menu.item_upserted');
        $this->assertHonoursContract($event);
        $this->assertSame($this->shop->ulid, $event->outlet_id);
        $this->assertSame($pizza->fresh()->ulid, $event->data['item_id']);
        $this->assertSame([[
            'option_id' => $cheese->fresh()->ulid,
            'modifier_id' => $cheese->modifier->fresh()->ulid,
            'modifier_name' => 'Extras',
            'name' => 'Extra cheese',
            'price_paise' => 7500,
            'active' => true,
        ]], $event->data['addons']);
    }

    public function test_archiving_a_menu_item_is_announced_and_nothing_follows_it(): void
    {
        $staff = $this->staff(['inventory.products.delete']);
        $dish = $this->dish('Kulfi', 90);
        $mark = $this->outboxMark();

        $this->actingAs($staff)->deleteJson(route('admin.products.destroy', $dish))->assertOk();

        $event = $this->onlyEvent($mark, 'crm.menu.item_archived');
        $this->assertHonoursContract($event);
        $this->assertSame($this->ids['outlet'], $event->outlet_id);
        $this->assertSame(['item_id' => $dish->fresh()->ulid], $event->data);
        $this->assertCount(0, $this->outboxSince($mark, 'crm.menu.item_upserted'));
    }

    public function test_changes_the_menu_event_does_not_carry_send_nothing(): void
    {
        $dish = $this->dish('Gulab Jamun', 70);
        $mark = $this->outboxMark();

        // Sold out and published are not in crm.menu.item_upserted - and
        // sold-out is what ERP itself sends, so it must never echo back.
        $dish->forceFill(['is_sold_out' => true, 'is_published' => true, 'purchase_price' => 20])->save();

        $this->assertCount(0, $this->outboxSince($mark));
    }

    /* -------------------------------------------------------------- cash */

    public function test_a_paid_out_is_announced_with_its_category_and_note(): void
    {
        $mark = $this->outboxMark();

        $paidOut = app(CashRegisterService::class)->payOut($this->shop, 120, 'Vegetables, morning delivery', 'supplier_payment');

        $event = $this->onlyEvent($mark, 'crm.cash.paid_out');
        $this->assertHonoursContract($event);
        $this->assertSame($this->ids['outlet'], $event->outlet_id);
        $this->assertSame([
            'paid_out_id' => $paidOut->ulid,
            'business_date' => OutletClock::dateOf($this->shop, $paidOut->paid_at),
            'amount_paise' => 12000,
            'category' => 'supplier_payment',
            'supplier_bill_id' => null,
            'note' => 'Vegetables, morning delivery',
        ], $event->data);
    }

    public function test_a_paid_out_with_no_reason_is_refused_and_announces_nothing(): void
    {
        $mark = $this->outboxMark();

        try {
            app(CashRegisterService::class)->payOut($this->shop, 50, '  ');
            $this->fail('A paid-out with no reason was accepted.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertCount(0, $this->outboxSince($mark));
    }

    /* ------------------------------------------------------------ guards */

    public function test_an_outlet_not_linked_to_accounts_records_nothing(): void
    {
        $this->shop->forceFill(['ulid' => null])->saveQuietly();
        $mark = $this->outboxMark();

        $this->sell([['product_id' => $this->dish('Tea', 20)->id, 'quantity' => 1]]);

        $this->assertCount(0, $this->outboxSince($mark, 'crm.sale.recorded'));
    }

    public function test_a_sale_row_with_no_id_of_its_own_gets_one_the_first_time_it_leaves(): void
    {
        $invoice = $this->sell([['product_id' => $this->dish('Coffee', 60)->id, 'quantity' => 1]]);
        // As a seeder (WithoutModelEvents) or saveQuietly() would leave it.
        DB::table('invoices')->where('id', $invoice->id)->update(['ulid' => null]);
        $mark = $this->outboxMark();

        app(InvoiceService::class)->cancel(Invoice::allShops()->findOrFail($invoice->id), 'Test');

        $event = $this->onlyEvent($mark, 'crm.sale.cancelled');
        $this->assertHonoursContract($event);
        $this->assertSame($invoice->fresh()->ulid, $event->data['sale_id']);
        $this->assertNotNull($invoice->fresh()->ulid);
    }
}
