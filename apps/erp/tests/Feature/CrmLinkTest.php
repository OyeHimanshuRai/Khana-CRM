<?php

namespace Tests\Feature;

use App\Models\KitchenLine;
use App\Models\OutletSetting;
use App\Models\PaidOut;
use App\Models\Sale;
use App\Models\SalesItem;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Services\Purchasing;
use App\Services\Recipes;
use App\Services\StockService;
use App\Stock\Posting;
use App\Support\Outlets;
use App\Support\Units;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Khana\Kit\Events\Inbox\InboxEvent;
use Khana\Kit\Support\Ulid;
use Tests\Concerns\HearsFromCrm;
use Tests\TestCase;

/**
 * The link with CRM: the menu copied in, stock used as dishes are cooked
 * and sold, and sold-out and cost sent back.
 *
 * Every rule in the architecture's "Items across services" has a test here
 * by name. Events go through the real inbox (see HearsFromCrm), and every
 * event ERP sends is checked against its contract.
 */
class CrmLinkTest extends TestCase
{
    use HearsFromCrm, RefreshDatabase;

    /** @var array{tenant: string, outlet: string} */
    private array $at;

    private Store $main;

    private Store $kitchen;

    private StockService $stock;

    private StockItem $paneer;

    private StockItem $curd;

    private StockItem $masala;

    protected function setUp(): void
    {
        parent::setUp();

        $this->at = $this->business('Spice Garden', ['erp' => 'active', 'crm' => 'active']);
        ['main' => $this->main, 'kitchen' => $this->kitchen] = Outlets::stores($this->at['outlet']);
        $this->stock = app(StockService::class);

        $this->paneer = $this->stockItem($this->at['tenant'], 'Paneer', 'g', 400);
        $this->curd = $this->stockItem($this->at['tenant'], 'Curd', 'g', 80);
        $this->masala = $this->stockItem($this->at['tenant'], 'Tikka Masala', 'g', 600);
    }

    private function stocked(StockItem $item, string|int $amount, string $unit = 'g', ?Store $store = null): void
    {
        $this->stock->receive(
            $store ?? $this->kitchen, $item, Units::toBase($amount, $unit, $item->base_unit),
            $item->last_cost_rate, new Posting(StockMovement::RECEIPT, businessDate: '2026-10-01'),
        );
    }

    private function onHand(StockItem $item): string
    {
        return Units::plain($this->stock->onHand($this->at['outlet'], $item));
    }

    /** Paneer Tikka with Half and Full, and the chef's recipes for both. */
    private function paneerTikka(): array
    {
        $tikka = $this->menuItem('Paneer Tikka', 320, [['Full', 320], ['Half', 180]]);
        $this->fromCrm($this->at, 'crm.menu.item_upserted', $tikka);

        $this->recipe($this->at, $tikka, 'Full', [[$this->paneer, 250, 'g'], [$this->curd, 50, 'g'], [$this->masala, 10, 'g']]);
        $this->recipe($this->at, $tikka, 'Half', [[$this->paneer, 125, 'g'], [$this->curd, 25, 'g'], [$this->masala, 5, 'g']]);

        return $tikka;
    }

    /* --------------------------------------------------------- the menu */

    public function test_a_new_dish_arrives_and_waits_for_a_recipe(): void
    {
        $tikka = $this->menuItem('Paneer Tikka', 320, [['Full', 320], ['Half', 180]]);
        $this->fromCrm($this->at, 'crm.menu.item_upserted', $tikka);

        $item = SalesItem::allOutlets()->where('ref', $tikka['item_id'])->sole();
        $this->assertSame('Paneer Tikka', $item->name);
        $this->assertSame($this->at['tenant'], $item->tenant_id);
        $this->assertSame(['Full', 'Half'], $item->sizes()->orderBy('id')->pluck('name')->all());

        $needs = app(Recipes::class)->needsRecipe($this->at['outlet']);
        $this->assertSame(['Full', 'Half'], $needs->pluck('size.name')->all());

        // One size written: the other still waits.
        $this->recipe($this->at, $tikka, 'Full', [[$this->paneer, 250, 'g']]);
        $this->assertSame(['Half'], app(Recipes::class)->needsRecipe($this->at['outlet'])->pluck('size.name')->all());
    }

