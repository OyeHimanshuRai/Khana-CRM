<?php

namespace Tests\Feature;

use App\Models\StockItem;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Services\Purchasing;
use App\Services\StockService;
use App\Services\StoreRoom;
use App\Stock\Posting;
use App\Support\Csv;
use App\Support\Outlets;
use App\Support\Units;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\HearsFromCrm;
use Tests\TestCase;

/**
 * Every report can be taken away as a CSV: the figures the screen shows, as
 * one plain table a spreadsheet can add up.
 */
class ReportExportTest extends TestCase
{
    use HearsFromCrm, RefreshDatabase;

    /** @var array{tenant: string, outlet: string} */
    private array $at;

    private StockItem $paneer;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');

        $this->at = $this->business('Spice Garden', ['erp' => 'active', 'crm' => 'active']);
        $this->paneer = $this->stockItem($this->at['tenant'], 'Paneer', 'g', 400, ['category' => 'Dairy', 'gst_rate_bp' => 500]);
    }

    /**
     * The file, as rows of cells.
     *
     * @return list<list<string>>
     */
    private function rows(TestResponse $response): array
    {
        $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $body = $response->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $body, 'Without the byte-order mark Excel garbles anything not English.');

        $lines = preg_split('/\r?\n/', trim(substr($body, 3)));

        return array_map(fn (string $line) => str_getcsv($line, ',', '"', ''), $lines);
    }

    private function export(string $report, array $query = []): TestResponse
    {
        return $this->get(route('reports.export', ['report' => $report] + $query));
    }

    private function stocked(StockItem $item, string|int $amount, string $unit): void
    {
        app(StockService::class)->receive(Outlets::stores($this->at['outlet'])['main'], $item, Units::toBase($amount, $unit, $item->base_unit),
            $item->last_cost_rate, new Posting(StockMovement::RECEIPT, businessDate: '2026-10-01'));
    }

    public function test_food_cost_comes_out_dish_by_dish_as_plain_numbers(): void
    {
        $this->signInAs('owner', $this->at);
        $this->stocked($this->paneer, 2, 'kg');

        $tikka = $this->menuItem('Paneer Tikka', 320);
        $dal = $this->menuItem('Dal Makhani', 240);
        $this->fromCrm($this->at, 'crm.menu.item_upserted', $tikka);
        $this->fromCrm($this->at, 'crm.menu.item_upserted', $dal);
        $this->recipe($this->at, $tikka, null, [[$this->paneer, 250, 'g']]);

        $line = $this->kitchenLine($tikka, null, 2);
        $this->fromCrm($this->at, 'crm.kitchen.line_advanced', $line);
        $this->fromCrm($this->at, 'crm.sale.recorded', $this->saleOf([[$tikka, null, 2, 640, [$line['line_id']]], [$dal, null, 1, 240, []]]));

        $response = $this->export('food-cost', ['from' => '2026-10-01', 'to' => '2026-10-31']);
        $rows = $this->rows($response);

        $this->assertStringContainsString('food-cost-', $response->headers->get('content-disposition'));
        $this->assertStringContainsString('2026-10-01-to-2026-10-31.csv', $response->headers->get('content-disposition'));

        $this->assertSame(['Dish', 'Portions sold', 'Sold for (Rs)', 'Food cost (Rs)', 'Food cost %', 'Left over (Rs)'], $rows[0]);
        // Two tikkas for Rs 640 used Rs 200 of paneer.
        $this->assertSame(['Paneer Tikka', '2', '640.00', '200.00', '31.25', '440.00'], $rows[1]);
        // No recipe is no cost on record: blanks, not zeros that would drag an average down.
        $this->assertSame(['Dal Makhani', '1', '240.00', '', '', '240.00'], $rows[2]);
        $this->assertCount(3, $rows);
    }

    public function test_stock_reports_give_one_unit_down_the_column(): void
    {
        $this->signInAs('owner', $this->at);
        $this->stocked($this->paneer, '2.5', 'kg');

        $oil = $this->stockItem($this->at['tenant'], 'Refined Oil', 'ml', 150, ['category' => 'Dry goods']);
        $this->stocked($oil, 500, 'ml');

        $rows = $this->rows($this->export('stock-value'));

        $this->assertSame(['Category', 'Item', 'Unit', 'On hand', 'Worth (Rs)'], $rows[0]);
        // Kilos and litres whatever the amount - "500 ml" and "2.5 kg" in one column cannot be summed.
        $this->assertContains(['Dairy', 'Paneer', 'kg', '2.5', '1000.00'], $rows);
        $this->assertContains(['Dry goods', 'Refined Oil', 'l', '0.5', '75.00'], $rows);

        // A count finds 600 g of paneer missing.
        app(StoreRoom::class)->count($this->at['outlet'], Outlets::stores($this->at['outlet'])['main'], '2026-10-02', [
            ['item' => $this->paneer, 'batch_id' => 0, 'qty' => Units::toBase('1.9', 'kg', 'g')],
        ]);

        $rows = $this->rows($this->export('variance', ['from' => '2026-10-01', 'to' => '2026-10-31']));

        $this->assertSame(['Item', 'Unit', 'Came in', 'Recipes used', 'Wasted', 'Sent to other outlets', 'Counts found', 'Counts found (Rs)'], $rows[0]);
        // A loss stays a negative number, not text.
        $this->assertContains(['Paneer', 'kg', '2.5', '0', '0', '0', '-0.6', '-240.00'], $rows);
    }

    public function test_purchases_come_out_as_the_register_and_payables_by_supplier(): void
    {
        $this->signInAs('owner', $this->at);

        $dairy = Supplier::allTenants()->create(['tenant_id' => $this->at['tenant'], 'name' => 'Amul Dairy', 'terms_days' => 7]);
        $curd = $this->stockItem($this->at['tenant'], 'Curd', 'g', 80, ['track_expiry' => true]);

        $line = fn (StockItem $item, int $kilos, int $rupees, array $batch = []) => $batch + [
            'item' => $item, 'qty' => Units::toBase($kilos, 'kg', 'g'), 'entered_qty' => (string) $kilos, 'unit' => 'kg',
            'amount_paise' => $rupees * 100, 'batch_no' => null, 'expiry' => null,
        ];

        app(Purchasing::class)->receive($this->at['outlet'], [
            'store' => Outlets::stores($this->at['outlet'])['main'], 'supplier' => $dairy, 'received_on' => '2026-10-03', 'bill_no' => 'AD/118',
        ], [
            $line($this->paneer, 10, 4000),
            // Goes off in five days, whenever this test is run.
            $line($curd, 5, 400, ['batch_no' => 'CRD-1', 'expiry' => $expires = now()->addDays(5)->toDateString()]),
        ]);

        $rows = $this->rows($this->export('purchases', ['from' => '2026-10-01', 'to' => '2026-10-31']));

        $this->assertSame(['Date', 'Receipt', 'Supplier', 'Supplier bill', 'Item', 'Unit', 'Quantity', 'Before tax (Rs)', 'Tax (Rs)', 'Total (Rs)'], $rows[0]);
        // One row for every line received; 5% input tax on the paneer.
        $this->assertSame(['2026-10-03', 'GRN-0001', 'Amul Dairy', 'AD/118', 'Paneer', 'kg', '10', '4000.00', '200.00', '4200.00'], $rows[1]);
        $this->assertSame(['2026-10-03', 'GRN-0001', 'Amul Dairy', 'AD/118', 'Curd', 'kg', '5', '400.00', '0.00', '400.00'], $rows[2]);

        // Outside the days asked for there is only the heading.
        $this->assertCount(1, $this->rows($this->export('purchases', ['from' => '2026-11-01', 'to' => '2026-11-30'])));

        $rows = $this->rows($this->export('payables'));
        $this->assertSame('You owe (Rs)', $rows[0][5]);
        $this->assertSame(['Amul Dairy', '4600.00'], [$rows[1][0], $rows[1][5]]);

        $rows = $this->rows($this->export('expiry'));
        $this->assertSame(['Item', 'Batch', 'Expires', 'Days left', 'Unit', 'On hand', 'Worth (Rs)'], $rows[0]);
        $this->assertSame(['Curd', 'CRD-1', $expires], array_slice($rows[1], 0, 3));
    }

    public function test_a_name_that_looks_like_a_formula_is_not_run_by_the_spreadsheet(): void
    {
        $this->signInAs('owner', $this->at);

        $trap = $this->stockItem($this->at['tenant'], '=HYPERLINK("http://evil.test","Paneer")', 'g', 100, ['category' => '+Dairy']);
        $this->stocked($trap, 1, 'kg');

        $rows = $this->rows($this->export('stock-value'));

        $this->assertSame("'+Dairy", $rows[1][0]);
        $this->assertSame("'=HYPERLINK(\"http://evil.test\",\"Paneer\")", $rows[1][1]);

        // A number that happens to be negative is still a number.
        $this->assertSame('-240.00', Csv::cell('-240.00'));
        $this->assertSame("'-Dairy", Csv::cell('-Dairy'));
        $this->assertSame('', Csv::cell(null));
    }

    public function test_a_report_is_downloaded_only_by_somebody_who_may_open_it(): void
    {
        $this->signInAs('storekeeper', $this->at);
        $this->export('stock-value')->assertForbidden();

        // An auditor reads and exports, and changes nothing.
        $this->flushSession();
        $this->signInAs('auditor', $this->at);
        $this->export('stock-value')->assertOk();
        $this->get(route('reports.show', 'stock-value'))->assertOk()->assertSee('Download CSV');

        $this->get('/reports/everything/export')->assertNotFound();
    }

    public function test_a_suspended_outlet_can_still_take_its_figures_away(): void
    {
        $suspended = $this->business('Late Payers', ['erp' => 'suspended']);
        $this->signInAs('owner', $suspended);

        $this->assertSame(['Supplier', 'Not yet due (Rs)'], array_slice($this->rows($this->export('payables'))[0], 0, 2));
    }
}
