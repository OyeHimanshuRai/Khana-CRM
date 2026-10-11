<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\StockItem;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\Store;
use App\Services\Reports;
use App\Services\StockService;
use App\Services\Transfers;
use App\Stock\Posting;
use App\Support\Outlets;
use App\Support\Units;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Tests\TestCase;

/**
 * Stock sent from one outlet of a business to another: off one shelf when it
 * is sent, on the other when it is received, and accounted for in between.
 */
class TransfersTest extends TestCase
{
    use RefreshDatabase;

    /** @var array{tenant: string, outlet: string} The central kitchen. */
    private array $here;

    /** @var array{tenant: string, outlet: string} The branch. */
    private array $there;

    private Store $main;

    private Store $branchStore;

    private StockService $stock;

    private Transfers $transfers;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');

        $this->here = $this->business('Spice Garden');
        $this->there = $this->business('Spice Garden', ['erp' => 'active'], $this->here['tenant']);

        $this->main = Outlets::stores($this->here['outlet'])['main'];
        $this->branchStore = Outlets::stores($this->there['outlet'])['main'];
        $this->stock = app(StockService::class);
        $this->transfers = app(Transfers::class);
    }

    private function kg(string|int $amount): int
    {
        return Units::toBase($amount, 'kg', 'g');
    }

    /** Paneer at the central kitchen: 4 kg that goes off on the 5th, 6 kg on the 9th. */
    private function paneerInTwoBatches(): StockItem
    {
        $paneer = $this->stockItem($this->here['tenant'], 'Paneer', 'g', 400, ['track_expiry' => true]);

        foreach ([['PAN-A', '2026-10-05', 4, 400], ['PAN-B', '2026-10-09', 6, 440]] as [$number, $expiry, $kilos, $price]) {
            $batch = Batch::allOutlets()->create([
                'outlet_id' => $this->here['outlet'], 'stock_item_id' => $paneer->id, 'batch_no' => $number, 'expiry_date' => $expiry,
            ]);

            $this->stock->receive($this->main, $paneer, $this->kg($kilos), Units::rateFromQuote($price, 'g'),
                new Posting(StockMovement::RECEIPT, businessDate: '2026-10-01'), $batch);
        }

        return $paneer;
    }

    private function send(StockItem $item, string|int $kilos): StockTransfer
    {
        return $this->transfers->send($this->here['outlet'], $this->main, $this->there['outlet'], '2026-10-01', [
            ['item' => $item, 'qty' => $this->kg($kilos)],
        ]);
    }

    /* --------------------------------------------------------- sending */

    public function test_sending_takes_the_oldest_batches_off_the_shelf_and_puts_them_on_the_transfer(): void
    {
        $paneer = $this->paneerInTwoBatches();

        $transfer = $this->send($paneer, 5);

        $this->assertSame('TRF-0001', $transfer->number);
        $this->assertSame(StockTransfer::SENT, $transfer->status);

        // All of the batch that goes off first, and a kilo of the next.
        $this->assertSame(
            [['PAN-A', $this->kg(4), '2026-10-05'], ['PAN-B', $this->kg(1), '2026-10-09']],
            $transfer->lines->map(fn ($line) => [$line->batch_no, $line->qty, $line->expiry_date->toDateString()])->all(),
        );

        // 4 kg at Rs 400 and 1 kg at Rs 440.
        $this->assertSame(160_000 + 44_000, $transfer->value_paise);

        // Gone from here, and not there yet: it is on the transfer.
        $this->assertSame($this->kg(5), $this->stock->onHand($this->here['outlet'], $paneer));
        $this->assertSame(0, $this->stock->onHand($this->there['outlet'], $paneer));
        $this->assertSame(204_000, $this->transfers->onTheWayFrom($this->here['outlet']));
        $this->assertSame(204_000, app(Reports::class)->stockValue($this->here['outlet'])['in_transit']);
    }

    public function test_stock_only_goes_to_another_outlet_of_the_same_business_that_uses_erp(): void
    {
        $rice = $this->stockItem($this->here['tenant'], 'Rice', 'g', 100);
        $line = [['item' => $rice, 'qty' => $this->kg(1)]];

        $rival = $this->business('Rival Dhaba');
        $crmOnly = $this->business('Spice Garden', ['crm' => 'active'], $this->here['tenant']);
        $suspended = $this->business('Spice Garden', ['erp' => 'suspended'], $this->here['tenant']);

        foreach ([$this->here['outlet'], $rival['outlet'], $crmOnly['outlet'], $suspended['outlet']] as $nowhere) {
            try {
                $this->transfers->send($this->here['outlet'], $this->main, $nowhere, '2026-10-01', $line);
                $this->fail('A transfer was sent somewhere it cannot go.');
            } catch (RuntimeException) {
                // Refused, as it should be.
            }
        }

        $this->assertSame(0, StockTransfer::allTenants()->count());
        $this->assertSame(0, StockMovement::allOutlets()->count());
    }

    /* ------------------------------------------------------- receiving */

    public function test_receiving_puts_the_same_batches_on_the_other_shelf_at_the_cost_they_left_at(): void
    {
        $paneer = $this->paneerInTwoBatches();
        $transfer = $this->send($paneer, 5);

        $transfer = $this->transfers->receive($transfer, $this->branchStore, '2026-10-02');

        $this->assertSame(StockTransfer::RECEIVED, $transfer->status);
        $this->assertSame('2026-10-02', $transfer->received_on->toDateString());
        $this->assertSame(0, $transfer->short_paise);
        $this->assertSame($this->kg(5), $this->stock->onHand($this->there['outlet'], $paneer));

        // The branch has its own rows for the two batches, dated as the sender's were.
        $arrived = Batch::allOutlets()->where('outlet_id', $this->there['outlet'])->orderBy('batch_no')->get();
        $this->assertSame([['PAN-A', '2026-10-05'], ['PAN-B', '2026-10-09']],
            $arrived->map(fn (Batch $batch) => [$batch->batch_no, $batch->expiry_date->toDateString()])->all());

        // Nothing was made or lost on the way: what came in is worth what went out.
        $out = (int) StockMovement::allOutlets()->where('type', StockMovement::OUTLET_OUT)->sum('value_paise');
        $in = (int) StockMovement::allOutlets()->where('type', StockMovement::OUTLET_IN)->sum('value_paise');
        $this->assertSame(-204_000, $out);
        $this->assertSame(204_000, $in);
        $this->assertSame(0, $this->transfers->onTheWayFrom($this->here['outlet']));

        // A transfer is not a purchase: what paneer last cost a supplier is untouched.
        $this->assertSame(Units::rateFromQuote(440, 'g'), $paneer->fresh()->last_cost_rate);

        $this->assertSame([], $this->stock->reconcile($this->here['outlet']));
        $this->assertSame([], $this->stock->reconcile($this->there['outlet']));
    }

    public function test_what_did_not_arrive_is_written_off_against_the_transfer(): void
    {
        $rice = $this->stockItem($this->here['tenant'], 'Rice', 'g', 100);
        $this->stock->receive($this->main, $rice, $this->kg(50), $rice->last_cost_rate, new Posting(StockMovement::RECEIPT, businessDate: '2026-10-01'));

        $transfer = $this->send($rice, 10);
        $line = $transfer->lines->sole();

        $transfer = $this->transfers->receive($transfer, $this->branchStore, '2026-10-02', [$line->id => $this->kg(8)]);

        // Ten kilos were sent, eight are on the shelf, and the ledger says where the two went.
        $this->assertSame($this->kg(8), $this->stock->onHand($this->there['outlet'], $rice));
        $this->assertSame(20_000, $transfer->short_paise);
        $this->assertSame($this->kg(8), $line->fresh()->received_qty);
        $this->assertSame($this->kg(2), $line->fresh()->shortQty());

        $lost = StockMovement::allOutlets()->where('type', StockMovement::WASTAGE)->sole();
        $this->assertSame($this->there['outlet'], $lost->outlet_id);
        $this->assertSame(-$this->kg(2), $lost->qty);
        $this->assertStringContainsString('Short on TRF-0001', $lost->reason);
    }

    public function test_more_than_was_sent_cannot_arrive_and_a_transfer_is_received_once(): void
    {
        $rice = $this->stockItem($this->here['tenant'], 'Rice', 'g', 100);
        $transfer = $this->send($rice, 10);
        $line = $transfer->lines->sole();

        try {
            $this->transfers->receive($transfer, $this->branchStore, '2026-10-02', [$line->id => $this->kg(11)]);
            $this->fail('Eleven kilos arrived of the ten that were sent.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('the most that can have arrived', $e->getMessage());
        }

        // Refused whole: nothing reached the branch.
        $this->assertSame(0, $this->stock->onHand($this->there['outlet'], $rice));
        $this->assertSame(StockTransfer::SENT, $transfer->fresh()->status);

        // Nor into a store of the outlet that sent it.
        try {
            $this->transfers->receive($transfer, $this->main, '2026-10-02');
            $this->fail('A transfer was received at the outlet that sent it.');
        } catch (RuntimeException) {
        }

        // Nor the day before it left.
        try {
            $this->transfers->receive($transfer, $this->branchStore, '2026-09-30');
            $this->fail('A transfer arrived before it was sent.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('cannot have arrived before', $e->getMessage());
        }

        $this->assertSame(StockTransfer::SENT, $transfer->fresh()->status);

        $this->transfers->receive($transfer, $this->branchStore, '2026-10-02');

        $this->expectException(RuntimeException::class);
        $this->transfers->receive($transfer, $this->branchStore, '2026-10-02');
    }

    /* ------------------------------------------------------ cancelling */

    public function test_a_transfer_nobody_received_goes_back_into_the_batches_it_left(): void
    {
        $paneer = $this->paneerInTwoBatches();
        $shelf = fn () => StockLevel::allOutlets()->where('outlet_id', $this->here['outlet'])
            ->orderBy('batch_id')->get(['batch_id', 'qty', 'cost_rate'])->toArray();

        $before = $shelf();
        $transfer = $this->send($paneer, 5);

        $this->transfers->cancel($transfer);

        $this->assertSame(StockTransfer::CANCELLED, $transfer->fresh()->status);
        $this->assertSame($before, $shelf(), 'every batch is as it was, at the cost it was');
        $this->assertSame(0, (int) StockMovement::allOutlets()->where('type', StockMovement::OUTLET_OUT)->sum('qty'));
        $this->assertSame(0, $this->transfers->onTheWayFrom($this->here['outlet']));
        $this->assertSame([], $this->stock->reconcile($this->here['outlet']));

        // Once it has arrived there is nothing to call back.
        $second = $this->send($paneer, 1);
        $this->transfers->receive($second, $this->branchStore, '2026-10-02');

        $this->expectException(RuntimeException::class);
        $this->transfers->cancel($second);
    }

    /* --------------------------------------------------------- screens */

    public function test_a_transfer_is_sent_from_one_outlet_and_received_at_the_other_from_the_forms(): void
    {
        $paneer = $this->paneerInTwoBatches();

        // The store manager at the central kitchen sends it.
        $this->signInAs('store_manager', $this->here);

        $this->get(route('transfers.create'))->assertOk()->assertSee($this->there['outlet']);

        $this->post(route('transfers.store'), [
            'to_outlet_id' => $this->there['outlet'], 'from_store_id' => $this->main->id, 'on_date' => '2026-10-01',
            'note' => 'For the weekend', 'lines' => [['item' => $paneer->id, 'qty' => '5', 'unit' => 'kg']],
        ])->assertRedirect();

        $transfer = StockTransfer::allTenants()->sole();

        $this->get(route('transfers.index'))->assertOk()->assertSee('TRF-0001')->assertSee('On its way');
        $this->get(route('transfers.show', $transfer->id))->assertOk()->assertSee('PAN-A')->assertSee('Cancel transfer')->assertDontSee('Put it in');
        $this->get(route('reports.show', 'stock-value'))->assertOk()->assertSee('On its way to another outlet')->assertSee('2,040.00');

        // It is not theirs to receive.
        $this->put(route('transfers.receive', $transfer->id), ['to_store_id' => $this->main->id, 'on_date' => '2026-10-02'])->assertForbidden();

        // The storekeeper at the branch is told, and takes it in.
        $this->flushSession();
        $this->signInAs('storekeeper', $this->there);

        $this->get('/')->assertOk()->assertSee('Transfers to receive');
        $this->get(route('transfers.index'))->assertOk()->assertSee('Waiting to be received')->assertSee('TRF-0001');
        $this->get(route('transfers.show', $transfer->id))->assertOk()->assertSee('Put it in')->assertSee('value="4"', false)->assertDontSee('Cancel transfer');

        $lines = $transfer->lines;

        // The first batch came whole; the kilo of the second was 200 g light.
        $this->put(route('transfers.receive', $transfer->id), [
            'to_store_id' => $this->branchStore->id, 'on_date' => '2026-10-02',
            'received' => [$lines[0]->id => '4', $lines[1]->id => '0.8'],
        ])->assertRedirect(route('transfers.show', $transfer->id));

        $this->assertSame($this->kg('4.8'), $this->stock->onHand($this->there['outlet'], $paneer));
        $this->assertSame(8_800, $transfer->fresh()->short_paise, '200 g at Rs 440 a kilo');

        $this->get(route('transfers.show', $transfer->id))->assertOk()->assertSee('Received')->assertSee('Did not arrive')->assertSee('200 g');
        $this->get('/')->assertOk()->assertDontSee('Transfers to receive');

        // Sending is not a storekeeper's job, and it cannot be called back now.
        $this->get(route('transfers.create'))->assertForbidden();
        $this->put(route('transfers.cancel', $transfer->id))->assertForbidden();
    }

    public function test_a_transfer_is_seen_only_by_the_two_outlets_it_is_between(): void
    {
        $rice = $this->stockItem($this->here['tenant'], 'Rice', 'g', 100);
        $transfer = $this->send($rice, 10);

        // A third outlet of the same business.
        $third = $this->business('Spice Garden', ['erp' => 'active'], $this->here['tenant']);
        $this->signInAs('owner', $third);

        $this->get(route('transfers.show', $transfer->id))->assertNotFound();
        $this->get(route('transfers.index'))->assertOk()->assertDontSee('TRF-0001');
        $this->put(route('transfers.receive', $transfer->id), [
            'to_store_id' => Outlets::stores($third['outlet'])['main']->id, 'on_date' => '2026-10-02',
        ])->assertNotFound();

        // And another business entirely.
        $this->flushSession();
        $this->signInAs('owner', $this->business('Rival Dhaba'));

        $this->get(route('transfers.show', $transfer->id))->assertNotFound();
        $this->assertSame(StockTransfer::SENT, $transfer->fresh()->status);
    }

    public function test_the_sender_calls_a_transfer_back_from_its_page(): void
    {
        $rice = $this->stockItem($this->here['tenant'], 'Rice', 'g', 100);
        $this->stock->receive($this->main, $rice, $this->kg(50), $rice->last_cost_rate, new Posting(StockMovement::RECEIPT, businessDate: '2026-10-01'));
        $transfer = $this->send($rice, 10);

        // The outlet it is going to cannot cancel it.
        $this->signInAs('owner', $this->there);
        $this->put(route('transfers.cancel', $transfer->id))->assertForbidden();

        $this->flushSession();
        $this->signInAs('store_manager', $this->here);

        $this->put(route('transfers.cancel', $transfer->id))->assertRedirect(route('transfers.show', $transfer->id));

        $this->assertSame($this->kg(50), $this->stock->onHand($this->here['outlet'], $rice));
        $this->get(route('transfers.show', $transfer->id))->assertOk()->assertSee('Cancelled');
    }

    public function test_the_transfers_tab_is_only_drawn_where_there_is_somewhere_to_send_stock(): void
    {
        $this->signInAs('owner', $this->here);
        $this->assertStringContainsString(route('transfers.index'), $this->get(route('stock.index'))->assertOk()->getContent());

        // A business with one outlet has no use for it - but the page still opens and says why.
        $this->flushSession();
        $alone = $this->business('Corner Cafe');
        $this->signInAs('owner', $alone);

        $this->assertStringNotContainsString(route('transfers.index'), $this->get(route('stock.index'))->assertOk()->getContent());
        $this->get(route('transfers.index'))->assertOk()->assertSee('Nowhere to send stock yet');
        $this->get(route('transfers.create'))->assertRedirect(route('transfers.index'));
    }

    public function test_the_variance_report_shows_what_was_sent_and_what_came(): void
    {
        $rice = $this->stockItem($this->here['tenant'], 'Rice', 'g', 100);
        $this->stock->receive($this->main, $rice, $this->kg(50), $rice->last_cost_rate, new Posting(StockMovement::RECEIPT, businessDate: '2026-10-01'));
        $this->transfers->receive($this->send($rice, 10), $this->branchStore, '2026-10-02');

        $reports = app(Reports::class);

        $sender = $reports->variance($this->here['outlet'], '2026-10-01', '2026-10-31')->sole();
        $this->assertSame([$this->kg(50), $this->kg(10)], [$sender['received'], $sender['sent']]);

        $receiver = $reports->variance($this->there['outlet'], '2026-10-01', '2026-10-31')->sole();
        $this->assertSame([$this->kg(10), 0], [$receiver['received'], $receiver['sent']]);

        $this->signInAs('owner', $this->here);
        $this->get(route('reports.show', ['report' => 'variance', 'from' => '2026-10-01', 'to' => '2026-10-31']))
            ->assertOk()->assertSee('Sent to other outlets')->assertSee('10 kg');
    }
}