    public function test_saving_the_dish_again_replaces_what_erp_had(): void
    {
        $tikka = $this->menuItem('Paneer Tikka', 320, [['Full', 320], ['Half', 180]]);
        $this->fromCrm($this->at, 'crm.menu.item_upserted', $tikka);

        $tikka['name'] = 'Paneer Tikka (Tandoor)';
        $tikka['sizes'] = [$tikka['sizes'][0]];
        $this->fromCrm($this->at, 'crm.menu.item_upserted', $tikka);

        $item = SalesItem::allOutlets()->sole();
        $this->assertSame('Paneer Tikka (Tandoor)', $item->name);
        // The size CRM dropped is switched off, not deleted.
        $this->assertSame(['Full' => 1, 'Half' => 0], $item->sizes()->pluck('is_active', 'name')->map(fn ($on) => (int) $on)->all());

        $this->fromCrm($this->at, 'crm.menu.item_archived', ['item_id' => $tikka['item_id']]);
        $this->assertFalse($item->fresh()->is_active);
        $this->assertCount(0, app(Recipes::class)->needsRecipe($this->at['outlet']));
    }

    public function test_packaged_goods_are_linked_by_barcode_without_being_asked(): void
    {
        $cola = $this->stockItem($this->at['tenant'], 'Cola 300 ml', 'pc', 28, ['barcode' => '8901764012273']);
        $this->stocked($cola, 24, 'pc');

        $onMenu = $this->menuItem('Cola', 60, [], ['barcode' => '8901764012273']);
        $this->fromCrm($this->at, 'crm.menu.item_upserted', $onMenu);

        $this->assertCount(0, app(Recipes::class)->needsRecipe($this->at['outlet']));

        // Sold at the counter, never through the kitchen: consumed at the sale.
        $this->fromCrm($this->at, 'crm.sale.recorded', $this->saleOf([[$onMenu, null, 3, 180]], channel: 'counter'));

        $this->assertSame('21', $this->onHand($cola));
    }

    /* ------------------------------------------ the worked example, 1-6 */

    public function test_the_paneer_tikka_example_from_the_architecture(): void
    {
        // 1-2. The dish arrives; the chef writes Full and Half.
        $tikka = $this->paneerTikka();
        $this->stocked($this->paneer, 700);
        $this->stocked($this->curd, 2, 'kg');
        $this->stocked($this->masala, 500);
        $mark = $this->outboxMark();

        // 3. Table 4 orders 2 Full. Nothing moves until the line is ready.
        $line = $this->kitchenLine($tikka, 'Full', 2, 'accepted');
        $this->fromCrm($this->at, 'crm.kitchen.line_advanced', $line);
        $this->assertSame('700', $this->onHand($this->paneer));

        $this->fromCrm($this->at, 'crm.kitchen.line_advanced', ['stage' => 'ready'] + $line);

        $this->assertSame('200', $this->onHand($this->paneer), '500 g of paneer for two Full');
        $this->assertSame('1900', $this->onHand($this->curd));
        $this->assertSame('480', $this->onHand($this->masala));

        // 4. 200 g cannot cover a Full (250 g): Full is sold out, Half is not.
        $signals = $this->sent('erp.availability.changed', $mark);
        $this->assertCount(1, $signals);
        $this->assertSame([
            'item_id' => $tikka['item_id'],
            'variant_id' => $this->variant($tikka, 'Full'),
            'available' => false,
            'reason' => 'stock_out',
        ], $signals[0]->data);
        $this->assertSame($this->at['outlet'], $signals[0]->outlet_id);

        // 5. The bill settles the kitchen line: no more stock, but the cost
        //    of the food is the sale's.
        $sale = $this->saleOf([[$tikka, 'Full', 2, 640, [$line['line_id']]]]);
        $this->fromCrm($this->at, 'crm.sale.recorded', $sale);

        $this->assertSame('200', $this->onHand($this->paneer));
        $kitchenLine = KitchenLine::allOutlets()->where('ref', $line['line_id'])->sole();
        // 500 g paneer at 400/kg + 100 g curd at 80/kg + 20 g masala at 600/kg.
        $this->assertSame(20_000 + 800 + 1_200, $kitchenLine->cost_paise);
        $this->assertSame(Sale::allOutlets()->sole()->lines()->sole()->id, $kitchenLine->sale_line_id);

        // Served afterwards: consumed once, not again.
        $this->fromCrm($this->at, 'crm.kitchen.line_advanced', ['stage' => 'served'] + $line);
        $this->assertSame('200', $this->onHand($this->paneer));

        // 6. Paneer arrives next morning: Full is back on sale.
        $mark = $this->outboxMark();
        $this->stocked($this->paneer, 5, 'kg', $this->main);

        $signals = $this->sent('erp.availability.changed', $mark);
        $this->assertCount(1, $signals);
        $this->assertSame(['available' => true, 'reason' => 'restocked'], array_intersect_key($signals[0]->data, ['available' => 1, 'reason' => 1]));
        $this->assertSame($this->variant($tikka, 'Full'), $signals[0]->data['variant_id']);

        $this->assertSame([], $this->stock->reconcile($this->at['outlet']));
    }

