<?php

namespace Tests\Feature;

use App\Models\KitchenLine;
use App\Models\NightlyCheck;
use App\Models\Sale;
use App\Models\SalesItem;
use App\Models\StockItem;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\User;
use App\Services\Purchasing;
use App\Services\Reports;
use App\Services\StockService;
use App\Stock\Posting;
use App\Support\Outlets;
use App\Support\Units;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Khana\Kit\Support\Ulid;
use Tests\Concerns\FakesOtherServices;
use Tests\Concerns\HearsFromCrm;
use Tests\TestCase;

/**
 * The nightly checks, one at a time: what each compares, what it puts
 * right by itself, and what it only names.
 *
 * It is 6 am on the 2nd at an outlet whose day closes at 4 am, so the day
 * being checked is the 1st - the day HearsFromCrm's events fall on. CRM and
 * Accounts are faked at their APIs (FakesOtherServices); the events that do
 * arrive come through the real inbox.
 */
class NightlyChecksTest extends TestCase
{
    use FakesOtherServices, HearsFromCrm, RefreshDatabase;

    private const DAY = '2026-10-01';

    /** @var array{tenant: string, outlet: string} */
    private array $at;

    private StockService $stock;

    private StockItem $paneer;

    private StockItem $cola;

    /** @var array<string, mixed> */
    private array $tikka;

    /** @var array<string, mixed> */
    private array $onMenuCola;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-02T00:30:00Z');

        $this->at = $this->business('Spice Garden', ['erp' => 'active', 'crm' => 'active']);
        $this->fakeOtherServices();
        $this->stock = app(StockService::class);

        $this->paneer = $this->stockItem($this->at['tenant'], 'Paneer', 'g', 400);
        $this->cola = $this->stockItem($this->at['tenant'], 'Cola 300 ml', 'pc', 28, ['barcode' => '8901764012273']);
        $this->stocked($this->paneer, 2, 'kg');
        $this->stocked($this->cola, 24, 'pc');

        // The menu as both sides have it: a dish with a recipe, and a bottle linked by its barcode.
        $this->tikka = $this->menuItem('Paneer Tikka', 320);
        $this->onMenuCola = $this->menuItem('Cola', 60, [], ['barcode' => '8901764012273']);

        foreach ([$this->tikka, $this->onMenuCola] as $dish) {
            $this->fromCrm($this->at, 'crm.menu.item_upserted', $dish);
            $this->crmLists($this->at, $dish);
        }

