<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\OutletSetting;
use App\Models\StockItem;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Store;
use App\Services\StockService;
use App\Stock\Posting;
use App\Support\Outlets;
use App\Support\Units;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Khana\Kit\Support\Ulid;
use Tests\TestCase;

/**
 * The one door stock goes through.
 *
 * What is held here is what every other part of ERP leans on: a level equals
 * the sum of its movements, cost is the weighted average, batches leave
 * earliest expiry first, taking is never refused, and the same event cannot
 * post twice.
 */
class StockServiceTest extends TestCase
{
    use RefreshDatabase;

    /** @var array{tenant: string, outlet: string} */
    private array $at;

    private Store $main;

    private Store $kitchen;

    private StockService $stock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->at = $this->business();
        ['main' => $this->main, 'kitchen' => $this->kitchen] = Outlets::stores($this->at['outlet']);
        $this->stock = app(StockService::class);
    }

    private function paneer(): StockItem
    {
        return $this->stockItem($this->at['tenant'], 'Paneer', 'g');
    }

    private function batch(StockItem $item, string $no, ?string $expiry): Batch
    {
        return Batch::allOutlets()->create([
            'outlet_id' => $this->at['outlet'],
            'stock_item_id' => $item->id,
            'batch_no' => $no,
            'expiry_date' => $expiry,
        ]);
    }

    private function kg(string|int $amount): int
    {
        return Units::toBase($amount, 'kg', 'g');
    }

    private function g(string|int $amount): int
    {
        return Units::toBase($amount, 'g', 'g');
    }

    private function perKg(string|int $rupees): int
    {
        return Units::rateFromQuote($rupees, 'g');
    }

    private function why(string $type = StockMovement::RECEIPT, ?string $date = '2026-10-01'): Posting
    {
        return new Posting($type, businessDate: $date);
    }

    /* ------------------------------------------------------------ units */

    public function test_units_convert_and_read_back_the_way_people_say_them(): void
    {
        $this->assertSame(2_500_000, Units::toBase('250', 'g', 'g'));
        $this->assertSame(125_000_000, Units::toBase('12.5', 'kg', 'g'));
        $this->assertSame(240_000, Units::toBase(2, 'dozen', 'pc'));
        $this->assertSame('12.5 kg', Units::show(Units::toBase('12.5', 'kg', 'g'), 'g'));
        $this->assertSame('250 g', Units::show($this->g(250), 'g'));
        $this->assertSame('-1.2 l', Units::show(Units::toBase('-1.2', 'l', 'ml'), 'ml'));

        // Rs 400 a kilo is 40 paise a gram; 250 g of it is Rs 100.
        $this->assertSame(10_000, Units::value($this->g(250), $this->perKg(400)));
        $this->assertSame(['paise' => 40_000, 'unit' => 'kg'], Units::quote($this->perKg(400), 'g'));

        $this->expectException(\InvalidArgumentException::class);
        Units::toBase(1, 'l', 'g');
    }

    public function test_a_pack_converts_through_its_own_size(): void
    {
        $oil = $this->stockItem($this->at['tenant'], 'Oil', 'ml');
        $tin = $oil->packs()->create(['name' => '15 litre tin', 'qty' => Units::toBase(15, 'l', 'ml')]);

        $this->assertSame(Units::toBase(30, 'l', 'ml'), $oil->fresh('packs')->toBase(2, 'pack:'.$tin->id));
        $this->assertArrayHasKey('pack:'.$tin->id, $oil->fresh('packs')->unitOptions());
    }

    /* ------------------------------------------------------- in and out */

    public function test_a_receipt_raises_the_level_and_writes_the_ledger(): void
    {
        $paneer = $this->paneer();

        $movement = $this->stock->receive($this->main, $paneer, $this->kg(5), $this->perKg(400), $this->why());

        $this->assertSame($this->kg(5), $this->stock->onHand($this->at['outlet'], $paneer));
        $this->assertSame(StockMovement::RECEIPT, $movement->type);
        $this->assertSame(200_000, $movement->value_paise, 'five kilos at Rs 400');
        $this->assertSame($this->kg(5), $movement->balance_qty);
        $this->assertSame($this->at['tenant'], $movement->tenant_id);
        $this->assertSame('2026-10-01', $movement->business_date->toDateString());
        $this->assertSame($this->perKg(400), $paneer->fresh()->last_cost_rate);
    }

    public function test_cost_is_the_weighted_average_of_what_is_on_hand(): void
    {
        $paneer = $this->paneer();

        $this->stock->receive($this->main, $paneer, $this->kg(10), $this->perKg(400), $this->why());
        $this->stock->receive($this->main, $paneer, $this->kg(30), $this->perKg(440), $this->why());

        // (10 x 400 + 30 x 440) / 40 = 430 a kilo.
        $this->assertSame($this->perKg(430), $this->stock->costRate($this->at['outlet'], $paneer));

        // Taking some out does not change what the rest is worth.
        $out = $this->stock->take($this->main, $paneer, $this->kg(4), $this->why(StockMovement::CONSUMPTION));

        $this->assertSame(-172_000, $out->sum('value_paise'));
        $this->assertSame($this->perKg(430), $this->stock->costRate($this->at['outlet'], $paneer));
    }

    public function test_taking_is_never_refused_and_the_level_goes_negative(): void
    {
        $paneer = $this->paneer();
        $this->stock->receive($this->kitchen, $paneer, $this->g(200), $this->perKg(400), $this->why());

        $this->stock->take($this->kitchen, $paneer, $this->g(500), $this->why(StockMovement::CONSUMPTION));

        $this->assertSame($this->g(-300), $this->stock->onHand($this->at['outlet'], $paneer));

        // The overdrawn part is still valued - at what the item last cost.
        $this->assertSame(-20_000, (int) StockMovement::allOutlets()->where('type', StockMovement::CONSUMPTION)->sum('value_paise'));

        // A receipt into an overdrawn slot is worth what arrived, not an
        // average with a debt.
        $this->stock->receive($this->kitchen, $paneer, $this->kg(1), $this->perKg(500), $this->why());

        $this->assertSame($this->g(700), $this->stock->onHand($this->at['outlet'], $paneer));
        $this->assertSame($this->perKg(500), $this->stock->costRate($this->at['outlet'], $paneer));
    }

    /* ---------------------------------------------------------- batches */

    public function test_batches_leave_earliest_expiry_first(): void
    {
        $curd = $this->stockItem($this->at['tenant'], 'Curd', 'g', 0, ['track_expiry' => true]);
        $later = $this->batch($curd, 'B-LATER', '2026-10-20');
        $sooner = $this->batch($curd, 'B-SOONER', '2026-10-05');

        $this->stock->receive($this->main, $curd, $this->kg(2), $this->perKg(80), $this->why(), $later);
        $this->stock->receive($this->main, $curd, $this->kg(1), $this->perKg(90), $this->why(), $sooner);
        $this->stock->receive($this->main, $curd, $this->kg(1), $this->perKg(70), $this->why());

        $out = $this->stock->take($this->main, $curd, $this->g(2500), $this->why(StockMovement::CONSUMPTION));

        // All of the batch that goes off first, then the next, and the
        // undated stock not touched while a dated batch remains.
        $this->assertSame(
            [[$sooner->id, $this->kg(-1)], [$later->id, $this->g(-1500)]],
            $out->map(fn (StockMovement $m) => [$m->batch_id, $m->qty])->all(),
        );
        $this->assertSame(-9_000 - 12_000, $out->sum('value_paise'), 'each batch leaves at its own cost');
    }

    public function test_an_expired_batch_is_not_cooked_with_unless_the_outlet_says_so(): void
    {
        $curd = $this->stockItem($this->at['tenant'], 'Curd', 'g', 80, ['track_expiry' => true]);
        $old = $this->batch($curd, 'B-OLD', '2026-09-28');

        $this->stock->receive($this->kitchen, $curd, $this->kg(1), $this->perKg(80), $this->why(date: '2026-09-25'), $old);

        // On 1 Oct the batch is past its date: on the shelf, not usable.
        $this->assertSame($this->kg(1), $this->stock->onHand($this->at['outlet'], $curd));
        $this->assertSame(0, $this->stock->onHand($this->at['outlet'], $curd, usableOn: '2026-10-01'));

        $out = $this->stock->take($this->kitchen, $curd, $this->g(100), $this->why(StockMovement::CONSUMPTION));
        $this->assertSame([0], $out->pluck('batch_id')->all(), 'taken from the undated slot, leaving the expired batch to be written off');

        OutletSetting::create(['outlet_id' => $this->at['outlet'], 'tenant_id' => $this->at['tenant'], 'exclude_expired' => false]);

        $out = $this->stock->take($this->kitchen, $curd, $this->g(100), $this->why(StockMovement::CONSUMPTION));
        $this->assertSame([$old->id], $out->pluck('batch_id')->all());
    }

    /* ------------------------------------------------------------ moves */

    public function test_issuing_to_the_kitchen_moves_each_batch_at_its_cost(): void
    {
        $paneer = $this->stockItem($this->at['tenant'], 'Paneer', 'g', 0, ['track_expiry' => true]);
        $batch = $this->batch($paneer, 'P-1', '2026-10-09');

        $this->stock->receive($this->main, $paneer, $this->kg(5), $this->perKg(400), $this->why(), $batch);
        $this->stock->move($this->main, $this->kitchen, $paneer, $this->kg(2), $this->why(StockMovement::TRANSFER_OUT));

        $this->assertSame($this->kg(3), $this->stock->onHand($this->at['outlet'], $paneer, $this->main));
        $this->assertSame($this->kg(2), $this->stock->onHand($this->at['outlet'], $paneer, $this->kitchen));

        $kitchen = StockLevel::allOutlets()->where('store_id', $this->kitchen->id)->sole();
        $this->assertSame($batch->id, $kitchen->batch_id);
        $this->assertSame($this->perKg(400), $kitchen->cost_rate);

        // The outlet holds what it held: a move is not a gain or a loss.
        $this->assertSame($this->kg(5), $this->stock->onHand($this->at['outlet'], $paneer));
        $this->assertSame(0, (int) StockMovement::allOutlets()->whereIn('type', [StockMovement::TRANSFER_OUT, StockMovement::TRANSFER_IN])->sum('value_paise'));
    }

    /* ----------------------------------------------------------- counts */

    public function test_a_count_sets_the_level_and_books_only_the_difference(): void
    {
        $paneer = $this->paneer();
        $this->stock->receive($this->main, $paneer, $this->kg(5), $this->perKg(400), $this->why());

        $movement = $this->stock->setTo($this->main, $paneer, 0, $this->g(4600), $this->why(StockMovement::ADJUSTMENT));

        $this->assertSame($this->g(-400), $movement->qty);
        $this->assertSame(-16_000, $movement->value_paise);
        $this->assertSame($this->g(4600), $this->stock->onHand($this->at['outlet'], $paneer));

        $this->assertNull(
            $this->stock->setTo($this->main, $paneer, 0, $this->g(4600), $this->why(StockMovement::ADJUSTMENT)),
            'a count that agrees with the books writes nothing',
        );
    }

    public function test_opening_stock_is_given_a_cost(): void
    {
        $rice = $this->stockItem($this->at['tenant'], 'Rice', 'g');

        $movement = $this->stock->setTo($this->main, $rice, 0, $this->kg(25), $this->why(StockMovement::OPENING), $this->perKg(90));

        $this->assertSame(225_000, $movement->value_paise);
        $this->assertSame($this->perKg(90), $rice->fresh()->last_cost_rate);
    }

    /* -------------------------------------------------------- reversals */

    public function test_a_reversal_puts_back_exactly_what_was_taken(): void
    {
        $paneer = $this->paneer();
        $this->stock->receive($this->kitchen, $paneer, $this->kg(1), $this->perKg(400), $this->why());

        $taken = $this->stock->take($this->kitchen, $paneer, $this->g(250), $this->why(StockMovement::CONSUMPTION));
        $back = $this->stock->reverse($taken, $this->why(StockMovement::REVERSAL));

        $this->assertSame($this->kg(1), $this->stock->onHand($this->at['outlet'], $paneer));
        $this->assertSame(0, $taken->sum('value_paise') + $back->sum('value_paise'));
    }

    /* ------------------------------------------------------ the ledger */

    public function test_every_level_equals_the_sum_of_its_movements(): void
    {
        $paneer = $this->paneer();

        $this->stock->receive($this->main, $paneer, $this->kg(5), $this->perKg(400), $this->why());
        $this->stock->move($this->main, $this->kitchen, $paneer, $this->kg(2), $this->why(StockMovement::TRANSFER_OUT));
        $this->stock->take($this->kitchen, $paneer, $this->g(2750), $this->why(StockMovement::CONSUMPTION));
        $this->stock->setTo($this->main, $paneer, 0, $this->kg(3), $this->why(StockMovement::ADJUSTMENT));

        $this->assertSame([], $this->stock->reconcile($this->at['outlet']));

        // And the check does find a level somebody changed behind its back.
        StockLevel::allOutlets()->where('store_id', $this->main->id)->update(['qty' => 1]);

        $this->assertCount(1, $this->stock->reconcile($this->at['outlet']));
    }

    public function test_the_same_event_line_cannot_post_twice(): void
    {
        $paneer = $this->paneer();
        $event = Ulid::generate();
        $line = Ulid::generate();

        $this->stock->take($this->kitchen, $paneer, $this->g(250), new Posting(
            StockMovement::CONSUMPTION, eventId: $event, eventLine: $line, businessDate: '2026-10-01',
        ));

        $this->expectException(QueryException::class);

        $this->stock->take($this->kitchen, $paneer, $this->g(250), new Posting(
            StockMovement::CONSUMPTION, eventId: $event, eventLine: $line, businessDate: '2026-10-01',
        ));
    }
}