    /* ------------------------------------------------------ consumption */

    public function test_the_outlet_chooses_the_stage_a_dish_is_consumed_at(): void
    {
        $tikka = $this->paneerTikka();
        $this->stocked($this->paneer, 2, 'kg');

        OutletSetting::create(['outlet_id' => $this->at['outlet'], 'tenant_id' => $this->at['tenant'], 'consume_stage' => 'accepted']);

        $line = $this->kitchenLine($tikka, 'Half', 1, 'accepted');
        $this->fromCrm($this->at, 'crm.kitchen.line_advanced', $line);

        $this->assertSame('1875', $this->onHand($this->paneer));
    }

    public function test_a_dish_is_cooked_from_wherever_the_stock_is_unless_the_outlet_is_strict(): void
    {
        $tikka = $this->paneerTikka();
        // Everything is still in the main store; nobody "issued" it.
        $this->stocked($this->paneer, 1, 'kg', $this->main);

        $this->fromCrm($this->at, 'crm.kitchen.line_advanced', $this->kitchenLine($tikka, 'Full', 1));

        $this->assertSame(Units::toBase(750, 'g', 'g'), $this->stock->onHand($this->at['outlet'], $this->paneer, $this->main));
        $this->assertSame(0, $this->stock->onHand($this->at['outlet'], $this->paneer, $this->kitchen));

        // A strict outlet cooks only with what is in the kitchen, and goes
        // negative there when it is not.
        OutletSetting::create(['outlet_id' => $this->at['outlet'], 'tenant_id' => $this->at['tenant'], 'kitchen_only' => true]);

        $this->fromCrm($this->at, 'crm.kitchen.line_advanced', $this->kitchenLine($tikka, 'Full', 1));

        $this->assertSame(Units::toBase(750, 'g', 'g'), $this->stock->onHand($this->at['outlet'], $this->paneer, $this->main));
        $this->assertSame(Units::toBase(-250, 'g', 'g'), $this->stock->onHand($this->at['outlet'], $this->paneer, $this->kitchen));
    }

    public function test_add_ons_add_their_own_recipe_on_top(): void
    {
        $cheese = $this->stockItem($this->at['tenant'], 'Cheese', 'g', 500);
        $this->stocked($cheese, 1, 'kg');
        $this->stocked($this->paneer, 1, 'kg');

        $option = Ulid::generate();
        $tikka = $this->menuItem('Paneer Tikka', 320, [], ['addons' => [[
            'option_id' => $option, 'modifier_id' => Ulid::generate(), 'modifier_name' => 'Extras',
            'name' => 'Extra cheese', 'price_paise' => 4000, 'active' => true,
        ]]]);
        $this->fromCrm($this->at, 'crm.menu.item_upserted', $tikka);
        $this->recipe($this->at, $tikka, null, [[$this->paneer, 250, 'g']]);

        $addon = \App\Models\SalesAddon::allOutlets()->where('ref', $option)->sole();
        app(Recipes::class)->save($this->at['outlet'], ['sales_addon_id' => $addon->id], [[
            'item' => $cheese, 'qty' => Units::toBase(20, 'g', 'g'), 'entered_qty' => '20', 'unit' => 'g',
        ]]);

        $this->fromCrm($this->at, 'crm.kitchen.line_advanced', $this->kitchenLine($tikka, null, 2, 'ready', ['option_ids' => [$option]]));

        $this->assertSame('500', $this->onHand($this->paneer));
        $this->assertSame('960', $this->onHand($cheese), 'extra cheese adds 20 g a portion');
    }

