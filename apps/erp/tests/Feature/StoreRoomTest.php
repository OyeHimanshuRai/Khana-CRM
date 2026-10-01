<?php

namespace Tests\Feature;

use App\Models\StockDocument;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Models\Store;
use App\Services\Recipes;
use App\Services\StockService;
use App\Services\StoreRoom;
use App\Stock\Posting;
use App\Support\Outlets;
use App\Support\Units;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * A storekeeper's day: issue to the kitchen, log wastage, make a batch of
 * gravy, count the shelf.
 */
class StoreRoomTest extends TestCase
{
    use RefreshDatabase;

    /** @var array{tenant: string, outlet: string} */
    private array $at;

    private Store $main;

    private Store $kitchen;

    private StoreRoom $room;

    private StockService $stock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->at = $this->business();
        ['main' => $this->main, 'kitchen' => $this->kitchen] = Outlets::stores($this->at['outlet']);
        $this->room = app(StoreRoom::class);
        $this->stock = app(StockService::class);
    }

    private function stocked(StockItem $item, string|int $amount, string $unit, ?Store $store = null): void
    {
        $this->stock->receive($store ?? $this->main, $item, Units::toBase($amount, $unit, $item->base_unit), $item->last_cost_rate,
            new Posting(StockMovement::RECEIPT, businessDate: '2026-10-01'));
    }

    private function qty(StockItem $item, string|int $amount, string $unit): int
    {
        return Units::toBase($amount, $unit, $item->base_unit);
    }

    public function test_an_issue_moves_stock_to_the_kitchen_under_one_document(): void
    {
        $paneer = $this->stockItem($this->at['tenant'], 'Paneer', 'g', 400);
        $oil = $this->stockItem($this->at['tenant'], 'Oil', 'ml', 150);
        $this->stocked($paneer, 10, 'kg');
        $this->stocked($oil, 15, 'l');

        $issue = $this->room->issue($this->at['outlet'], $this->main, $this->kitchen, '2026-10-01', [
            ['item' => $paneer, 'qty' => $this->qty($paneer, 2, 'kg')],
            ['item' => $oil, 'qty' => $this->qty($oil, 5, 'l')],
        ], 'Evening service');

        $this->assertSame('ISS-0001', $issue->number);
        $this->assertSame(StockDocument::ISSUE, $issue->kind);
        $this->assertSame(80_000 + 75_000, $issue->value_paise);
        $this->assertCount(2, $issue->lines);

        $this->assertSame($this->qty($paneer, 2, 'kg'), $this->stock->onHand($this->at['outlet'], $paneer, $this->kitchen));
        $this->assertSame($this->qty($paneer, 8, 'kg'), $this->stock->onHand($this->at['outlet'], $paneer, $this->main));
    }

    public function test_wastage_needs_a_reason_and_is_valued_at_cost(): void
    {
        $tomato = $this->stockItem($this->at['tenant'], 'Tomato', 'g', 40);
        $this->stocked($tomato, 20, 'kg', $this->kitchen);

        $note = $this->room->waste($this->at['outlet'], $this->kitchen, '2026-10-01', 'Spoiled', [
            ['item' => $tomato, 'qty' => $this->qty($tomato, 3, 'kg'), 'batch_id' => 0, 'note' => 'Crate at the bottom'],
        ]);

        $this->assertSame('WST-0001', $note->number);
        $this->assertSame(12_000, $note->value_paise);

        $movement = StockMovement::allOutlets()->where('type', StockMovement::WASTAGE)->sole();
        $this->assertSame('Spoiled - Crate at the bottom', $movement->reason);
        $this->assertSame($this->qty($tomato, 17, 'kg'), $this->stock->onHand($this->at['outlet'], $tomato));

        $this->expectException(RuntimeException::class);
        $this->room->waste($this->at['outlet'], $this->kitchen, '2026-10-01', ' ', [['item' => $tomato, 'qty' => 1, 'batch_id' => 0, 'note' => null]]);
    }

    public function test_a_prepared_item_is_made_from_its_recipe_and_costs_what_went_into_it(): void
    {
        $tomato = $this->stockItem($this->at['tenant'], 'Tomato', 'g', 40);
        $onion = $this->stockItem($this->at['tenant'], 'Onion', 'g', 30);
        $gravy = $this->stockItem($this->at['tenant'], 'Makhani Gravy', 'ml', 0, ['is_prepared' => true]);
        $this->stocked($tomato, 20, 'kg', $this->kitchen);
        $this->stocked($onion, 10, 'kg', $this->kitchen);

        // One batch of the recipe makes 5 litres.
        app(Recipes::class)->save($this->at['outlet'], ['stock_item_id' => $gravy->id], [
            ['item' => $tomato, 'qty' => $this->qty($tomato, 4, 'kg'), 'entered_qty' => '4', 'unit' => 'kg'],
            ['item' => $onion, 'qty' => $this->qty($onion, 2, 'kg'), 'entered_qty' => '2', 'unit' => 'kg'],
        ], Units::toBase(5, 'l', 'ml'));

        // Making 10 litres takes twice the recipe.
        $batch = $this->room->produce($this->at['outlet'], $this->kitchen, $gravy, Units::toBase(10, 'l', 'ml'), '2026-10-01');

        $this->assertSame('PRD-0001', $batch->number);
        $this->assertSame($this->qty($tomato, 12, 'kg'), $this->stock->onHand($this->at['outlet'], $tomato));
        $this->assertSame($this->qty($onion, 6, 'kg'), $this->stock->onHand($this->at['outlet'], $onion));
        $this->assertSame(Units::toBase(10, 'l', 'ml'), $this->stock->onHand($this->at['outlet'], $gravy));

        // 8 kg tomato at 40 + 4 kg onion at 30 = Rs 440 for 10 litres.
        $this->assertSame(44_000, $batch->value_paise);
        $this->assertSame(Units::rateFromQuote(44, 'ml'), $this->stock->costRate($this->at['outlet'], $gravy));

        // What went in equals what came out: making gravy is not a profit.
        $this->assertSame(0, (int) StockMovement::allOutlets()
            ->whereIn('type', [StockMovement::PRODUCTION_OUT, StockMovement::PRODUCTION_IN])->sum('value_paise'));
    }

    public function test_a_prepared_item_with_no_recipe_cannot_be_made(): void
    {
        $gravy = $this->stockItem($this->at['tenant'], 'Makhani Gravy', 'ml', 0, ['is_prepared' => true]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has no recipe yet');

        $this->room->produce($this->at['outlet'], $this->kitchen, $gravy, Units::toBase(10, 'l', 'ml'), '2026-10-01');
    }

    public function test_a_count_books_only_the_difference_and_leaves_uncounted_stock_alone(): void
    {
        $paneer = $this->stockItem($this->at['tenant'], 'Paneer', 'g', 400);
        $rice = $this->stockItem($this->at['tenant'], 'Rice', 'g', 100);
        $oil = $this->stockItem($this->at['tenant'], 'Oil', 'ml', 150);
        $this->stocked($paneer, 10, 'kg');
        $this->stocked($rice, 25, 'kg');
        $this->stocked($oil, 15, 'l');

        $count = $this->room->count($this->at['outlet'], $this->main, '2026-10-01', [
            ['item' => $paneer, 'batch_id' => 0, 'qty' => $this->qty($paneer, '9.4', 'kg')],
            ['item' => $rice, 'batch_id' => 0, 'qty' => $this->qty($rice, 25, 'kg')],
        ]);

        $this->assertSame('CNT-0001', $count->number);

        $lines = $count->lines->keyBy('stock_item_id');
        $this->assertSame($this->qty($paneer, 10, 'kg'), $lines[$paneer->id]->expected_qty);
        $this->assertSame($this->qty($paneer, '9.4', 'kg'), $lines[$paneer->id]->counted_qty);
        $this->assertSame($this->qty($paneer, '-0.6', 'kg'), $lines[$paneer->id]->qty);
        $this->assertSame(0, $lines[$rice->id]->qty, 'the rice was all there');

        // 600 g of paneer at Rs 400 a kilo is missing.
        $this->assertSame(-24_000, $count->value_paise);
        $this->assertSame(1, StockMovement::allOutlets()->where('type', StockMovement::ADJUSTMENT)->count());

        // The oil was not counted, so it is as the books had it.
        $this->assertSame($this->qty($oil, 15, 'l'), $this->stock->onHand($this->at['outlet'], $oil));
        $this->assertSame([], $this->stock->reconcile($this->at['outlet']));
    }

    public function test_an_opening_count_brings_stock_in_at_a_cost(): void
    {
        $rice = $this->stockItem($this->at['tenant'], 'Rice', 'g');

        $count = $this->room->count($this->at['outlet'], $this->main, '2026-10-01', [
            ['item' => $rice, 'batch_id' => 0, 'qty' => $this->qty($rice, 40, 'kg'), 'cost_rate' => Units::rateFromQuote(95, 'g')],
        ], opening: true);

        $this->assertSame(380_000, $count->value_paise);
        $this->assertSame(StockMovement::OPENING, StockMovement::allOutlets()->sole()->type);
        $this->assertSame(Units::rateFromQuote(95, 'g'), $this->stock->costRate($this->at['outlet'], $rice));
    }
}
