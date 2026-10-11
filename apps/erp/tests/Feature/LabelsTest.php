<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\GoodsReceipt;
use App\Models\StockDocument;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Services\Purchasing;
use App\Services\Recipes;
use App\Services\StockService;
use App\Services\StoreRoom;
use App\Stock\Posting;
use App\Support\Outlets;
use App\Support\Units;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Batch labels: what is stuck on the tub so the oldest is used first. Printed
 * for the batches a delivery brought, or the one a production run made.
 */
class LabelsTest extends TestCase
{
    use RefreshDatabase;

    /** @var array{tenant: string, outlet: string} */
    private array $at;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');

        $this->at = $this->business('Spice Garden');
    }

    /** A delivery of curd and paneer, each its own batch, and rice that has none. */
    private function delivery(): GoodsReceipt
    {
        $dairy = Supplier::allTenants()->create(['tenant_id' => $this->at['tenant'], 'name' => 'Amul Dairy', 'terms_days' => 7]);

        $line = fn (StockItem $item, int $kilos, int $rupees, array $batch = []) => $batch + [
            'item' => $item, 'qty' => Units::toBase($kilos, 'kg', 'g'), 'entered_qty' => (string) $kilos, 'unit' => 'kg',
            'amount_paise' => $rupees * 100, 'batch_no' => null, 'expiry' => null,
        ];

        return app(Purchasing::class)->receive($this->at['outlet'], [
            'store' => Outlets::stores($this->at['outlet'])['main'], 'supplier' => $dairy, 'received_on' => '2026-10-01',
        ], [
            $line($this->stockItem($this->at['tenant'], 'Curd', 'g', 80, ['track_expiry' => true]), 5, 400, ['batch_no' => 'CRD-1', 'expiry' => '2026-10-06']),
            $line($this->stockItem($this->at['tenant'], 'Paneer', 'g', 400, ['track_expiry' => true]), 10, 4000, ['batch_no' => 'PAN-7', 'expiry' => '2026-10-04']),
            $line($this->stockItem($this->at['tenant'], 'Basmati Rice', 'g', 110), 25, 2750),
        ]);
    }

    public function test_a_delivery_prints_a_label_for_each_batch_it_brought(): void
    {
        $this->signInAs('storekeeper', $this->at);
        $receipt = $this->delivery();

        $this->get(route('receipts.show', $receipt->id))->assertOk()->assertSee('Print labels');

        $html = $this->get(route('labels.show', ['receipt' => $receipt->id]))->assertOk()->getContent();

        // The two that go off, soonest first; rice has no batch, so no label.
        $this->assertSame(2, substr_count($html, 'class="label"'));
        $this->assertLessThan(strpos($html, 'Batch CRD-1'), strpos($html, 'Batch PAN-7'));
        $this->assertStringContainsString('Use by 04 Oct 2026', $html);
        $this->assertStringContainsString('Received 01 Oct 2026', $html);
        $this->assertStringContainsString(Outlets::name($this->at['outlet']), $html);
        $this->assertStringNotContainsString('Basmati Rice', $html);

        // A page to print: no sidebar, and sized for the sheet.
        $this->assertStringNotContainsString('data-label="Stock"', $html);
        $this->assertStringContainsString('size: A4', $html);
        $this->assertStringContainsString('Print 2 labels', $html);
    }

    public function test_copies_and_paper_are_chosen_and_the_paper_is_remembered(): void
    {
        $this->signInAs('storekeeper', $this->at);
        $receipt = $this->delivery();

        $html = $this->get(route('labels.show', ['receipt' => $receipt->id, 'copies' => 3, 'paper' => 'roll-50']))->assertOk()->getContent();

        $this->assertSame(6, substr_count($html, 'class="label"'));
        // A roll is one label to a page.
        $this->assertSame(6, substr_count($html, 'class="page"'));
        $this->assertStringContainsString('size: 50mm 25mm', $html);

        // The kitchen has one printer: next time it is still the roll.
        $again = $this->get(route('labels.show', ['receipt' => $receipt->id]))->assertOk()->getContent();
        $this->assertStringContainsString('size: 50mm 25mm', $again);

        // A sheet of 24 takes 24, then starts another.
        $sheets = $this->get(route('labels.show', ['receipt' => $receipt->id, 'copies' => 13, 'paper' => 'a4-24']))->assertOk()->getContent();
        $this->assertSame(26, substr_count($sheets, 'class="label"'));
        $this->assertSame(2, substr_count($sheets, 'class="page"'));

        // Nonsense is put right rather than obeyed.
        $odd = $this->get(route('labels.show', ['receipt' => $receipt->id, 'copies' => 9999, 'paper' => 'billboard']))->assertOk()->getContent();
        $this->assertSame(100, substr_count($odd, 'class="label"'));
        $this->assertStringContainsString('size: A4', $odd);
    }

    public function test_a_production_run_of_something_that_goes_off_makes_a_batch_to_label(): void
    {
        $this->signInAs('store_manager', $this->at);

        $tomato = $this->stockItem($this->at['tenant'], 'Tomato', 'g', 40);
        $gravy = $this->stockItem($this->at['tenant'], 'Makhani Gravy', 'ml', 0, ['is_prepared' => true, 'track_expiry' => true, 'shelf_life_days' => 3]);
        $kitchen = Outlets::stores($this->at['outlet'])['kitchen'];

        app(StockService::class)->receive($kitchen, $tomato, Units::toBase(20, 'kg', 'g'), $tomato->last_cost_rate,
            new Posting(StockMovement::RECEIPT, businessDate: '2026-10-01'));

        app(Recipes::class)->save($this->at['outlet'], ['stock_item_id' => $gravy->id], [
            ['item' => $tomato, 'qty' => Units::toBase(4, 'kg', 'g'), 'entered_qty' => '4', 'unit' => 'kg'],
        ], Units::toBase(5, 'l', 'ml'));

        $room = app(StoreRoom::class);
        $monday = $room->produce($this->at['outlet'], $kitchen, $gravy, Units::toBase(5, 'l', 'ml'), '2026-10-05');
        $tuesday = $room->produce($this->at['outlet'], $kitchen, $gravy, Units::toBase(5, 'l', 'ml'), '2026-10-06');

        // Numbered after its own note, made that day, good for the item's three days.
        $batch = Batch::allOutlets()->where('batch_no', $monday->number)->sole();
        $this->assertSame(['2026-10-05', '2026-10-08'], [$batch->made_on->toDateString(), $batch->expiry_date->toDateString()]);
        $this->assertSame($batch->id, $monday->lines->firstWhere('qty', '>', 0)->batch_id);

        // And Monday's is used before Tuesday's.
        $used = app(StockService::class)->take($kitchen, $gravy, Units::toBase(6, 'l', 'ml'), new Posting(StockMovement::CONSUMPTION, businessDate: '2026-10-06'));
        $this->assertSame([[$batch->id, -Units::toBase(5, 'l', 'ml')], [Batch::allOutlets()->where('batch_no', $tuesday->number)->sole()->id, -Units::toBase(1, 'l', 'ml')]],
            $used->map(fn (StockMovement $movement) => [$movement->batch_id, $movement->qty])->all());

        $this->get(route('production.index'))->assertOk()->assertSee('Print labels');

        $this->get(route('labels.show', ['document' => $monday->id]))
            ->assertOk()
            ->assertSee('Makhani Gravy')
            ->assertSee('Batch '.$monday->number)
            ->assertSee('Made 05 Oct 2026')
            ->assertSee('Use by 08 Oct 2026');

        // Something that does not go off is made as before: no batch, nothing to label.
        $dough = $this->stockItem($this->at['tenant'], 'Dough', 'g', 0, ['is_prepared' => true]);
        app(Recipes::class)->save($this->at['outlet'], ['stock_item_id' => $dough->id], [
            ['item' => $tomato, 'qty' => Units::toBase(1, 'kg', 'g'), 'entered_qty' => '1', 'unit' => 'kg'],
        ], Units::toBase(1, 'kg', 'g'));

        $plain = $room->produce($this->at['outlet'], $kitchen, $dough, Units::toBase(1, 'kg', 'g'), '2026-10-06');
        $this->assertSame(0, $plain->lines->firstWhere('qty', '>', 0)->batch_id);
        $this->get(route('labels.show', ['document' => $plain->id]))->assertNotFound();
        $this->assertSame(3, StockDocument::allOutlets()->count());
    }

    public function test_a_batch_is_labelled_from_the_item_s_own_page(): void
    {
        $this->signInAs('auditor', $this->at);
        $this->delivery();
        $batch = Batch::allOutlets()->where('batch_no', 'CRD-1')->sole();

        $this->assertStringContainsString(
            e(route('labels.show', ['batch' => [$batch->id]])),
            $this->get(route('stock.show', $batch->stock_item_id))->assertOk()->getContent(),
        );

        $html = $this->get(route('labels.show', ['batch' => [$batch->id]]))->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'class="label"'));
        $this->assertStringContainsString('Print 1 label<', $html);
    }

    public function test_another_outlet_s_batches_cannot_be_printed_by_naming_them(): void
    {
        $this->signInAs('owner', $this->at);
        $receipt = $this->delivery();
        $batch = Batch::allOutlets()->where('batch_no', 'PAN-7')->sole();

        $this->flushSession();
        $this->signInAs('owner', $this->business('Rival Dhaba'));

        $this->get(route('labels.show', ['batch' => [$batch->id]]))->assertNotFound();
        $this->get(route('labels.show', ['receipt' => $receipt->id]))->assertNotFound();
        // And with nothing asked for there is nothing to print.
        $this->get(route('labels.show'))->assertNotFound();
    }
}