    public function test_a_dish_with_no_recipe_uses_nothing_but_the_sale_still_counts(): void
    {
        $soup = $this->menuItem('Tomato Soup', 120);
        $this->fromCrm($this->at, 'crm.menu.item_upserted', $soup);

        $this->fromCrm($this->at, 'crm.sale.recorded', $this->saleOf([[$soup, null, 2, 240]], channel: 'takeaway'));

        $this->assertSame(0, StockMovement::allOutlets()->count());
        $this->assertSame(24_000, Sale::allOutlets()->sole()->net_paise);
    }

    /* ----------------------------------------------------- cancellations */

    public function test_a_line_cancelled_before_cooking_gives_its_stock_back(): void
    {
        $tikka = $this->paneerTikka();
        $this->stocked($this->paneer, 1, 'kg');
        OutletSetting::create(['outlet_id' => $this->at['outlet'], 'tenant_id' => $this->at['tenant'], 'consume_stage' => 'accepted']);

        $line = $this->kitchenLine($tikka, 'Full', 1, 'accepted');
        $this->fromCrm($this->at, 'crm.kitchen.line_advanced', $line);
        $this->assertSame('750', $this->onHand($this->paneer));

        $this->fromCrm($this->at, 'crm.kitchen.line_cancelled', [
            'stage_reached' => 'accepted', 'reason' => 'Guest changed their mind',
        ] + array_diff_key($line, ['stage' => 1]));

        $this->assertSame('1000', $this->onHand($this->paneer));
        // One row back for each of the three ingredients.
        $this->assertSame(3, StockMovement::allOutlets()->where('type', StockMovement::REVERSAL)->count());
        $this->assertSame(0, KitchenLine::allOutlets()->sole()->cost_paise);
    }

    public function test_a_line_cancelled_after_cooking_began_is_wastage(): void
    {
        $tikka = $this->paneerTikka();
        $this->stocked($this->paneer, 1, 'kg');

        // Consumed at "ready" here, so nothing is booked yet at "preparing".
        $line = $this->kitchenLine($tikka, 'Full', 1, 'preparing');
        $this->fromCrm($this->at, 'crm.kitchen.line_advanced', $line);
        $this->assertSame('1000', $this->onHand($this->paneer));

        $this->fromCrm($this->at, 'crm.kitchen.line_cancelled', [
            'stage_reached' => 'preparing', 'reason' => 'Wrong table',
        ] + array_diff_key($line, ['stage' => 1]));

        $this->assertSame('750', $this->onHand($this->paneer), 'the paneer is gone whoever ate it');

        $wasted = StockMovement::allOutlets()->where('type', StockMovement::WASTAGE)->get();
        $this->assertCount(3, $wasted, 'paneer, curd and masala');
        $this->assertStringContainsString('Cancelled after cooking began', $wasted[0]->reason);
        $this->assertTrue(KitchenLine::allOutlets()->sole()->wasted);
    }

