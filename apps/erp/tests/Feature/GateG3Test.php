<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\KitchenLine;
use App\Models\Sale;
use App\Models\SalesAddon;
use App\Models\SalesItem;
use App\Models\StockDocument;
use App\Models\StockItem;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Services\Purchasing;
use App\Services\Recipes;
use App\Services\Reports;
use App\Services\StockService;
use App\Services\StoreRoom;
use App\Support\Outlets;
use App\Support\Units;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Khana\Kit\Events\Inbox\InboxEvent;
use Khana\Kit\Support\Ulid;
use Tests\Concerns\HearsFromCrm;
use Tests\TestCase;

/**
 * Gate G3 (architecture doc): "ERP alone runs receipt, recipe, imported
 * sales, count and variance. With CRM, stock moves within 5 seconds of a
 * kitchen line; a replay from zero rebuilds identical stock."
 *
 * Three tests, one for each sentence:
 *
 *   - ERP alone: an outlet with no CRM receives a delivery, writes a recipe,
 *     imports a day's sales from its billing software, counts the shelf and
 *     reads what went missing - all through the screens a person uses.
 *
 *   - With CRM: a kitchen line arriving at the inbox has moved the stock
 *     well inside five seconds. (The time across two real services is
 *     measured in the joint smoke test; what is held here is that nothing
 *     on ERP's side - a queue, a schedule, a batch - stands in the way.)
 *
 *   - Replay from zero: a whole service of events is applied to one outlet,
 *     then to a second, empty outlet set up the same way - this time with
 *     every event sent twice. The two end with the same levels and the
 *     same ledger, row for row: nothing counted twice, nothing lost.
 */