        $this->recipe($this->at, $this->tikka, null, [[$this->paneer, 250, 'g']]);
    }

    private function stocked(StockItem $item, string|int $amount, string $unit, ?array $at = null): void
    {
        $this->stock->receive(
            Outlets::stores(($at ?? $this->at)['outlet'])['kitchen'], $item, Units::toBase($amount, $unit, $item->base_unit),
            $item->last_cost_rate, new Posting(StockMovement::RECEIPT, businessDate: '2026-09-30'),
        );
    }

    private function onHand(StockItem $item): string
    {
        return Units::plain($this->stock->onHand($this->at['outlet'], $item));
    }

    /** Run the command; 0 when no check ended in a problem. */
    private function nightly(array $arguments = []): int
    {
        return $this->artisan('erp:nightly', $arguments)->run();
    }

    private function check(string $check, ?array $at = null, string $day = self::DAY): NightlyCheck
    {
        return NightlyCheck::allOutlets()->where('outlet_id', ($at ?? $this->at)['outlet'])
            ->where('business_date', $day)->where('check', $check)->sole();
    }

    /** @return array<string, string> check => status */
    private function statuses(?array $at = null, string $day = self::DAY): array
    {
        return NightlyCheck::allOutlets()->where('outlet_id', ($at ?? $this->at)['outlet'])->where('business_date', $day)
            ->pluck('status', 'check')->sortKeys()->all();
    }

    /** A bill CRM made and ERP heard of in the ordinary way. */
    private function sold(array $lines, string $channel = 'dine_in'): array
    {
        $sale = $this->crmSold($this->at, $this->saleOf($lines, self::DAY, $channel));
        $this->fromCrm($this->at, 'crm.sale.recorded', $sale);

        return $sale;
    }

    /* ----------------------------------------------------- a quiet night */

    public function test_a_night_on_which_nothing_was_lost_is_all_in_order_and_is_checked_once(): void
    {
        $line = $this->kitchenLine($this->tikka, null, 2);
        $this->fromCrm($this->at, 'crm.kitchen.line_advanced', $line);
        $this->sold([[$this->tikka, null, 2, 640, [$line['line_id']]], [$this->onMenuCola, null, 3, 180]]);

        $movements = StockMovement::allOutlets()->count();

        $this->assertSame(0, $this->nightly());

        $this->assertSame(['balances' => 'ok', 'menu' => 'ok', 'reference' => 'ok', 'sales' => 'ok'], $this->statuses());
        $this->assertSame('ERP and CRM both have 1 bill worth ₹820.00 for the day.', $this->check('sales')->detail);
        $this->assertSame([0, 0], [$this->check('sales')->found, $this->check('sales')->repaired]);
        $this->assertSame($this->at['tenant'], $this->check('menu')->tenant_id);
        $this->assertSame($movements, StockMovement::allOutlets()->count(), 'a night with nothing to put right moves nothing');

        // Asked from outside any transaction of ERP's own (1 is the test's).
        $this->assertSame([1], array_values(array_unique($this->transactionDepths)));

        // An hour later the same day is finished, and nobody is asked again.
        $asked = [count($this->askedOfCrm()), count($this->askedOfAccounts())];
        $ranAt = $this->check('sales')->ran_at;
        $this->travel(1)->hours();

        $this->assertSame(0, $this->nightly());
        $this->assertSame($asked, [count($this->askedOfCrm()), count($this->askedOfAccounts())]);
        $this->assertEquals($ranAt, $this->check('sales')->ran_at);

        // Unless told to.
        $this->assertSame(0, $this->nightly(['--force' => true]));
        $this->assertGreaterThan($asked[0], count($this->askedOfCrm()));
        $this->assertTrue($this->check('sales')->ran_at->gt($ranAt));
        $this->assertSame(4, NightlyCheck::allOutlets()->count(), 'one row for each check, however often it is run');
    }

    /* ------------------------------------------------------------- sales */

    public function test_a_bill_erp_never_heard_of_is_fetched_and_its_stock_moves(): void
    {
        // Sold at the counter; the event never arrived.
        $sale = $this->crmSold($this->at, $this->saleOf([[$this->onMenuCola, null, 4, 240]], self::DAY, 'counter'));
        $this->assertSame('24', $this->onHand($this->cola));

        $this->assertSame(0, $this->nightly());

        $check = $this->check('sales');
        $this->assertSame([NightlyCheck::REPAIRED, 1, 1], [$check->status, $check->found, $check->repaired]);
        $this->assertSame(
            '1 bill ERP had not heard of was fetched from CRM and recorded ('.$sale['invoice_no'].'). ERP and CRM both have 1 bill worth ₹240.00 for the day.',
            $check->detail,
        );

        // Recorded as the event would have recorded it.
        $recorded = Sale::allOutlets()->sole();
        $this->assertSame([$sale['sale_id'], $sale['sale_id'], 'counter', 24_000, self::DAY],
            [$recorded->ref, $recorded->ulid, $recorded->channel, $recorded->grand_paise, $recorded->business_date->toDateString()]);
        $this->assertSame('20', $this->onHand($this->cola));

        // Booked on the day of the bill, though it was booked this morning.
        $movement = StockMovement::allOutlets()->where('type', StockMovement::CONSUMPTION)->sole();
        $this->assertSame([self::DAY, '2026-10-02 00:30:00'], [$movement->business_date->toDateString(), $movement->occurred_at->toDateTimeString()]);

        // The event was only late: it turns up, and nothing is counted twice.
        $this->fromCrm($this->at, 'crm.sale.recorded', $sale);

        $this->assertSame(1, Sale::allOutlets()->count());
        $this->assertSame('20', $this->onHand($this->cola));
        $this->assertSame([], $this->stock->reconcile($this->at['outlet']));
    }

    public function test_a_bill_cancelled_in_crm_is_cancelled_here_and_gives_its_stock_back(): void
    {
        $kept = $this->sold([[$this->onMenuCola, null, 1, 60]]);
        $voided = $this->sold([[$this->onMenuCola, null, 6, 360]]);
        $this->assertSame('17', $this->onHand($this->cola));

        // Cancelled at the till; crm.sale.cancelled never arrived.
        $this->crmCancelled($voided);

        $this->assertSame(0, $this->nightly());

        $check = $this->check('sales');
        $this->assertSame([NightlyCheck::REPAIRED, 1, 1], [$check->status, $check->found, $check->repaired]);
        $this->assertStringContainsString('1 bill cancelled in CRM was cancelled here ('.$voided['invoice_no'].').', $check->detail);

        $sale = Sale::allOutlets()->where('ref', $voided['sale_id'])->sole();
        $this->assertTrue($sale->isCancelled());
        $this->assertStringContainsString('nightly check', $sale->cancel_reason);
        $this->assertFalse(Sale::allOutlets()->where('ref', $kept['sale_id'])->sole()->isCancelled());
        $this->assertSame('23', $this->onHand($this->cola), 'the six bottles are back on the shelf');
    }

    public function test_a_bill_crm_has_already_cancelled_and_erp_never_heard_of_is_nothing_to_put_right(): void
    {
        $this->crmSold($this->at, $this->saleOf([[$this->onMenuCola, null, 6, 360]], self::DAY), 'cancelled');

        $this->assertSame(0, $this->nightly());

        $this->assertSame(NightlyCheck::OK, $this->check('sales')->status);
        $this->assertSame(0, Sale::allOutlets()->count());
        $this->assertSame('24', $this->onHand($this->cola));
    }

    public function test_a_bill_crm_does_not_list_is_named_and_left_alone(): void
    {
        $this->sold([[$this->onMenuCola, null, 1, 60]]);

        // ERP heard of this one; CRM says there is no such bill that day.
        $stray = $this->saleOf([[$this->onMenuCola, null, 2, 120]], self::DAY);
        $this->fromCrm($this->at, 'crm.sale.recorded', $stray);

        $this->assertSame(1, $this->nightly(), 'a problem is what the command exits with');

        $check = $this->check('sales');
        $this->assertSame([NightlyCheck::PROBLEM, 1, 0], [$check->status, $check->found, $check->repaired]);
        $this->assertSame(
            '1 bill is in ERP for this day but not in CRM\'s list ('.$stray['invoice_no'].'). Nothing was removed. '
            .'ERP has 2 bills worth ₹180.00 for the day; CRM has 1 bill worth ₹60.00.',
            $check->detail,
        );

        $this->assertSame(2, Sale::allOutlets()->where('status', Sale::RECORDED)->count());
        $this->assertSame('21', $this->onHand($this->cola));

        // A problem is not final: the next run looks again, and the others are left alone.
        $menuRanAt = $this->check('menu')->ran_at;
        $this->travel(1)->hours();

        $this->assertSame(1, $this->nightly());
        $this->assertSame('2026-10-02 01:30:00', $this->check('sales')->ran_at->toDateTimeString());
        $this->assertEquals($menuRanAt, $this->check('menu')->ran_at);

        // CRM turns out to have it after all.
        $this->crmSold($this->at, $stray);
        $this->travel(1)->hours();

        $this->assertSame(0, $this->nightly());
        $this->assertSame(NightlyCheck::OK, $this->check('sales')->status);
    }

    public function test_a_total_that_still_differs_is_a_problem_with_both_figures(): void
    {
        $sale = $this->sold([[$this->onMenuCola, null, 2, 120]]);

        // The bill was edited in CRM after ERP heard of it.
        $this->crmSales[$sale['sale_id']]['data']['totals']['grand_paise'] = 13_000;

        $this->assertSame(1, $this->nightly());

        $check = $this->check('sales');
        $this->assertSame(NightlyCheck::PROBLEM, $check->status);
        $this->assertSame('ERP has 1 bill worth ₹120.00 for the day; CRM has 1 bill worth ₹130.00.', $check->detail);
    }

    public function test_a_billed_dish_the_kitchen_never_reported_is_booked_and_a_late_event_books_nothing_more(): void
    {
        // Two tables. For the first the kitchen was never heard from at all;
        // for the second it got as far as "preparing" and "ready" was lost.
        $unheard = $this->kitchenLine($this->tikka, null, 2);
        $halfway = $this->kitchenLine($this->tikka, null, 1, 'preparing');
        $this->fromCrm($this->at, 'crm.kitchen.line_advanced', $halfway);

        $first = $this->sold([[$this->tikka, null, 2, 640, [$unheard['line_id']]]]);
        $this->sold([[$this->tikka, null, 1, 320, [$halfway['line_id']]]]);

        $this->assertSame('2000', $this->onHand($this->paneer), 'billed, but nothing was ever booked');
        $this->assertNull(app(Reports::class)->foodCost($this->at['outlet'], self::DAY, self::DAY)['percent']);

        $this->assertSame(0, $this->nightly());

        $check = $this->check('sales');
        $this->assertSame([NightlyCheck::REPAIRED, 2, 2], [$check->status, $check->found, $check->repaired]);
        $this->assertStringContainsString('2 dishes that were billed but never marked cooked were booked as used.', $check->detail);

        $this->assertSame('1250', $this->onHand($this->paneer), '750 g for three tikkas');

        $line = KitchenLine::allOutlets()->where('ref', $unheard['line_id'])->sole();
        $this->assertSame([Units::toBase(2, 'pc', 'pc'), 20_000, self::DAY, $this->tikka['item_id']],
            [$line->qty, $line->cost_paise, $line->business_date->toDateString(), $line->item_ref]);
        $this->assertSame('2026-10-02 00:30:00', $line->consumed_at->toDateTimeString());

        $movement = StockMovement::allOutlets()->where('reference_type', $line->getMorphClass())->where('reference_id', $line->id)->sole();
        $this->assertSame([StockMovement::CONSUMPTION, self::DAY], [$movement->type, $movement->business_date->toDateString()]);
        $this->assertSame('On bill '.$first['invoice_no'].' but never marked cooked; booked by the nightly check', $movement->reason);

        // The day's food cost now has the food in it: Rs 300 of paneer on Rs 960.
        $this->assertSame([96_000, 30_000, '31.25'], array_values(array_intersect_key(
            app(Reports::class)->foodCost($this->at['outlet'], self::DAY, self::DAY), ['revenue' => 1, 'cost' => 1, 'percent' => 1],
        )));

        // The kitchen's events were only late. They arrive, every stage of them.
        $ledger = StockMovement::allOutlets()->count();

        foreach (['accepted', 'preparing', 'ready', 'served'] as $stage) {
            $this->fromCrm($this->at, 'crm.kitchen.line_advanced', ['stage' => $stage] + $unheard);
            $this->fromCrm($this->at, 'crm.kitchen.line_advanced', ['stage' => $stage] + $halfway);
        }

        $this->assertSame('1250', $this->onHand($this->paneer));
        $this->assertSame($ledger, StockMovement::allOutlets()->count(), 'a late kitchen event books nothing twice');
        $this->assertSame(30_000, (int) KitchenLine::allOutlets()->sum('cost_paise'));

        // And a second look at the same day finds nothing left to book.
        $this->assertSame(0, $this->nightly(['--force' => true]));
        $this->assertSame(NightlyCheck::OK, $this->check('sales')->status);
        $this->assertSame([], $this->stock->reconcile($this->at['outlet']));
    }

    public function test_a_line_the_kitchen_cancelled_or_already_booked_is_not_booked_by_the_check(): void
    {
        $cooked = $this->kitchenLine($this->tikka, null, 1);
        $this->fromCrm($this->at, 'crm.kitchen.line_advanced', $cooked);

        $cancelled = $this->kitchenLine($this->tikka, null, 1, 'accepted');
        $this->fromCrm($this->at, 'crm.kitchen.line_advanced', $cancelled);
        $this->fromCrm($this->at, 'crm.kitchen.line_cancelled', ['stage_reached' => 'accepted', 'reason' => 'Guest left'] + array_diff_key($cancelled, ['stage' => 1]));

        $this->sold([[$this->tikka, null, 1, 320, [$cooked['line_id']]], [$this->tikka, null, 1, 320, [$cancelled['line_id']]]]);
        $this->assertSame('1750', $this->onHand($this->paneer));

        $this->assertSame(0, $this->nightly());

        $this->assertSame(NightlyCheck::OK, $this->check('sales')->status);
        $this->assertSame('1750', $this->onHand($this->paneer));
    }

    public function test_a_kitchen_line_booked_by_the_check_and_then_cancelled_gives_its_stock_back(): void
    {
        $line = $this->kitchenLine($this->tikka, null, 2);
        $this->sold([[$this->tikka, null, 2, 640, [$line['line_id']]]]);

        $this->nightly();
        $this->assertSame('1500', $this->onHand($this->paneer));

        // It had never been cooked after all: the kitchen's cancellation was the late event.
        $this->fromCrm($this->at, 'crm.kitchen.line_cancelled', ['stage_reached' => 'accepted', 'reason' => 'Never made'] + array_diff_key($line, ['stage' => 1]));

        $this->assertSame('2000', $this->onHand($this->paneer));
        $this->assertSame([], $this->stock->reconcile($this->at['outlet']));
    }

    public function test_crm_being_down_is_a_problem_tonight_and_put_right_the_hour_after(): void
    {
        $sale = $this->crmSold($this->at, $this->saleOf([[$this->onMenuCola, null, 4, 240]], self::DAY));
        $this->crmDown = true;

        $this->assertSame(1, $this->nightly());

        $this->assertSame(['balances' => 'ok', 'menu' => 'problem', 'reference' => 'ok', 'sales' => 'problem'], $this->statuses());
        $this->assertStringStartsWith('CRM could not be reached, so the day\'s sales were not compared.', $this->check('sales')->detail);
        $this->assertStringStartsWith('CRM could not be reached, so the menu was not compared.', $this->check('menu')->detail);
        $this->assertSame('24', $this->onHand($this->cola));

        $this->crmDown = false;
        $this->travel(1)->hours();

        $this->assertSame(0, $this->nightly());

        $this->assertSame(['balances' => 'ok', 'menu' => 'ok', 'reference' => 'ok', 'sales' => 'repaired'], $this->statuses());
        $this->assertSame($sale['sale_id'], Sale::allOutlets()->sole()->ref);
        $this->assertSame('20', $this->onHand($this->cola));
    }

    public function test_crm_failing_part_way_keeps_what_was_fetched_and_tries_the_rest_again(): void
    {
        $this->crmSold($this->at, $this->saleOf([[$this->onMenuCola, null, 1, 60]], self::DAY));
        $lost = $this->crmSold($this->at, $this->saleOf([[$this->onMenuCola, null, 2, 120]], self::DAY));

        // CRM lists the second bill but fails when asked for it.
        $this->crmChokesOn = [$lost['sale_id']];

        $this->assertSame(1, $this->nightly());

        $check = $this->check('sales');
        $this->assertSame([NightlyCheck::PROBLEM, 2, 1], [$check->status, $check->found, $check->repaired]);
        $this->assertStringContainsString('CRM stopped answering part-way through: Khana CRM answered with an error (500).', $check->detail);
        $this->assertSame(1, Sale::allOutlets()->count(), 'the bill fetched before it failed is kept');

        $this->crmChokesOn = [];
        $this->travel(1)->hours();

        $this->assertSame(0, $this->nightly());
        $this->assertSame([NightlyCheck::REPAIRED, 1, 1], [$this->check('sales')->status, $this->check('sales')->found, $this->check('sales')->repaired]);
        $this->assertSame(2, Sale::allOutlets()->count());
        $this->assertSame('21', $this->onHand($this->cola));
    }

    public function test_a_bill_erp_cannot_make_sense_of_stops_the_check_not_the_night(): void
    {
        $this->crmSold($this->at, $this->saleOf([[$this->onMenuCola, null, 1, 60]], self::DAY));
        $lost = $this->crmSold($this->at, $this->saleOf([[$this->onMenuCola, null, 2, 120]], self::DAY));

        // A quantity no service would send.
        $held = $this->crmSales[$lost['sale_id']];
        $this->crmSales[$lost['sale_id']]['data']['lines'][0]['qty'] = 'lots';

        $this->assertSame(1, $this->nightly());

        $this->assertSame(['balances' => 'ok', 'menu' => 'ok', 'reference' => 'ok', 'sales' => 'problem'], $this->statuses());
        $this->assertStringContainsString('stopped before it finished', $this->check('sales')->detail);
        $this->assertSame(1, Sale::allOutlets()->count(), 'the bill fetched before it stopped is kept');
        $this->assertSame([], $this->stock->reconcile($this->at['outlet']));

        $this->crmSales[$lost['sale_id']] = $held;
        $this->travel(1)->hours();

        $this->assertSame(0, $this->nightly());
        $this->assertSame(2, Sale::allOutlets()->count());
        $this->assertSame('21', $this->onHand($this->cola));
    }

    public function test_an_outlet_with_no_crm_is_not_compared_with_one_and_crm_is_never_asked(): void
    {
        $alone = $this->business('Dosa Corner', ['erp' => 'active']);

        $this->assertSame(0, $this->nightly(['outlet' => $alone['outlet']]));

        $this->assertSame(['balances' => 'ok', 'menu' => 'skipped', 'reference' => 'ok', 'sales' => 'skipped'], $this->statuses($alone));
        $this->assertSame('This outlet does not use Khana CRM, so there are no CRM sales to compare.', $this->check('sales', $alone)->detail);
        $this->assertSame([], $this->askedOfCrm());

        // Only the outlet named was checked.
        $this->assertSame([], $this->statuses());
    }

    /* -------------------------------------------------------------- menu */

    public function test_a_menu_that_drifted_is_put_right_overnight(): void
    {
        // A new dish, and one taken off the menu: both events lost.
        $this->crmLists($this->at, $this->menuItem('Kulfi', 110));
        $this->crmLists($this->at, $this->onMenuCola, archived: true);

        $this->assertSame(0, $this->nightly());

        $check = $this->check('menu');
        $this->assertSame([NightlyCheck::REPAIRED, 2, 2], [$check->status, $check->found, $check->repaired]);
        $this->assertSame('Menu brought in line with CRM: 1 dish added, 1 dish put away.', $check->detail);
        $this->assertSame(['Cola' => 0, 'Kulfi' => 1, 'Paneer Tikka' => 1],
            SalesItem::allOutlets()->orderBy('name')->pluck('is_active', 'name')->map(fn ($on) => (int) $on)->all());
    }

    public function test_a_bill_for_a_dish_erp_had_not_heard_of_is_recorded_against_it_because_the_menu_comes_first(): void
    {
        $water = $this->stockItem($this->at['tenant'], 'Water 1 l', 'pc', 12, ['barcode' => '8900000000017']);
        $this->stocked($water, 12, 'pc');

        // Added to the menu and sold the same evening; ERP heard neither.
        $onMenu = $this->menuItem('Water', 20, [], ['barcode' => '8900000000017']);
        $this->crmLists($this->at, $onMenu);
        $this->crmSold($this->at, $this->saleOf([[$onMenu, null, 5, 100]], self::DAY, 'counter'));

        $this->assertSame(0, $this->nightly());

        $this->assertSame(['balances' => 'ok', 'menu' => 'repaired', 'reference' => 'ok', 'sales' => 'repaired'], $this->statuses());
        $this->assertSame(Units::toBase(7, 'pc', 'pc'), $this->stock->onHand($this->at['outlet'], $water));
    }

    /* --------------------------------------------------------- reference */

    public function test_details_that_differ_from_accounts_are_brought_in_line(): void
    {
        $person = fn (string $name, string $role) => User::create([
            'ulid' => Ulid::generate(), 'tenant_id' => $this->at['tenant'], 'name' => $name,
            'email' => strtolower($name).'@spicegarden.test', 'role_key' => $role, 'outlet_ids' => [$this->at['outlet']],
        ]);

        $ravi = $person('Ravi', 'storekeeper');
        $meena = $person('Meena', 'store_manager');
        $asha = $person('Asha', 'accountant');
        DB::table('sessions')->insert(['id' => 'meena-at-work', 'user_id' => $meena->id, 'payload' => '', 'last_activity' => time()]);

        // What Accounts holds now; none of the events that said so arrived.
        $snapshot = $this->snapshotOf($this->at['tenant']);
        $snapshot['outlets'][0]['name'] = 'Spice Garden, Saket';
        $snapshot['outlets'][0]['day_cutoff'] = '05:00';
        $snapshot['entitlements'][1]['state'] = 'grace';
        $snapshot['access'] = [
            // Ravi was made an auditor; Meena has left ERP; Asha is as she was.
            ['user_id' => $ravi->ulid, 'product' => 'erp', 'role_key' => 'auditor', 'outlet_ids' => [$this->at['outlet']]],
            ['user_id' => $asha->ulid, 'product' => 'erp', 'role_key' => 'accountant', 'outlet_ids' => [$this->at['outlet']]],
        ];
        $this->snapshots[$this->at['tenant']] = $snapshot;

        $this->assertSame(0, $this->nightly());

        $check = $this->check('reference');
        $this->assertSame([NightlyCheck::REPAIRED, 4, 4], [$check->status, $check->found, $check->repaired]);
        $this->assertSame('4 details that differed from Accounts were brought in line.', $check->detail);

        $outlet = DB::table('khana_outlets')->where('id', $this->at['outlet'])->first();
        $this->assertSame(['Spice Garden, Saket', '05:00'], [$outlet->name, $outlet->day_cutoff]);
        $this->assertSame('grace', DB::table('khana_entitlements')->where('outlet_id', $this->at['outlet'])->where('product', 'crm')->value('state'));
        $this->assertSame('auditor', $ravi->fresh()->role_key);
        $this->assertSame([null, []], [$meena->fresh()->role_key, $meena->fresh()->outlet_ids]);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $meena->id)->count(), 'whatever she had open is closed');
        $this->assertSame('accountant', $asha->fresh()->role_key);

        // The outlet's new name is used from here on, in the same run.
        $this->assertSame('Spice Garden, Saket', Outlets::name($this->at['outlet']));

        // Nothing differs any more.
        $this->snapshots = [];
        $this->assertSame(0, $this->nightly(['--force' => true]));
        $this->assertSame(NightlyCheck::OK, $this->check('reference')->status);
    }

    public function test_what_erp_holds_that_accounts_does_not_list_is_named_and_never_deleted(): void
    {
        $branch = $this->business('Spice Garden', ['erp' => 'active'], $this->at['tenant']);
        $branchName = DB::table('khana_outlets')->where('id', $branch['outlet'])->value('name');
        $mainName = DB::table('khana_outlets')->where('id', $this->at['outlet'])->value('name');

        // Accounts lists neither the branch nor a CRM plan for the first outlet.
        $snapshot = $this->snapshotOf($this->at['tenant']);
        $snapshot['outlets'] = [$snapshot['outlets'][0]];
        $snapshot['entitlements'] = [$snapshot['entitlements'][0]];
        $this->snapshots[$this->at['tenant']] = $snapshot;

        $this->assertSame(1, $this->nightly(['outlet' => $this->at['outlet']]));

        $check = $this->check('reference');
        $this->assertSame([NightlyCheck::PROBLEM, 3, 0], [$check->status, $check->found, $check->repaired]);
        $this->assertSame(
            'ERP holds an outlet called "'.$branchName.'" that Accounts does not list for this business. '
            .'ERP holds a Khana CRM plan for "'.$mainName.'" that Accounts does not list. '
            .'ERP holds a Khana ERP plan for "'.$branchName.'" that Accounts does not list. Nothing was removed.',
            $check->detail,
        );

        $this->assertSame(2, DB::table('khana_outlets')->where('tenant_id', $this->at['tenant'])->count());
        $this->assertSame(3, DB::table('khana_entitlements')->where('tenant_id', $this->at['tenant'])->count());
    }

    public function test_a_snapshot_is_never_written_over_something_newer(): void
    {
        $late = User::create([
            'ulid' => Ulid::generate(), 'tenant_id' => $this->at['tenant'], 'name' => 'Late Arrival',
            'role_key' => 'storekeeper', 'outlet_ids' => [$this->at['outlet']],
        ]);

        // Accounts' picture, taken a minute before two things changed here.
        $snapshot = $this->snapshotOf($this->at['tenant']);
        $snapshot['outlets'][0]['name'] = 'The Old Name';
        $snapshot['access'] = [];
        $this->snapshots[$this->at['tenant']] = $snapshot;

        $this->travel(1)->minutes();
        DB::table('khana_outlets')->where('id', $this->at['outlet'])->update(['name' => 'The New Name', 'changed_at' => now()->format('Y-m-d H:i:s')]);
        $late->forceFill(['last_login_at' => now()])->save();

        $this->assertSame(0, $this->nightly());

        $this->assertSame(NightlyCheck::OK, $this->check('reference')->status);
        $this->assertSame('The New Name', DB::table('khana_outlets')->where('id', $this->at['outlet'])->value('name'));
        $this->assertSame('storekeeper', $late->fresh()->role_key, 'somebody who signed in since the snapshot is not shut out by it');
    }

    public function test_accounts_is_asked_once_for_each_business_however_many_outlets_it_has(): void
    {
        $this->business('Spice Garden', ['erp' => 'active'], $this->at['tenant']);
        $this->business('Spice Garden', ['erp' => 'suspended'], $this->at['tenant']);
        $other = $this->business('Dosa Corner', ['erp' => 'active']);
        // Holds no ERP: not ERP's to check.
        $crmOnly = $this->business('Chai Point', ['crm' => 'active']);

        $this->assertSame(0, $this->nightly());

        $this->assertCount(2, $this->askedOfAccounts());
        $this->assertSame(4, NightlyCheck::allOutlets()->where('check', NightlyCheck::REFERENCE)->where('status', NightlyCheck::OK)->count());
        $this->assertSame(['balances' => 'ok', 'menu' => 'skipped', 'reference' => 'ok', 'sales' => 'skipped'], $this->statuses($other));
        $this->assertSame([], $this->statuses($crmOnly));
    }

    public function test_accounts_being_down_is_a_problem_and_the_other_checks_still_run(): void
    {
        $this->accountsDown = true;

        // The token for CRM comes from Accounts too, so tonight nobody can be asked anything.
        $this->assertSame(1, $this->nightly());

        $this->assertSame(['balances' => 'ok', 'menu' => 'problem', 'reference' => 'problem', 'sales' => 'problem'], $this->statuses());
        $this->assertStringStartsWith('Accounts could not be reached, so the business details were not compared.', $this->check('reference')->detail);

        $this->accountsDown = false;
        $this->travel(1)->hours();

        $this->assertSame(0, $this->nightly());
        $this->assertSame(['balances' => 'ok', 'menu' => 'ok', 'reference' => 'ok', 'sales' => 'ok'], $this->statuses());
    }

    public function test_an_outlet_found_to_hold_crm_gets_its_menu_the_same_night(): void
    {
        $at = $this->business('Dosa Corner', ['erp' => 'active']);
        $this->crmLists($at, $this->menuItem('Masala Dosa', 120));

        // The outlet bought CRM, and the event that said so never arrived.
        $snapshot = $this->snapshotOf($at['tenant']);
        $snapshot['entitlements'][] = ['outlet_id' => $at['outlet'], 'product' => 'crm', 'state' => 'active', 'plan' => 'crm', 'limits' => [], 'valid_until' => null];
        $this->snapshots[$at['tenant']] = $snapshot;

        $this->assertSame(0, $this->nightly(['outlet' => $at['outlet']]));

        $statuses = $this->statuses($at);
        $this->assertSame('repaired', $statuses['reference']);
        $this->assertNotSame('skipped', $statuses['menu']);
        $this->assertSame('ok', $statuses['sales']);
        $this->assertSame(['Masala Dosa'], SalesItem::allOutlets()->where('outlet_id', $at['outlet'])->pluck('name')->all());
        $this->assertNotNull(Outlets::settings($at['outlet'])->menu_synced_at);
    }

    /* ---------------------------------------------------------- balances */

    public function test_a_stock_level_that_does_not_equal_its_movements_is_named_and_not_corrected(): void
    {
        $level = StockLevel::allOutlets()->where('stock_item_id', $this->paneer->id)->sole();
        // Somebody edited the level behind StockService's back.
        DB::table('stock_levels')->where('id', $level->id)->update(['qty' => Units::toBase(1500, 'g', 'g')]);

        $this->assertSame(1, $this->nightly());

        $check = $this->check('balances');
        $this->assertSame([NightlyCheck::PROBLEM, 1, 0], [$check->status, $check->found, $check->repaired]);
        $this->assertSame(
            '1 stock level does not equal the movements behind it: Paneer in Kitchen says 1.5 kg, its movements add up to 2 kg. Nothing was changed.',
            $check->detail,
        );

        $this->assertSame(Units::toBase(1500, 'g', 'g'), $level->fresh()->qty);
        // The older command says the same thing.
        $this->artisan('erp:reconcile', ['outlet' => $this->at['outlet']])->assertFailed();
    }

    public function test_a_supplier_bill_that_does_not_equal_its_payments_is_named_and_not_corrected(): void
    {
        $dairy = Supplier::allTenants()->create(['tenant_id' => $this->at['tenant'], 'name' => 'Amul Dairy']);
        $bill = SupplierBill::allOutlets()->create(['outlet_id' => $this->at['outlet'], 'supplier_id' => $dairy->id, 'bill_no' => 'AD/1', 'billed_on' => '2026-09-28', 'total_paise' => 50_000]);
        $settled = SupplierBill::allOutlets()->create(['outlet_id' => $this->at['outlet'], 'supplier_id' => $dairy->id, 'bill_no' => 'AD/2', 'billed_on' => '2026-09-29', 'total_paise' => 10_000]);

        app(Purchasing::class)->pay($this->at['outlet'], $dairy, 20_000, 'upi', '2026-09-30', $bill);
        app(Purchasing::class)->pay($this->at['outlet'], $dairy, 10_000, 'cash', '2026-09-30', $settled);

        $this->assertSame(0, $this->nightly());
        $this->assertSame(NightlyCheck::OK, $this->check('balances')->status);

        // The paid figure is changed by hand.
        DB::table('supplier_bills')->where('id', $bill->id)->update(['paid_paise' => 35_000]);

        $this->assertSame(1, $this->nightly(['--force' => true]));

        $check = $this->check('balances');
        $this->assertSame([NightlyCheck::PROBLEM, 1, 0], [$check->status, $check->found, $check->repaired]);
        $this->assertSame(
            '1 supplier bill does not equal the payments made against it: bill AD/1 from Amul Dairy says ₹350.00 paid, its payments add up to ₹200.00. Nothing was changed.',
            $check->detail,
        );
        $this->assertSame(35_000, $bill->fresh()->paid_paise);
    }

    /* ------------------------------------------------------- the command */

    public function test_each_outlet_is_checked_for_its_own_night(): void
    {
        // Half past eight in the evening of the 1st in New York: the day there is still the 1st.
        $far = $this->business('Curry House', ['erp' => 'active']);
        DB::table('khana_outlets')->where('id', $far['outlet'])->update(['timezone' => 'America/New_York']);
        Outlets::forget();

        $this->assertSame(0, $this->nightly());

        $this->assertCount(4, $this->statuses($this->at, '2026-10-01'));
        $this->assertCount(4, $this->statuses($far, '2026-09-30'));
        $this->assertSame([], $this->statuses($far, '2026-10-01'));

        // Eight hours on its day has closed too, and that night is checked in its turn.
        $this->travel(8)->hours();
        $this->assertSame(0, $this->nightly());
        $this->assertCount(4, $this->statuses($far, '2026-10-01'));
    }

    public function test_a_day_can_be_named_but_not_one_still_being_traded(): void
    {
        $this->assertSame(0, $this->nightly(['--date' => '2026-09-29']));
        $this->assertCount(4, $this->statuses($this->at, '2026-09-29'));
        $this->assertSame(4, NightlyCheck::allOutlets()->count());

        // Today is not over: "all in order" said of half a day would stand.
        $this->artisan('erp:nightly', ['--date' => '2026-10-02'])->expectsOutputToContain('is not over yet')->assertSuccessful();
        $this->assertSame(4, NightlyCheck::allOutlets()->count());

        $this->artisan('erp:nightly', ['--date' => '02/10/2026'])->assertExitCode(2);
        $this->artisan('erp:nightly', ['outlet' => Ulid::generate()])->assertExitCode(2);
    }

    public function test_the_checks_and_the_watch_are_on_the_schedule(): void
    {
        $scheduled = collect(app(Schedule::class)->events())
            ->mapWithKeys(fn ($event) => [trim((string) preg_replace('/^.*artisan["\']?\s+/', '', $event->command)) => $event->expression]);

        $this->assertSame('10 * * * *', $scheduled['erp:nightly']);
        $this->assertSame('*/10 * * * *', $scheduled['erp:watch']);
        $this->assertSame('30 4 * * *', $scheduled['erp:reconcile']);
    }
}