    public function test_a_cancelled_bill_gives_back_only_what_the_bill_itself_took(): void
    {
        $tikka = $this->paneerTikka();
        $cola = $this->stockItem($this->at['tenant'], 'Cola 300 ml', 'pc', 28, ['barcode' => '890']);
        $onMenu = $this->menuItem('Cola', 60, [], ['barcode' => '890']);
        $this->fromCrm($this->at, 'crm.menu.item_upserted', $onMenu);
        $this->stocked($this->paneer, 1, 'kg');
        $this->stocked($cola, 10, 'pc');

        $line = $this->kitchenLine($tikka, 'Full', 1);
        $this->fromCrm($this->at, 'crm.kitchen.line_advanced', $line);

        $sale = $this->saleOf([[$tikka, 'Full', 1, 320, [$line['line_id']]], [$onMenu, null, 2, 120]]);
        $this->fromCrm($this->at, 'crm.sale.recorded', $sale);

        $this->assertSame('750', $this->onHand($this->paneer));
        $this->assertSame('8', $this->onHand($cola));

        $this->fromCrm($this->at, 'crm.sale.cancelled', ['sale_id' => $sale['sale_id'], 'reason' => 'Billed to the wrong table']);

        // The colas go back on the shelf; the tikka was cooked and stays used.
        $this->assertSame('10', $this->onHand($cola));
        $this->assertSame('750', $this->onHand($this->paneer));
        $this->assertTrue(Sale::allOutlets()->sole()->isCancelled());

        // Cancelling again changes nothing.
        $this->fromCrm($this->at, 'crm.sale.cancelled', ['sale_id' => $sale['sale_id'], 'reason' => 'Again']);
        $this->assertSame('10', $this->onHand($cola));
    }

    public function test_a_return_puts_stock_back_only_where_crm_says_it_can_be_sold_again(): void
    {
        $cola = $this->stockItem($this->at['tenant'], 'Cola 300 ml', 'pc', 28, ['barcode' => '890']);
        $onMenu = $this->menuItem('Cola', 60, [], ['barcode' => '890']);
        $this->fromCrm($this->at, 'crm.menu.item_upserted', $onMenu);
        $this->stocked($cola, 10, 'pc');

        $sale = $this->saleOf([[$onMenu, null, 4, 240]], channel: 'counter');
        $this->fromCrm($this->at, 'crm.sale.recorded', $sale);
        $this->assertSame('6', $this->onHand($cola));

        $this->fromCrm($this->at, 'crm.sale.returned', [
            'return_id' => Ulid::generate(), 'sale_id' => $sale['sale_id'], 'business_date' => '2026-10-01',
            'lines' => [['line_id' => $sale['lines'][0]['line_id'], 'qty' => '1.0000', 'back_to_stock' => true]],
        ]);
        $this->assertSame('7', $this->onHand($cola));

        $this->fromCrm($this->at, 'crm.sale.returned', [
            'return_id' => Ulid::generate(), 'sale_id' => $sale['sale_id'], 'business_date' => '2026-10-01',
            'lines' => [['line_id' => $sale['lines'][0]['line_id'], 'qty' => '1.0000', 'back_to_stock' => false]],
        ]);
        $this->assertSame('7', $this->onHand($cola), 'an opened bottle is not stock');
    }

    /* ------------------------------------------------- once, and in order */

    public function test_an_event_delivered_twice_counts_once(): void
    {
        $tikka = $this->paneerTikka();
        $this->stocked($this->paneer, 1, 'kg');

        $event = $this->crmEvent($this->at, 'crm.kitchen.line_advanced', $this->kitchenLine($tikka, 'Full', 1));

        $this->deliver([$event]);
        $this->deliver([$event]);
        $this->deliver([$event, $event]);

        $this->assertSame('750', $this->onHand($this->paneer));
        $this->assertSame(1, InboxEvent::query()->where('event_id', $event['id'])->count());
    }

    public function test_an_outlet_that_does_not_own_erp_is_not_applied(): void
    {
        $crmOnly = $this->business('Chai Point', ['crm' => 'active', 'erp' => 'ended']);

        $this->deliver([$this->crmEvent($crmOnly, 'crm.menu.item_upserted', $this->menuItem('Chai', 20))]);

        $this->assertSame(0, SalesItem::allOutlets()->where('outlet_id', $crmOnly['outlet'])->count());
        $this->assertSame(InboxEvent::IGNORED, InboxEvent::query()->latest('id')->value('status'));
    }

    /* ------------------------------------------------------------- cost */

