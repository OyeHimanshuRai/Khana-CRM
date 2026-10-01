<?php

namespace Tests\Feature;

use App\Models\Sale;
use App\Models\SalesItem;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Services\Recipes;
use App\Services\SalesImporter;
use App\Services\StockService;
use App\Stock\Posting;
use App\Support\Outlets;
use App\Support\Units;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Khana\Kit\Events\OutboxEvent;
use Khana\Kit\Support\Ulid;
use Tests\Concerns\HearsFromCrm;
use Tests\Concerns\MintsMachineTokens;
use Tests\TestCase;

/**
 * ERP with no CRM beside it, and what ERP answers when asked.
 *
 * An outlet that bills with something else tells ERP what it sold through
 * the sales API or a CSV import; either way the sale goes through the same
 * door CRM's do. And CRM, when it is there, reads two things: the outlet's
 * open supplier bills and a dish's stock and cost.
 */
class ErpAloneTest extends TestCase
{
    use HearsFromCrm, MintsMachineTokens, RefreshDatabase;

    /** @var array{tenant: string, outlet: string} */
    private array $at;

    private StockService $stock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->trustAccountsKey();

        // ERP only: no CRM anywhere.
        $this->at = $this->business('Dosa Corner', ['erp' => 'active']);
        $this->stock = app(StockService::class);
    }

    private function stocked(StockItem $item, string|int $amount, string $unit): void
    {
        $this->stock->receive(Outlets::stores($this->at['outlet'])['main'], $item, Units::toBase($amount, $unit, $item->base_unit),
            $item->last_cost_rate, new Posting(StockMovement::RECEIPT, businessDate: '2026-10-01'));
    }

    /** @return array<string, mixed> a sale as other billing software posts it */
    private function posted(array $extra = []): array
    {
        return array_replace([
            'outlet_id' => $this->at['outlet'],
            'reference' => 'PETPOOJA-88121',
            'invoice_no' => 'PP/88121',
            'business_date' => '2026-10-01',
            'channel' => 'dine_in',
            'lines' => [
                ['item_ref' => 'D-014', 'variant_ref' => null, 'name' => 'Masala Dosa', 'qty' => '3', 'net_paise' => 36_000, 'tax_paise' => 1_800],
            ],
            'totals' => ['net_paise' => 36_000, 'tax_paise' => 1_800, 'round_off_paise' => 0, 'grand_paise' => 37_800],
        ], $extra);
    }

    private function csv(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'erp-sales-');
        file_put_contents($path, $content);

        return $path;
    }

    /* -------------------------------------------------------- the sales API */

    public function test_other_billing_software_posts_a_sale_and_stock_follows_the_recipe(): void
    {
        $batter = $this->stockItem($this->at['tenant'], 'Dosa Batter', 'g', 60);
        $potato = $this->stockItem($this->at['tenant'], 'Potato', 'g', 30);
        $this->stocked($batter, 10, 'kg');
        $this->stocked($potato, 10, 'kg');

        $headers = $this->bearer(['sales:write'], ['outlet_id' => $this->at['outlet']]);

        // The first sale teaches ERP the dish: it has no recipe, so nothing moves.
        $this->postJson('/api/v1/sales', $this->posted(), $headers)
            ->assertCreated()
            ->assertJsonPath('reference', 'PETPOOJA-88121')
            ->assertJsonPath('status', 'recorded');

        $dosa = SalesItem::allOutlets()->where('source', SalesItem::API)->sole();
        $this->assertSame(['D-014', 'Masala Dosa'], [$dosa->ref, $dosa->name]);
        $this->assertSame(0, StockMovement::allOutlets()->where('type', StockMovement::CONSUMPTION)->count());
        $this->assertSame(['Masala Dosa'], app(Recipes::class)->needsRecipe($this->at['outlet'])->pluck('item.name')->all());

        app(Recipes::class)->save($this->at['outlet'], ['sales_item_id' => $dosa->id], [
            ['item' => $batter, 'qty' => Units::toBase(150, 'g', 'g'), 'entered_qty' => '150', 'unit' => 'g'],
            ['item' => $potato, 'qty' => Units::toBase(100, 'g', 'g'), 'entered_qty' => '100', 'unit' => 'g'],
        ]);

        // The next sale of it uses stock.
        $this->postJson('/api/v1/sales', $this->posted(['reference' => 'PETPOOJA-88122', 'invoice_no' => 'PP/88122']), $headers)
            ->assertCreated();

        $this->assertSame(Units::toBase(9550, 'g', 'g'), $this->stock->onHand($this->at['outlet'], $batter));
        $this->assertSame(Units::toBase(9700, 'g', 'g'), $this->stock->onHand($this->at['outlet'], $potato));

        // An ERP-only outlet has nobody to tell about sold-out or cost.
        $this->assertSame(0, OutboxEvent::query()->count());
    }

    public function test_the_same_sale_posted_twice_is_recorded_once(): void
    {
        $headers = $this->bearer(['sales:write']);

        $first = $this->postJson('/api/v1/sales', $this->posted(), $headers)->assertCreated()->json('sale_id');
        $again = $this->postJson('/api/v1/sales', $this->posted(), $headers)->assertOk()->json('sale_id');

        $this->assertTrue(Ulid::isValid($first));
        $this->assertSame($first, $again);
        $this->assertSame(1, Sale::allOutlets()->count());
    }

    public function test_a_posted_sale_can_be_cancelled_and_gives_its_stock_back(): void
    {
        $batter = $this->stockItem($this->at['tenant'], 'Dosa Batter', 'g', 60);
        $this->stocked($batter, 1, 'kg');
        $headers = $this->bearer(['sales:write']);

        $this->postJson('/api/v1/sales', $this->posted(['reference' => 'A-1']), $headers)->assertCreated();
        $dosa = SalesItem::allOutlets()->sole();
        app(Recipes::class)->save($this->at['outlet'], ['sales_item_id' => $dosa->id], [
            ['item' => $batter, 'qty' => Units::toBase(150, 'g', 'g'), 'entered_qty' => '150', 'unit' => 'g'],
        ]);

        $this->postJson('/api/v1/sales', $this->posted(['reference' => 'A-2']), $headers)->assertCreated();
        $this->assertSame(Units::toBase(550, 'g', 'g'), $this->stock->onHand($this->at['outlet'], $batter));

        $this->postJson('/api/v1/sales/A-2/cancel', ['outlet_id' => $this->at['outlet'], 'reason' => 'Voided at the counter'], $headers)
            ->assertOk()
            ->assertJsonPath('status', 'cancelled');

        $this->assertSame(Units::toBase(1, 'kg', 'g'), $this->stock->onHand($this->at['outlet'], $batter));

        $this->postJson('/api/v1/sales/NOPE/cancel', ['outlet_id' => $this->at['outlet'], 'reason' => 'x'], $headers)->assertNotFound();
    }

    public function test_the_sales_door_is_locked_to_the_right_key(): void
    {
        $this->postJson('/api/v1/sales', $this->posted())->assertUnauthorized();

        // The wrong scope.
        $this->postJson('/api/v1/sales', $this->posted(), $this->bearer(['bills:read']))->assertForbidden();

        // A key nobody at Accounts signed.
        $this->postJson('/api/v1/sales', $this->posted(), $this->bearer(['sales:write'], [], 'stranger-private'))->assertUnauthorized();

        // A key bound to another outlet.
        $other = $this->business('Idli House');
        $this->postJson('/api/v1/sales', $this->posted(), $this->bearer(['sales:write'], ['outlet_id' => $other['outlet']]))->assertForbidden();

        // An outlet that does not have ERP.
        $crmOnly = $this->business('Chai Point', ['crm' => 'active']);
        $this->postJson('/api/v1/sales', $this->posted(['outlet_id' => $crmOnly['outlet']]), $this->bearer(['sales:write']))->assertForbidden();

        // And a sale that is not one.
        $this->postJson('/api/v1/sales', $this->posted(['lines' => []]), $this->bearer(['sales:write']))->assertStatus(422);

        $this->assertSame(0, Sale::allOutlets()->count());
    }

    /* ----------------------------------------------------------- CSV import */

    public function test_a_csv_of_daily_sales_is_imported_and_stock_follows(): void
    {
        $batter = $this->stockItem($this->at['tenant'], 'Dosa Batter', 'g', 60);
        $this->stocked($batter, 10, 'kg');

        $importer = app(SalesImporter::class);

        $file = $importer->read($this->csv(
            "\xEF\xBB\xBFBill Date,Item Name,Qty,Net Amount,GST\n".
            "01/10/2026,Masala Dosa,12,1440.00,72.00\n".
            "01/10/2026,Filter Coffee,20,600.00,30.00\n".
            "\n".
            "02/10/2026,Masala Dosa,9,1080.00,54.00\n"
        ));

        $this->assertSame(['Bill Date', 'Item Name', 'Qty', 'Net Amount', 'GST'], $file['headers']);

        // The headings are enough for ERP to guess which column is which.
        $layout = $importer->layoutFor($this->at['outlet'], $file['headers']);
        $this->assertSame(['date' => 0, 'item' => 1, 'qty' => 2, 'amount' => 3, 'tax' => 4, 'size' => null, 'invoice' => null], $layout);

        $names = $importer->names($this->at['outlet'], $file['rows'], $layout);
        $this->assertSame([['masala dosa', 2, null], ['filter coffee', 1, null]], array_map(fn ($n) => [$n['alias'], $n['rows'], $n['sales_item_id']], $names));

        $import = $importer->import($this->at['outlet'], 'sales.csv', $file['headers'], $file['rows'], $layout);

        // No bill numbers in the file: one sale a day.
        $this->assertSame([3, 2, 0], [$import->rows, $import->sales, $import->skipped]);
        $this->assertSame(['2026-10-01' => 214_200, '2026-10-02' => 113_400],
            Sale::allOutlets()->orderBy('business_date')->get()->mapWithKeys(fn (Sale $s) => [$s->business_date->toDateString() => $s->grand_paise])->all());

        // Both names became sales items, and wait for a recipe.
        $this->assertSame(['Filter Coffee', 'Masala Dosa'], app(Recipes::class)->needsRecipe($this->at['outlet'])->pluck('item.name')->all());

        // With a recipe, the next day's file uses stock.
        $dosa = SalesItem::allOutlets()->where('name', 'Masala Dosa')->sole();
        app(Recipes::class)->save($this->at['outlet'], ['sales_item_id' => $dosa->id], [
            ['item' => $batter, 'qty' => Units::toBase(150, 'g', 'g'), 'entered_qty' => '150', 'unit' => 'g'],
        ]);

        $next = $importer->read($this->csv("Bill Date,Item Name,Qty,Net Amount,GST\n03/10/2026,MASALA DOSA,10,1200,60\n"));
        $importer->import($this->at['outlet'], 'sales-3.csv', $next['headers'], $next['rows'], $importer->layoutFor($this->at['outlet'], $next['headers']));

        $this->assertSame(Units::toBase(8500, 'g', 'g'), $this->stock->onHand($this->at['outlet'], $batter));
        $this->assertSame(1, SalesItem::allOutlets()->where('name', 'Masala Dosa')->count(), 'the name was remembered, not made again');
    }

    public function test_importing_the_same_file_again_records_nothing_twice(): void
    {
        $importer = app(SalesImporter::class);
        $path = $this->csv("Date,Bill No,Item,Size,Qty,Amount\n2026-10-01,B-1,Dosa,Full,2,240\n2026-10-01,B-1,Coffee,,1,30\n2026-10-01,B-2,Dosa,Half,1,70\n");

        $file = $importer->read($path);
        $layout = $importer->layoutFor($this->at['outlet'], $file['headers']);

        $first = $importer->import($this->at['outlet'], 'a.csv', $file['headers'], $file['rows'], $layout);
        $second = $importer->import($this->at['outlet'], 'a.csv', $file['headers'], $file['rows'], $layout);

        // Bill numbers in the file: one sale a bill.
        $this->assertSame([2, 0], [$first->sales, $first->skipped]);
        $this->assertSame([0, 2], [$second->sales, $second->skipped]);
        $this->assertSame(2, Sale::allOutlets()->count());
        $this->assertSame(['coffee', 'dosa|full', 'dosa|half'], \Illuminate\Support\Facades\DB::table('sales_item_aliases')->orderBy('alias')->pluck('alias')->all());
    }

    public function test_a_name_in_the_file_can_be_matched_to_a_dish_erp_already_has(): void
    {
        $batter = $this->stockItem($this->at['tenant'], 'Dosa Batter', 'g', 60);
        $this->stocked($batter, 1, 'kg');

        $importer = app(SalesImporter::class);
        $first = $importer->read($this->csv("Date,Item,Qty\n2026-10-01,Masala Dosa,1\n"));
        $importer->import($this->at['outlet'], 'a.csv', $first['headers'], $first['rows'], $importer->layoutFor($this->at['outlet'], $first['headers']));

        $dosa = SalesItem::allOutlets()->sole();
        app(Recipes::class)->save($this->at['outlet'], ['sales_item_id' => $dosa->id], [
            ['item' => $batter, 'qty' => Units::toBase(150, 'g', 'g'), 'entered_qty' => '150', 'unit' => 'g'],
        ]);

        // The till was reprogrammed: the same dish under a new name.
        $second = $importer->read($this->csv("Date,Item,Qty\n2026-10-02,M.Dosa (Spl),2\n"));
        $layout = $importer->layoutFor($this->at['outlet'], $second['headers']);

        $importer->import($this->at['outlet'], 'b.csv', $second['headers'], $second['rows'], $layout, [
            'm.dosa (spl)' => ['sales_item_id' => $dosa->id],
        ]);

        $this->assertSame(1, SalesItem::allOutlets()->count());
        $this->assertSame(Units::toBase(700, 'g', 'g'), $this->stock->onHand($this->at['outlet'], $batter));

        // And the match is remembered for next time.
        $this->assertSame($dosa->id, $importer->names($this->at['outlet'], $second['rows'], $layout)[0]['sales_item_id']);
    }

    public function test_a_file_erp_cannot_read_says_which_row_is_wrong(): void
    {
        $importer = app(SalesImporter::class);
        $file = $importer->read($this->csv("Date,Item,Qty\n2026-10-01,Dosa,2\nyesterday,Dosa,1\n"));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Row 3');

        $importer->import($this->at['outlet'], 'bad.csv', $file['headers'], $file['rows'], $importer->layoutFor($this->at['outlet'], $file['headers']));
    }

    /* ------------------------------------------------------ what CRM reads */

    public function test_crm_reads_an_outlets_open_supplier_bills(): void
    {
        $suite = $this->business('Spice Garden', ['erp' => 'active', 'crm' => 'active']);
        $dairy = Supplier::allTenants()->create(['tenant_id' => $suite['tenant'], 'name' => 'Amul Dairy']);

        $later = SupplierBill::allOutlets()->create(['outlet_id' => $suite['outlet'], 'supplier_id' => $dairy->id, 'bill_no' => 'AD/2', 'billed_on' => '2026-10-01', 'due_on' => '2026-10-20', 'total_paise' => 80_000]);
        $sooner = SupplierBill::allOutlets()->create(['outlet_id' => $suite['outlet'], 'supplier_id' => $dairy->id, 'bill_no' => 'AD/1', 'billed_on' => '2026-09-25', 'due_on' => '2026-10-05', 'total_paise' => 50_000, 'paid_paise' => 20_000]);
        SupplierBill::allOutlets()->create(['outlet_id' => $suite['outlet'], 'supplier_id' => $dairy->id, 'bill_no' => 'AD/0', 'billed_on' => '2026-09-01', 'total_paise' => 10_000, 'paid_paise' => 10_000, 'status' => SupplierBill::PAID]);

        $response = $this->getJson("/api/v1/outlets/{$suite['outlet']}/supplier-bills", $this->bearer(['bills:read']))->assertOk();

        $this->assertSame([
            ['bill_id' => $sooner->ulid, 'supplier_name' => 'Amul Dairy', 'bill_no' => 'AD/1', 'billed_on' => '2026-09-25', 'due_on' => '2026-10-05', 'total_paise' => 50_000, 'due_paise' => 30_000],
            ['bill_id' => $later->ulid, 'supplier_name' => 'Amul Dairy', 'bill_no' => 'AD/2', 'billed_on' => '2026-10-01', 'due_on' => '2026-10-20', 'total_paise' => 80_000, 'due_paise' => 80_000],
        ], $response->json('bills'));

        $this->getJson("/api/v1/outlets/{$suite['outlet']}/supplier-bills?status=all", $this->bearer(['bills:read']))->assertJsonCount(3, 'bills');
        $this->getJson("/api/v1/outlets/{$suite['outlet']}/supplier-bills", $this->bearer(['stock:read']))->assertForbidden();

        // Another outlet's bills are not this outlet's.
        $this->getJson("/api/v1/outlets/{$this->at['outlet']}/supplier-bills", $this->bearer(['bills:read']))->assertOk()->assertJsonCount(0, 'bills');
    }

    public function test_crm_reads_a_dishs_stock_and_cost(): void
    {
        $suite = $this->business('Spice Garden', ['erp' => 'active', 'crm' => 'active']);
        $paneer = $this->stockItem($suite['tenant'], 'Paneer', 'g', 400);
        $this->stock->receive(Outlets::stores($suite['outlet'])['kitchen'], $paneer, Units::toBase(700, 'g', 'g'), $paneer->last_cost_rate,
            new Posting(StockMovement::RECEIPT, businessDate: '2026-10-01'));

        $tikka = $this->menuItem('Paneer Tikka', 320, [['Full', 320], ['Half', 180]]);
        $this->fromCrm($suite, 'crm.menu.item_upserted', $tikka);

        $url = "/api/v1/outlets/{$suite['outlet']}/items/{$tikka['item_id']}/stock";

        // No recipe yet: ERP has no opinion.
        $this->getJson($url, $this->bearer(['stock:read']))
            ->assertOk()
            ->assertJsonPath('has_recipe', false)
            ->assertJsonPath('sizes.0.portions_available', null)
            ->assertJsonPath('sizes.0.available', true);

        $this->recipe($suite, $tikka, 'Full', [[$paneer, 250, 'g']]);
        $this->recipe($suite, $tikka, 'Half', [[$paneer, 125, 'g']]);

        $this->getJson($url, $this->bearer(['stock:read']))
            ->assertOk()
            ->assertExactJson([
                'item_id' => $tikka['item_id'],
                'has_recipe' => true,
                'sizes' => [
                    // 700 g covers 2.8 Full portions at Rs 100 each: 31.25% of Rs 320.
                    ['variant_id' => $this->variant($tikka, 'Full'), 'portions_available' => '2.8000', 'cost_paise' => 10_000, 'food_cost_percent' => '31.25', 'available' => true],
                    ['variant_id' => $this->variant($tikka, 'Half'), 'portions_available' => '5.6000', 'cost_paise' => 5_000, 'food_cost_percent' => '27.78', 'available' => true],
                ],
            ]);

        $this->getJson("/api/v1/outlets/{$suite['outlet']}/items/".Ulid::generate().'/stock', $this->bearer(['stock:read']))->assertNotFound();
    }

    public function test_accounts_reads_erps_roles(): void
    {
        $this->getJson('/api/v1/roles?tenant_id='.$this->at['tenant'], $this->bearer(['roles:read']))
            ->assertOk()
            ->assertJsonPath('product', 'erp')
            ->assertJsonPath('roles.0', ['key' => 'owner', 'name' => 'Owner', 'preset' => true])
            ->assertJsonCount(5, 'roles');
    }
}