class GateG3Test extends TestCase
{
    use HearsFromCrm, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');
    }

    /* ------------------------------------------------------- ERP alone */

    public function test_erp_alone_runs_receipt_recipe_imported_sales_count_and_variance(): void
    {
        Storage::fake('local');

        // An outlet that bills with something else: ERP, and no CRM.
        $at = $this->business('Dosa Corner', ['erp' => 'active']);
        $this->signInAs('owner', $at);

        $batter = $this->stockItem($at['tenant'], 'Dosa Batter', 'g');
        $potato = $this->stockItem($at['tenant'], 'Potato', 'g');
        $supplier = Supplier::allTenants()->create(['tenant_id' => $at['tenant'], 'name' => 'Sri Krishna Stores']);
        $main = Outlets::stores($at['outlet'])['main'];
        $stock = app(StockService::class);

        // Receipt: 20 kg of batter at Rs 60, 10 kg of potato at Rs 30.
        $this->post(route('receipts.store'), [
            'supplier_id' => $supplier->id, 'store_id' => $main->id, 'received_on' => '2026-10-01', 'bill_no' => 'SK/77',
            'lines' => [
                ['item' => $batter->id, 'qty' => '20', 'unit' => 'kg', 'price' => '60'],
                ['item' => $potato->id, 'qty' => '10', 'unit' => 'kg', 'price' => '30'],
            ],
        ])->assertRedirect();

        $this->assertSame(Units::toBase(20, 'kg', 'g'), $stock->onHand($at['outlet'], $batter));

        // Imported sales: the first file teaches ERP the dishes.
        $import = function (string $name, string $csv) {
            $response = $this->post(route('sales.import.upload'), ['file' => UploadedFile::fake()->createWithContent($name, $csv)])->assertRedirect();
            $token = basename(parse_url($response->headers->get('Location'), PHP_URL_PATH));

            $this->get(route('sales.import.match', $token))->assertOk();
            $this->post(route('sales.import.store', $token), [
                'layout' => ['date' => 0, 'item' => 1, 'qty' => 2, 'amount' => 3, 'tax' => '', 'size' => '', 'invoice' => ''],
            ])->assertRedirect(route('sales.index'));
        };

        $import('1-oct.csv', "Date,Item,Qty,Amount\n01/10/2026,Masala Dosa,40,4800\n01/10/2026,Plain Dosa,25,2250\n");

        $this->assertSame(Units::toBase(20, 'kg', 'g'), $stock->onHand($at['outlet'], $batter), 'no recipe yet, so nothing was used');
        $this->get(route('recipes.index'))->assertOk()->assertSee('Needs a recipe')->assertSee('Masala Dosa')->assertSee('Plain Dosa');

        // Recipe: masala dosa is 150 g batter and 100 g potato; plain is 150 g batter.
        $masala = SalesItem::allOutlets()->where('name', 'Masala Dosa')->sole();
        $plain = SalesItem::allOutlets()->where('name', 'Plain Dosa')->sole();

        $this->put(route('recipes.update', ['item' => $masala->id]), ['lines' => [
            ['item' => $batter->id, 'qty' => '150', 'unit' => 'g'],
            ['item' => $potato->id, 'qty' => '100', 'unit' => 'g'],
        ]])->assertRedirect(route('recipes.index'));

        $this->put(route('recipes.update', ['item' => $plain->id]), ['lines' => [
            ['item' => $batter->id, 'qty' => '150', 'unit' => 'g'],
        ]])->assertRedirect(route('recipes.index'));

        // The next day's file uses stock: 30 masala and 20 plain.
        $import('2-oct.csv', "Date,Item,Qty,Amount\n02/10/2026,Masala Dosa,30,3600\n02/10/2026,Plain Dosa,20,1800\n");

        $this->assertSame(Units::toBase('12.5', 'kg', 'g'), $stock->onHand($at['outlet'], $batter), '50 dosas at 150 g');
        $this->assertSame(Units::toBase(7, 'kg', 'g'), $stock->onHand($at['outlet'], $potato), '30 masala dosas at 100 g');

        // Count: the shelf has 11.9 kg of batter, not 12.5; the potato matches.
        $this->post(route('counts.store'), [
            'store_id' => $main->id, 'on_date' => '2026-10-02',
            'rows' => [
                ['item' => $batter->id, 'batch_id' => 0, 'qty' => '11.9'],
                ['item' => $potato->id, 'batch_id' => 0, 'qty' => '7'],
            ],
        ])->assertRedirect();

        $this->assertSame(Units::toBase('11.9', 'kg', 'g'), $stock->onHand($at['outlet'], $batter));

        // Variance: 600 g of batter, Rs 36, gone with no explanation.
        $variance = app(Reports::class)->variance($at['outlet'], '2026-10-01', '2026-10-02')->keyBy(fn ($row) => $row['item']->name);

        $this->assertSame(Units::toBase(20, 'kg', 'g'), $variance['Dosa Batter']['received']);
        $this->assertSame(Units::toBase('7.5', 'kg', 'g'), $variance['Dosa Batter']['used']);
        $this->assertSame(Units::toBase('-0.6', 'kg', 'g'), $variance['Dosa Batter']['difference']);
        $this->assertSame(-3_600, $variance['Dosa Batter']['difference_value']);
        $this->assertSame(0, $variance['Potato']['difference']);

        $this->get(route('reports.show', ['report' => 'variance', 'from' => '2026-10-01', 'to' => '2026-10-02']))
            ->assertOk()->assertSee('Dosa Batter')->assertSee('36.00');

        // Food cost for the second day: Rs 5,400 sold; 7.5 kg batter and 3 kg potato used.
        $food = app(Reports::class)->foodCost($at['outlet'], '2026-10-02', '2026-10-02');
        $this->assertSame(540_000, $food['revenue']);
        $this->assertSame(45_000 + 9_000, $food['cost']);
        $this->assertSame('10.00', $food['percent']);

        // The delivery raised a bill, and the books balance.
        $this->get(route('bills.index'))->assertOk()->assertSee('Sri Krishna Stores')->assertSee('1,500.00');
        $this->assertSame([], $stock->reconcile($at['outlet']));
        $this->artisan('erp:reconcile', ['outlet' => $at['outlet']])->assertSuccessful();
    }

    /* -------------------------------------------------------- with CRM */

    public function test_with_crm_stock_moves_within_five_seconds_of_a_kitchen_line(): void
    {
        $at = $this->business('Spice Garden', ['erp' => 'active', 'crm' => 'active']);
        $paneer = $this->stockItem($at['tenant'], 'Paneer', 'g', 400);
        app(StockService::class)->receive(Outlets::stores($at['outlet'])['kitchen'], $paneer, Units::toBase(2, 'kg', 'g'),
            $paneer->last_cost_rate, new \App\Stock\Posting(StockMovement::RECEIPT, businessDate: '2026-10-01'));

        $tikka = $this->menuItem('Paneer Tikka', 320);
        $this->fromCrm($at, 'crm.menu.item_upserted', $tikka);
        $this->recipe($at, $tikka, null, [[$paneer, 250, 'g']]);

        $event = $this->crmEvent($at, 'crm.kitchen.line_advanced', $this->kitchenLine($tikka, null, 2));

        // From the batch arriving at the inbox to the stock having moved.
        $started = microtime(true);
        $this->deliver([$event]);
        $took = microtime(true) - $started;

        $movement = StockMovement::allOutlets()->where('type', StockMovement::CONSUMPTION)->sole();

        $this->assertSame(Units::toBase(-500, 'g', 'g'), $movement->qty);
        $this->assertSame($event['id'], $movement->event_id);
        $this->assertLessThan(5.0, $took, 'a kitchen line must move stock within five seconds');

        // Applied where it arrived: no queue was left holding it.
        $this->assertSame(InboxEvent::APPLIED, InboxEvent::query()->where('event_id', $event['id'])->value('status'));
        $this->assertSame(0, InboxEvent::query()->where('status', InboxEvent::PENDING)->count());
    }

    /* ------------------------------------------------ replay from zero */

    public function test_a_replay_from_zero_rebuilds_identical_stock(): void
    {
        // Two outlets of one business, set up the same way.
        $live = $this->business('Spice Garden', ['erp' => 'active', 'crm' => 'active']);
        $rebuilt = $this->business('Spice Garden', ['erp' => 'active', 'crm' => 'active'], $live['tenant']);

        $items = [
            'paneer' => $this->stockItem($live['tenant'], 'Paneer', 'g', 0, ['track_expiry' => true]),
            'curd' => $this->stockItem($live['tenant'], 'Curd', 'g'),
            'masala' => $this->stockItem($live['tenant'], 'Tikka Masala', 'g'),
            'cheese' => $this->stockItem($live['tenant'], 'Cheese', 'g'),
            'cola' => $this->stockItem($live['tenant'], 'Cola 300 ml', 'pc', 0, ['barcode' => '8901764012273']),
        ];

        // One evening, as CRM would have reported it. Built once: the same
        // payloads go to both outlets.
        $tikka = $this->menuItem('Paneer Tikka', 320, [['Full', 320], ['Half', 180]], ['addons' => [[
            'option_id' => $cheeseOption = Ulid::generate(), 'modifier_id' => Ulid::generate(), 'modifier_name' => 'Extras',
            'name' => 'Extra cheese', 'price_paise' => 4_000, 'active' => true,
        ]]]);
        $cola = $this->menuItem('Cola', 60, [], ['barcode' => '8901764012273']);
        $soup = $this->menuItem('Tomato Soup', 120);

        $lines = [
            'a' => $this->kitchenLine($tikka, 'Full', 2),
            'b' => $this->kitchenLine($tikka, 'Half', 3, 'ready', ['option_ids' => [$cheeseOption]]),
            'c' => $this->kitchenLine($tikka, 'Full', 1),
            'd' => $this->kitchenLine($tikka, 'Half', 1),
            'e' => $this->kitchenLine($tikka, 'Full', 4),
            'f' => $this->kitchenLine($soup, null, 2),
        ];

        $stage = fn (string $key, string $stage) => ['crm.kitchen.line_advanced', ['stage' => $stage] + $lines[$key]];
        $cancel = fn (string $key, string $reached, string $why) => ['crm.kitchen.line_cancelled',
            ['stage_reached' => $reached, 'reason' => $why] + array_diff_key($lines[$key], ['stage' => 1])];

        $billOne = $this->saleOf([
            [$tikka, 'Full', 2, 640, [$lines['a']['line_id']]],
            [$tikka, 'Half', 3, 660, [$lines['b']['line_id']]],
            [$cola, null, 4, 240],
        ]);
        $billTwo = $this->saleOf([[$cola, null, 6, 360]], channel: 'counter');
        $billThree = $this->saleOf([[$tikka, 'Full', 4, 1280, [$lines['e']['line_id']]], [$soup, null, 2, 240, [$lines['f']['line_id']]]]);

        $evening = [
            ['crm.menu.item_upserted', $tikka],
            ['crm.menu.item_upserted', $cola],
            ['crm.menu.item_upserted', $soup],
            'recipes',
            $stage('a', 'accepted'), $stage('b', 'accepted'), $stage('a', 'preparing'),
            $stage('a', 'ready'), $stage('b', 'ready'),
            // Cancelled before anybody cooked it: nothing to book.
            $stage('c', 'accepted'), $cancel('c', 'accepted', 'Guest left'),
            // Cancelled on the stove: the food is wastage.
            $stage('d', 'preparing'), $cancel('d', 'preparing', 'Dropped'),
            ['crm.sale.recorded', $billOne],
            $stage('a', 'served'), $stage('b', 'served'),
            ['crm.sale.recorded', $billTwo],
            ['crm.sale.cancelled', ['sale_id' => $billTwo['sale_id'], 'reason' => 'Rang up twice']],
            $stage('e', 'ready'), $stage('f', 'ready'),
            ['crm.sale.recorded', $billThree],
            ['crm.sale.returned', [
                'return_id' => Ulid::generate(), 'sale_id' => $billOne['sale_id'], 'business_date' => '2026-10-01',
                'lines' => [['line_id' => $billOne['lines'][2]['line_id'], 'qty' => '1.0000', 'back_to_stock' => true]],
            ]],
        ];

        // The live outlet hears each event once, as it happens.
        $this->prepare($live, $items);

        foreach ($evening as $step) {
            $step === 'recipes'
                ? $this->writeRecipes($live, $items, $tikka, $cheeseOption)
                : $this->fromCrm($live, $step[0], $step[1]);
        }

        // The rebuilt outlet starts from zero and is sent everything twice,
        // in batches - a replay after an outage.
        $this->prepare($rebuilt, $items);

        $batch = [];

        foreach ($evening as $step) {
            if ($step === 'recipes') {
                $this->deliver($batch);
                $batch = [];
                $this->writeRecipes($rebuilt, $items, $tikka, $cheeseOption);

                continue;
            }

            $event = $this->crmEvent($rebuilt, $step[0], $step[1]);
            array_push($batch, $event, $event);
        }

        $this->deliver($batch);
        $this->deliver($batch);

        // What actually happened, said once, so the comparison below cannot
        // pass by both being empty.
        $stock = app(StockService::class);

        // 2 + 4 Full at 250 g, 3 + 1 Half at 125 g = 2,000 g of 3,000.
        $this->assertSame(Units::toBase(1, 'kg', 'g'), $stock->onHand($live['outlet'], $items['paneer']));
        // 3 Half with extra cheese at 20 g.
        $this->assertSame(Units::toBase(940, 'g', 'g'), $stock->onHand($live['outlet'], $items['cheese']));
        // 4 sold and 1 returned; the 6 on the cancelled bill went back.
        $this->assertSame(Units::toBase(21, 'pc', 'pc'), $stock->onHand($live['outlet'], $items['cola']));
        $this->assertSame(1, KitchenLine::forOutlet($live['outlet'])->where('wasted', true)->count());
        $this->assertSame([Sale::RECORDED, Sale::CANCELLED, Sale::RECORDED], Sale::forOutlet($live['outlet'])->orderBy('id')->pluck('status')->all());

        // The gate: identical stock, and an identical ledger behind it.
        $this->assertSame($this->levels($live), $this->levels($rebuilt), 'the rebuilt outlet holds different stock');
        $this->assertSame($this->ledger($live), $this->ledger($rebuilt), 'the rebuilt outlet reached its stock a different way');
        $this->assertSame(
            (int) KitchenLine::forOutlet($live['outlet'])->sum('cost_paise'),
            (int) KitchenLine::forOutlet($rebuilt['outlet'])->sum('cost_paise'),
        );

        // And both add up.
        $this->assertSame([], $stock->reconcile($live['outlet']));
        $this->assertSame([], $stock->reconcile($rebuilt['outlet']));

        // Sending the live outlet its whole evening again changes nothing.
        $before = $this->ledger($live);
        $replay = InboxEvent::query()->where('outlet_id', $live['outlet'])->orderBy('id')->get()
            ->map(fn (InboxEvent $event) => $event->toEnvelope()->toArray())->all();

        $this->deliver($replay);

        $this->assertSame($before, $this->ledger($live));
    }

    /* ---------------------------------------------------------- helpers */

    /** The stock an outlet starts the evening with - ERP's own work, not CRM's. */
    private function prepare(array $at, array $items): void
    {
        ['main' => $main, 'kitchen' => $kitchen] = Outlets::stores($at['outlet']);
        $purchasing = app(Purchasing::class);

        $line = fn (StockItem $item, string|int $amount, string $unit, int $rupees, array $extra = []) => [
            'item' => $item, 'qty' => Units::toBase($amount, $unit, $item->base_unit), 'entered_qty' => (string) $amount,
            'unit' => $unit, 'amount_paise' => $rupees * 100, 'batch_no' => null, 'expiry' => null,
        ] + $extra;

        // Two deliveries of paneer at different prices and dates: the
        // batches must leave in date order, each at its own cost.
        $purchasing->receive($at['outlet'], ['store' => $main, 'received_on' => '2026-09-29'], [
            $line($items['paneer'], 1, 'kg', 380, ['batch_no' => 'P-LATER', 'expiry' => '2026-10-12']),
            $line($items['curd'], 5, 'kg', 400),
            $line($items['masala'], 1, 'kg', 600),
            $line($items['cheese'], 1, 'kg', 500),
            $line($items['cola'], 24, 'pc', 672),
        ]);
        $purchasing->receive($at['outlet'], ['store' => $main, 'received_on' => '2026-09-30'], [
            $line($items['paneer'], 2, 'kg', 840, ['batch_no' => 'P-SOONER', 'expiry' => '2026-10-06']),
        ]);

        // Some of it issued to the kitchen; the rest is cooked from the main store.
        app(StoreRoom::class)->issue($at['outlet'], $main, $kitchen, '2026-10-01', [
            ['item' => $items['paneer'], 'qty' => Units::toBase(1500, 'g', 'g')],
            ['item' => $items['cola'], 'qty' => Units::toBase(12, 'pc', 'pc')],
        ]);
    }

    private function writeRecipes(array $at, array $items, array $tikka, string $cheeseOption): void
    {
        $this->recipe($at, $tikka, 'Full', [[$items['paneer'], 250, 'g'], [$items['curd'], 50, 'g'], [$items['masala'], 10, 'g']]);
        $this->recipe($at, $tikka, 'Half', [[$items['paneer'], 125, 'g'], [$items['curd'], 25, 'g'], [$items['masala'], 5, 'g']]);

        $addon = SalesAddon::forOutlet($at['outlet'])->where('ref', $cheeseOption)->sole();

        app(Recipes::class)->save($at['outlet'], ['sales_addon_id' => $addon->id], [[
            'item' => $items['cheese'], 'qty' => Units::toBase(20, 'g', 'g'), 'entered_qty' => '20', 'unit' => 'g',
        ]]);
    }

    /**
     * What an outlet holds, in words that do not depend on row ids:
     * "Kitchen / Paneer / P-SOONER" => [quantity, cost rate].
     *
     * @return array<string, array{0: int, 1: int}>
     */
    private function levels(array $at): array
    {
        $batches = Batch::forOutlet($at['outlet'])->pluck('batch_no', 'id');

        return StockLevel::forOutlet($at['outlet'])->with(['store', 'item'])->get()
            ->filter(fn (StockLevel $level) => $level->qty !== 0)
            ->mapWithKeys(fn (StockLevel $level) => [
                $level->store->name.' / '.$level->item->name.' / '.($batches[$level->batch_id] ?? '-') => [$level->qty, $level->cost_rate],
            ])
            ->sortKeys()
            ->all();
    }

    /**
     * Every movement an outlet has booked, in the order it booked them.
     *
     * @return list<string>
     */
    private function ledger(array $at): array
    {
        $batches = Batch::forOutlet($at['outlet'])->pluck('batch_no', 'id');

        return StockMovement::forOutlet($at['outlet'])->with(['store', 'item'])->orderBy('id')->get()
            ->map(fn (StockMovement $m) => implode(' | ', [
                $m->type, $m->store->name, $m->item->name, $batches[$m->batch_id] ?? '-',
                $m->qty, $m->cost_rate, $m->value_paise, $m->balance_qty, $m->business_date->toDateString(),
            ]))
            ->all();
    }
}