    public function test_crm_is_told_what_a_portion_costs_and_again_when_it_moves_by_a_percent(): void
    {
        $tikka = $this->menuItem('Paneer Tikka', 320);
        $this->fromCrm($this->at, 'crm.menu.item_upserted', $tikka);
        $this->stocked($this->paneer, 10, 'kg');

        $mark = $this->outboxMark();
        $this->recipe($this->at, $tikka, null, [[$this->paneer, 250, 'g']]);

        $costs = $this->sent('erp.cost.changed', $mark);
        $this->assertCount(1, $costs);
        $this->assertSame([
            'item_id' => $tikka['item_id'], 'variant_id' => null, 'cost_paise' => 10_000, 'food_cost_percent' => '31.25',
        ], $costs[0]->data);

        // Ten kilos more at Rs 402: the average moves to 401, a quarter of
        // a percent. Nothing is sent.
        $mark = $this->outboxMark();
        $this->stock->receive($this->main, $this->paneer, Units::toBase(10, 'kg', 'g'), Units::rateFromQuote(402, 'g'), new Posting(StockMovement::RECEIPT, businessDate: '2026-10-02'));
        $this->assertCount(0, $this->sent('erp.cost.changed', $mark));

        // Twenty kilos at Rs 460 moves it past one percent.
        $this->stock->receive($this->main, $this->paneer, Units::toBase(20, 'kg', 'g'), Units::rateFromQuote(460, 'g'), new Posting(StockMovement::RECEIPT, businessDate: '2026-10-03'));

        $costs = $this->sent('erp.cost.changed', $mark);
        $this->assertCount(1, $costs);
        $this->assertSame(10_763, $costs[0]->data['cost_paise']);
    }

    public function test_an_optional_ingredient_running_out_does_not_stop_the_dish(): void
    {
        $coriander = $this->stockItem($this->at['tenant'], 'Coriander', 'g', 100);
        $tikka = $this->menuItem('Paneer Tikka', 320);
        $this->fromCrm($this->at, 'crm.menu.item_upserted', $tikka);
        $this->stocked($this->paneer, 1, 'kg');

        $mark = $this->outboxMark();
        $this->recipe($this->at, $tikka, null, [[$this->paneer, 250, 'g'], [$coriander, 5, 'g']], optional: [$coriander->id]);

        $this->assertCount(0, $this->sent('erp.availability.changed', $mark), 'no coriander is not a reason to stop selling tikka');

        // Make it required and the dish stops.
        $this->recipe($this->at, $tikka, null, [[$this->paneer, 250, 'g'], [$coriander, 5, 'g']]);

        $signals = $this->sent('erp.availability.changed', $mark);
        $this->assertCount(1, $signals);
        $this->assertSame(['available' => false, 'reason' => 'recipe_changed'], array_intersect_key($signals[0]->data, ['available' => 1, 'reason' => 1]));
    }

    /* -------------------------------------------------------- paid-outs */

    public function test_cash_from_the_till_waits_to_be_filed_unless_the_cashier_named_the_bill(): void
    {
        $dairy = Supplier::allTenants()->create(['tenant_id' => $this->at['tenant'], 'name' => 'Amul Dairy']);
        $bill = SupplierBill::allOutlets()->create([
            'outlet_id' => $this->at['outlet'], 'supplier_id' => $dairy->id, 'bill_no' => 'AD/1',
            'billed_on' => '2026-10-01', 'total_paise' => 50_000,
        ]);

        $this->fromCrm($this->at, 'crm.cash.paid_out', [
            'paid_out_id' => Ulid::generate(), 'business_date' => '2026-10-01', 'amount_paise' => 20_000,
            'category' => 'supplier', 'supplier_bill_id' => $bill->ulid, 'note' => 'Part payment',
        ]);

        $this->assertSame(30_000, $bill->fresh()->duePaise());
        $this->assertSame(PaidOut::FILED, PaidOut::allOutlets()->sole()->status);

        $this->fromCrm($this->at, 'crm.cash.paid_out', [
            'paid_out_id' => Ulid::generate(), 'business_date' => '2026-10-01', 'amount_paise' => 35_000,
            'category' => 'gas', 'supplier_bill_id' => null, 'note' => 'Cylinder',
        ]);

        $pending = PaidOut::allOutlets()->pending()->sole();
        app(Purchasing::class)->filePaidOutAsExpense($pending, 'gas');

        $this->assertSame(PaidOut::FILED, $pending->fresh()->status);
        $this->assertSame(35_000, \App\Models\Expense::allOutlets()->sole()->amount_paise);
    }
}
