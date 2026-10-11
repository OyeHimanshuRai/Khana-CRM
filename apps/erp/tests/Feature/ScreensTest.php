<?php

namespace Tests\Feature;

use App\Models\GoodsReceipt;
use App\Models\PaidOut;
use App\Models\Recipe;
use App\Models\Sale;
use App\Models\SalesItem;
use App\Models\StockDocument;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Services\StockService;
use App\Stock\Posting;
use App\Support\Outlets;
use App\Support\Units;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Khana\Kit\Support\Ulid;
use Tests\Concerns\HearsFromCrm;
use Tests\TestCase;

/**
 * ERP through its screens: every page opens, and each of the jobs a person
 * comes here to do can be done from the form it is done on.
 *
 * The services have their own tests. What is held here is the wiring - a
 * form's field names, a controller's validation, a route's permission - the
 * part that breaks without any service being wrong.
 */
class ScreensTest extends TestCase
{
    use HearsFromCrm, RefreshDatabase;

    /** @var array{tenant: string, outlet: string} */
    private array $at;

    private StockItem $paneer;

    private StockItem $curd;

    private Supplier $dairy;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');

        $this->at = $this->business('Spice Garden', ['erp' => 'active', 'crm' => 'active']);
        $this->paneer = $this->stockItem($this->at['tenant'], 'Paneer', 'g', 400, ['gst_rate_bp' => 500, 'reorder_qty' => Units::toBase(5, 'kg', 'g')]);
        $this->curd = $this->stockItem($this->at['tenant'], 'Curd', 'g', 80, ['track_expiry' => true]);
        $this->dairy = Supplier::allTenants()->create(['tenant_id' => $this->at['tenant'], 'name' => 'Amul Dairy', 'terms_days' => 7]);
    }

    private function onHand(StockItem $item): string
    {
        return Units::plain(app(StockService::class)->onHand($this->at['outlet'], $item));
    }

    private function stocked(StockItem $item, string|int $amount, string $unit): void
    {
        app(StockService::class)->receive(Outlets::stores($this->at['outlet'])['main'], $item, Units::toBase($amount, $unit, $item->base_unit),
            $item->last_cost_rate, new Posting(StockMovement::RECEIPT, businessDate: '2026-10-01'));
    }

    /* ------------------------------------------------- every page opens */

    public function test_every_screen_in_the_sidebar_opens_for_the_owner(): void
    {
        $this->signInAs('owner', $this->at);
        $this->stocked($this->paneer, 2, 'kg');

        $visited = 0;

        foreach (config('menu') as $group) {
            foreach ($group['items'] as $hub) {
                foreach ($hub['tabs'] ?? [$hub] as $tab) {
                    $this->assertTrue(Route::has($tab['route']), "The menu names a route that does not exist: {$tab['route']}");

                    $this->get(route($tab['route'], $tab['params'] ?? []))->assertOk();
                    $visited++;
                }
            }
        }

        $this->assertGreaterThan(20, $visited);

        // And the forms behind the buttons.
        foreach (['items.create', 'receipts.create', 'orders.create', 'issues.create', 'wastage.create', 'production.create', 'counts.create', 'sales.import'] as $form) {
            $this->get(route($form))->assertOk();
        }
    }

    public function test_the_home_screen_says_what_needs_doing(): void
    {
        $this->signInAs('owner', $this->at);
        $this->stocked($this->paneer, 2, 'kg');

        $this->fromCrm($this->at, 'crm.menu.item_upserted', $this->menuItem('Paneer Tikka', 320));
        SupplierBill::allOutlets()->create(['outlet_id' => $this->at['outlet'], 'supplier_id' => $this->dairy->id, 'bill_no' => 'AD/1', 'billed_on' => '2026-01-01', 'due_on' => '2026-01-08', 'total_paise' => 50_000]);

        $this->get('/')
            ->assertOk()
            ->assertSee('Running low')
            ->assertSee('Dishes with no recipe')
            ->assertSee('Supplier bills overdue')
            ->assertSee('Receive goods')
            ->assertSee('Log wastage');
    }

    public function test_a_storekeeper_sees_the_store_room_and_not_the_books(): void
    {
        $this->signInAs('storekeeper', $this->at);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('data-label="Stock"', $html);
        $this->assertStringContainsString('data-label="Kitchen"', $html);
        $this->assertStringNotContainsString('data-label="Bills"', $html);
        $this->assertStringNotContainsString('data-label="Settings"', $html);

        $this->get(route('bills.index'))->assertForbidden();
        $this->get(route('reports.show', 'food-cost'))->assertForbidden();
        $this->get(route('receipts.create'))->assertOk();
    }

    /* ------------------------------------------------------------ items */

    public function test_a_stock_item_is_added_with_its_packs(): void
    {
        $this->signInAs('store_manager', $this->at);

        $this->post(route('items.store'), [
            'name' => 'Refined Oil', 'category' => 'Dry goods', 'base_unit' => 'ml', 'reorder' => '10',
            'gst_rate' => '5', 'is_active' => 1, 'track_expiry' => 0, 'is_prepared' => 0,
            'packs' => [['name' => '15 litre tin', 'size' => '15'], ['name' => '', 'size' => '']],
        ])->assertRedirect(route('items.index'));

        $oil = StockItem::allTenants()->where('name', 'Refined Oil')->sole();
        $this->assertSame(500, $oil->gst_rate_bp);
        $this->assertSame(Units::toBase(10, 'l', 'ml'), $oil->reorder_qty);
        $this->assertSame([Units::toBase(15, 'l', 'ml')], $oil->packs->pluck('qty')->all());

        $this->get(route('items.edit', $oil->id))->assertOk()->assertSee('15 litre tin');
    }

    /* -------------------------------------------------------- purchases */

    public function test_a_delivery_is_received_from_the_form(): void
    {
        $this->signInAs('storekeeper', $this->at);
        $main = Outlets::stores($this->at['outlet'])['main'];

        $this->post(route('receipts.store'), [
            'supplier_id' => $this->dairy->id,
            'store_id' => $main->id,
            'received_on' => '2026-10-01',
            'bill_no' => 'AD/118',
            'lines' => [
                ['item' => $this->paneer->id, 'qty' => '10', 'unit' => 'kg', 'price' => '400'],
                ['item' => $this->curd->id, 'qty' => '5', 'unit' => 'kg', 'price' => '80', 'batch_no' => 'CRD-1', 'expiry' => '2026-10-06'],
                ['item' => '', 'qty' => '', 'unit' => ''],
            ],
        ])->assertRedirect();

        $receipt = GoodsReceipt::allOutlets()->sole();
        $this->assertSame(440_000 + 20_000, $receipt->total_paise, 'Rs 4,000 + Rs 400, plus 5% on the paneer');
        $this->assertSame('10000', $this->onHand($this->paneer));
        $this->assertSame('5000', $this->onHand($this->curd));

        $this->get(route('receipts.show', $receipt->id))->assertOk()->assertSee('CRD-1')->assertSee('Amul Dairy');
        $this->get(route('bills.index'))->assertForbidden();
    }

    public function test_a_wrong_row_is_named_and_nothing_is_received(): void
    {
        $this->signInAs('storekeeper', $this->at);

        $this->post(route('receipts.store'), [
            'store_id' => Outlets::stores($this->at['outlet'])['main']->id,
            'received_on' => '2026-10-01',
            'lines' => [['item' => $this->paneer->id, 'qty' => '10', 'unit' => 'l']],
        ])->assertSessionHasErrors('lines.0.qty');

        $this->post(route('receipts.store'), [
            'store_id' => Outlets::stores($this->at['outlet'])['main']->id,
            'received_on' => '2026-10-01',
            'lines' => [['item' => '', 'qty' => '']],
        ])->assertSessionHasErrors('lines');

        $this->assertSame(0, GoodsReceipt::allOutlets()->count());
    }

    public function test_an_order_is_written_received_against_and_paid(): void
    {
        $this->signInAs('owner', $this->at);

        $this->post(route('orders.store'), [
            'supplier_id' => $this->dairy->id,
            'ordered_on' => '2026-10-01',
            'lines' => [['item' => $this->paneer->id, 'qty' => '10', 'unit' => 'kg', 'price' => '400']],
        ])->assertRedirect();

        $order = \App\Models\PurchaseOrder::allOutlets()->sole();

        // The receipt form arrives filled from the order.
        $this->get(route('receipts.create', ['order' => $order->id]))
            ->assertOk()
            ->assertSee('Against order '.$order->number)
            ->assertSee('value="10"', false)
            ->assertSee('value="400"', false);

        $this->post(route('receipts.store'), [
            'supplier_id' => $this->dairy->id,
            'order_id' => $order->id,
            'store_id' => Outlets::stores($this->at['outlet'])['main']->id,
            'received_on' => '2026-10-02',
            'lines' => [['item' => $this->paneer->id, 'qty' => '10', 'unit' => 'kg', 'price' => '400']],
        ])->assertRedirect();

        $this->assertSame('received', $order->fresh()->status);

        $bill = SupplierBill::allOutlets()->sole();
        $this->get(route('bills.index'))->assertOk()->assertSee('Amul Dairy')->assertSee('4,200.00');

        $this->post(route('payments.store'), [
            'supplier_id' => $this->dairy->id, 'supplier_bill_id' => $bill->id, 'amount' => '4200',
            'method' => 'upi', 'paid_on' => '2026-10-03',
        ])->assertRedirect()->assertSessionHas('status');

        $this->assertSame(SupplierBill::PAID, $bill->fresh()->status);
        $this->get(route('suppliers.show', $this->dairy->id))->assertOk()->assertSee('You owe')->assertSee('Paid');
    }

    /* ------------------------------------------------------- store room */

    public function test_stock_is_issued_wasted_and_counted_from_the_forms(): void
    {
        $this->signInAs('storekeeper', $this->at);
        $this->stocked($this->paneer, 10, 'kg');
        ['main' => $main, 'kitchen' => $kitchen] = Outlets::stores($this->at['outlet']);

        $this->post(route('issues.store'), [
            'from_store_id' => $main->id, 'to_store_id' => $kitchen->id, 'on_date' => '2026-10-01',
            'lines' => [['item' => $this->paneer->id, 'qty' => '3', 'unit' => 'kg']],
        ])->assertRedirect(route('issues.index'));

        $this->post(route('wastage.store'), [
            'store_id' => $kitchen->id, 'on_date' => '2026-10-01', 'reason' => 'Spoiled',
            'lines' => [['item' => $this->paneer->id, 'qty' => '500', 'unit' => 'g', 'note' => 'Left out overnight']],
        ])->assertRedirect(route('wastage.index'));

        $this->assertSame(Units::toBase(2500, 'g', 'g'), app(StockService::class)->onHand($this->at['outlet'], $this->paneer, $kitchen));

        // The count form lists what the books say the kitchen holds.
        $this->get(route('counts.create', ['store' => $kitchen->id]))->assertOk()->assertSee('Paneer')->assertSee('2.5 kg');

        $this->post(route('counts.store'), [
            'store_id' => $kitchen->id, 'on_date' => '2026-10-01',
            'rows' => [['item' => $this->paneer->id, 'batch_id' => 0, 'qty' => '2.2']],
        ])->assertRedirect();

        $count = StockDocument::allOutlets()->where('kind', StockDocument::COUNT)->sole();
        $this->assertSame(-12_000, $count->value_paise, '300 g short at Rs 400 a kilo');
        $this->get(route('counts.show', $count->id))->assertOk()->assertSee('Paneer');

        // A count with nothing typed is refused, not posted as "all zero".
        $this->post(route('counts.store'), [
            'store_id' => $kitchen->id, 'on_date' => '2026-10-01',
            'rows' => [['item' => $this->paneer->id, 'batch_id' => 0, 'qty' => '']],
        ])->assertSessionHasErrors('rows');

        $this->get(route('issues.index'))->assertOk()->assertSee('ISS-0001');
        $this->get(route('wastage.index'))->assertOk()->assertSee('WST-0001');
    }

    public function test_opening_stock_is_entered_as_a_count_with_a_cost(): void
    {
        $this->signInAs('owner', $this->at);
        $rice = $this->stockItem($this->at['tenant'], 'Rice', 'g');
        $main = Outlets::stores($this->at['outlet'])['main'];

        // The store is empty in the books; "list every item" shows it all.
        $this->get(route('counts.create', ['store' => $main->id, 'all' => 1]))->assertOk()->assertSee('Rice');

        $this->post(route('counts.store'), [
            'store_id' => $main->id, 'on_date' => '2026-10-01', 'opening' => 1,
            'rows' => [['item' => $rice->id, 'batch_id' => 0, 'qty' => '40', 'price' => '95']],
        ])->assertRedirect();

        $this->assertSame(StockMovement::OPENING, StockMovement::allOutlets()->sole()->type);
        $this->assertSame(380_000, StockMovement::allOutlets()->sole()->value_paise);
    }

    /* ---------------------------------------------------------- recipes */

    public function test_a_recipe_is_written_from_the_needs_recipe_list(): void
    {
        $this->signInAs('store_manager', $this->at);
        $this->stocked($this->paneer, 1, 'kg');

        $tikka = $this->menuItem('Paneer Tikka', 320, [['Full', 320], ['Half', 180]]);
        $this->fromCrm($this->at, 'crm.menu.item_upserted', $tikka);

        $dish = SalesItem::allOutlets()->sole();
        $full = $dish->sizes()->where('name', 'Full')->sole();

        $this->get(route('recipes.index'))->assertOk()->assertSee('Needs a recipe')->assertSee('Paneer Tikka');
        $this->get(route('recipes.edit', ['item' => $dish->id, 'size' => $full->id]))->assertOk()->assertSee('One portion uses');

        $this->put(route('recipes.update', ['item' => $dish->id, 'size' => $full->id]), [
            'lines' => [
                ['item' => $this->paneer->id, 'qty' => '250', 'unit' => 'g'],
                ['item' => $this->curd->id, 'qty' => '50', 'unit' => 'g', 'optional' => 1],
            ],
        ])->assertRedirect(route('recipes.index'));

        $recipe = Recipe::allOutlets()->sole();
        $this->assertSame([[$this->paneer->id, false], [$this->curd->id, true]], $recipe->lines->map(fn ($l) => [$l->stock_item_id, $l->is_optional])->all());

        // Rs 100 of paneer and Rs 4 of curd on a Rs 320 dish.
        $this->get(route('recipes.index'))->assertOk()->assertSee('104.00')->assertSee('32.50%')->assertSee('4 portions');

        $this->delete(route('recipes.destroy', ['item' => $dish->id, 'size' => $full->id]))->assertRedirect(route('recipes.index'));
        $this->assertSame(0, Recipe::allOutlets()->count());
    }

    public function test_a_prepared_item_gets_a_recipe_and_a_batch_is_made(): void
    {
        $this->signInAs('store_manager', $this->at);
        $gravy = $this->stockItem($this->at['tenant'], 'Makhani Gravy', 'ml', 0, ['is_prepared' => true]);
        $tomato = $this->stockItem($this->at['tenant'], 'Tomato', 'g', 40);
        $this->stocked($tomato, 20, 'kg');
        $kitchen = Outlets::stores($this->at['outlet'])['kitchen'];

        $this->get(route('prepared.index'))->assertOk()->assertSee('Makhani Gravy')->assertSee('No recipe');

        $this->put(route('prepared.update', $gravy->id), [
            'yield' => '5',
            'lines' => [['item' => $tomato->id, 'qty' => '4', 'unit' => 'kg']],
        ])->assertRedirect(route('prepared.index'));

        $this->post(route('production.store'), [
            'stock_item_id' => $gravy->id, 'store_id' => Outlets::stores($this->at['outlet'])['main']->id,
            'qty' => '10', 'unit' => 'l', 'on_date' => '2026-10-01',
        ])->assertRedirect(route('production.index'));

        $this->assertSame('10000', $this->onHand($gravy));
        $this->assertSame('12000', $this->onHand($tomato));
        $this->get(route('production.index'))->assertOk()->assertSee('10 l of Makhani Gravy');
        $this->assertNotNull($kitchen);
    }

    /* ------------------------------------------------------------ sales */

    public function test_a_sales_file_is_imported_through_the_two_screens(): void
    {
        Storage::fake('local');
        $this->signInAs('store_manager', $this->at);

        $csv = "Date,Item,Qty,Amount\n2026-10-01,Masala Dosa,12,1440\n2026-10-01,Filter Coffee,20,600\n";

        $response = $this->post(route('sales.import.upload'), ['file' => UploadedFile::fake()->createWithContent('october.csv', $csv)])
            ->assertRedirect();

        $token = basename(parse_url($response->headers->get('Location'), PHP_URL_PATH));

        $this->get(route('sales.import.match', $token))
            ->assertOk()
            ->assertSee('october.csv')
            ->assertSee('Masala Dosa')
            ->assertSee('A new dish');

        $this->post(route('sales.import.store', $token), [
            'layout' => ['date' => 0, 'item' => 1, 'qty' => 2, 'amount' => 3, 'tax' => '', 'size' => '', 'invoice' => ''],
            'match' => [['alias' => 'masala dosa', 'choice' => ''], ['alias' => 'filter coffee', 'choice' => '']],
        ])->assertRedirect(route('sales.index'))->assertSessionHas('status', fn (string $m) => str_contains($m, '1 sale imported'));

        $sale = Sale::allOutlets()->sole();
        $this->assertSame(204_000, $sale->grand_paise);
        $this->assertSame(2, SalesItem::allOutlets()->where('source', SalesItem::CSV)->count());

        $this->get(route('sales.index'))->assertOk()->assertSee('Day 2026-10-01');
        $this->get(route('sales.show', $sale->id))->assertOk()->assertSee('Masala Dosa')->assertSee('No recipe');

        // The file is gone once imported, and the token with it.
        $this->get(route('sales.import.match', $token))->assertNotFound();
    }

    public function test_somebody_elses_upload_cannot_be_finished(): void
    {
        Storage::fake('local');
        $this->signInAs('store_manager', $this->at);

        $response = $this->post(route('sales.import.upload'), ['file' => UploadedFile::fake()->createWithContent('a.csv', "Date,Item,Qty\n2026-10-01,Dosa,1\n")]);
        $token = basename(parse_url($response->headers->get('Location'), PHP_URL_PATH));

        // Another person, another session.
        $this->flushSession();
        $this->signInAs('owner', $this->business('Rival Dhaba'));

        $this->get(route('sales.import.match', $token))->assertNotFound();
    }

    /* ------------------------------------------------------------ bills */

    public function test_an_expense_is_added_and_a_paid_out_is_filed(): void
    {
        $this->signInAs('accountant', $this->at);

        $this->post(route('expenses.store'), [
            'category' => 'gas', 'amount' => '1,850.50', 'spent_on' => '2026-10-01', 'method' => 'cash', 'note' => 'Two cylinders',
        ])->assertRedirect(route('expenses.index'));

        $this->get(route('expenses.index', ['month' => '2026-10']))->assertOk()->assertSee('1,850.50')->assertSee('Two cylinders');

        $paidOut = PaidOut::allOutlets()->create([
            'ulid' => Ulid::generate(), 'outlet_id' => $this->at['outlet'], 'business_date' => '2026-10-01',
            'amount_paise' => 30_000, 'category' => 'supplier', 'note' => 'Vegetable vendor',
        ]);

        $this->get(route('paid-outs.index'))->assertOk()->assertSee('Vegetable vendor');

        $this->put(route('paid-outs.file', $paidOut->id), ['as' => 'payment', 'supplier_id' => $this->dairy->id])
            ->assertRedirect(route('paid-outs.index'));

        $this->assertSame(PaidOut::FILED, $paidOut->fresh()->status);
        // Nothing was owed, so the cash sits as an advance with the dairy.
        $this->get(route('suppliers.show', $this->dairy->id))->assertOk()->assertSee('Paid in advance');

        // Filing it twice is refused.
        $this->put(route('paid-outs.file', $paidOut->id), ['as' => 'expense', 'category' => 'gas'])->assertSessionHas('error');
    }

    /* ---------------------------------------------------------- reports */

    public function test_the_reports_add_up(): void
    {
        $this->signInAs('owner', $this->at);
        $this->stocked($this->paneer, 2, 'kg');

        $tikka = $this->menuItem('Paneer Tikka', 320);
        $this->fromCrm($this->at, 'crm.menu.item_upserted', $tikka);
        $this->recipe($this->at, $tikka, null, [[$this->paneer, 250, 'g']]);

        $line = $this->kitchenLine($tikka, null, 2);
        $this->fromCrm($this->at, 'crm.kitchen.line_advanced', $line);
        $this->fromCrm($this->at, 'crm.sale.recorded', $this->saleOf([[$tikka, null, 2, 640, [$line['line_id']]]]));

        // Two tikkas for Rs 640 used Rs 200 of paneer: 31.25%.
        $this->get(route('reports.show', ['report' => 'food-cost', 'from' => '2026-10-01', 'to' => '2026-10-31']))
            ->assertOk()->assertSee('640.00')->assertSee('200.00')->assertSee('31.25%');

        $this->get(route('reports.show', ['report' => 'stock-value']))->assertOk()->assertSee('600.00');
        $this->get(route('reports.show', ['report' => 'variance', 'from' => '2026-10-01', 'to' => '2026-10-31']))->assertOk()->assertSee('500 g');
    }

    /* --------------------------------------------------------- settings */

    public function test_settings_are_four_switches(): void
    {
        $this->signInAs('owner', $this->at);

        $this->get(route('settings.edit'))->assertOk()->assertSee('A dish uses its ingredients');

        $this->put(route('settings.update'), [
            'consume_stage' => 'served', 'exclude_expired' => 0, 'input_tax_is_cost' => 1, 'kitchen_only' => 1,
        ])->assertRedirect(route('settings.edit'));

        $settings = Outlets::settings($this->at['outlet']);
        $this->assertSame(['served', false, true, true], [$settings->consume_stage, $settings->exclude_expired, $settings->input_tax_is_cost, $settings->kitchen_only]);
    }

    /* ------------------------------------------------------- isolation */

    public function test_another_business_stock_cannot_be_reached_by_typing_its_address(): void
    {
        $rival = $this->business('Rival Dhaba');
        $secret = $this->stockItem($rival['tenant'], 'Secret Spice', 'g', 900);

        $this->signInAs('owner', $this->at);

        $this->get(route('stock.show', $secret->id))->assertNotFound();
        $this->get(route('items.edit', $secret->id))->assertNotFound();
        $this->get(route('items.index'))->assertOk()->assertDontSee('Secret Spice');

        // Nor received into this outlet.
        $this->post(route('receipts.store'), [
            'store_id' => Outlets::stores($this->at['outlet'])['main']->id, 'received_on' => '2026-10-01',
            'lines' => [['item' => $secret->id, 'qty' => '1', 'unit' => 'kg', 'price' => '900']],
        ])->assertSessionHasErrors('lines.0.item');
    }
}
