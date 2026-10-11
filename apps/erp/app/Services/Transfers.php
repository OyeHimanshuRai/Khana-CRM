<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\StockTransferLine;
use App\Models\Store;
use App\Stock\Posting;
use App\Support\Numbering;
use App\Support\Outlets;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Stock sent from one outlet of a business to another: a central kitchen's
 * gravy to its branches, a crate of paneer to the outlet that ran out.
 *
 * It takes two people in two buildings, so it is two steps. Sending takes
 * the stock off the sender's shelf there and then. Until the other outlet
 * says it came, it is on neither shelf - it is on the transfer, at the cost
 * it left at. Receiving puts it on theirs, as the same batches at the same
 * cost; a transfer nobody has received yet can be called back.
 *
 * Like everything else that moves stock, none of it writes a level itself:
 * it asks StockService.
 */
class Transfers
{
    public function __construct(private readonly StockService $stock) {}

    /**
     * Send stock to another outlet.
     *
     * Each item leaves earliest expiry first, and the transfer keeps one
     * line for every batch that went - which is what lets the other outlet
     * receive the very batches that were sent.
     *
     * @param  list<array{item: StockItem, qty: int}>  $lines
     */
    public function send(string $fromOutlet, Store $from, string $toOutlet, string $onDate, array $lines, ?string $note = null): StockTransfer
    {
        if ($from->outlet_id !== $fromOutlet) {
            throw new RuntimeException('That store belongs to another outlet.');
        }

        if ($toOutlet === $fromOutlet) {
            throw new RuntimeException('A transfer goes to a different outlet. To move stock inside this one, issue it to the kitchen.');
        }

        if (! Outlets::others($fromOutlet)->contains('id', $toOutlet)) {
            throw new RuntimeException('That outlet cannot take stock: it is not an outlet of this business that uses ERP.');
        }

        if ($lines === []) {
            throw new RuntimeException('A transfer has at least one item in it.');
        }

        return DB::transaction(function () use ($fromOutlet, $from, $toOutlet, $onDate, $lines, $note) {
            $transfer = StockTransfer::allTenants()->create([
                'tenant_id' => Outlets::tenantOf($fromOutlet),
                'from_outlet_id' => $fromOutlet,
                'to_outlet_id' => $toOutlet,
                'number' => Numbering::next($fromOutlet, 'TRF'),
                'from_store_id' => $from->id,
                'sent_on' => $onDate,
                'note' => $note,
                'user_id' => Auth::id(),
            ]);

            $why = new Posting(StockMovement::OUTLET_OUT, $transfer, 'To '.Outlets::name($toOutlet), businessDate: $onDate);
            $worth = 0;

            foreach ($lines as $line) {
                foreach ($this->stock->take($from, $line['item'], $line['qty'], $why) as $movement) {
                    $batch = $movement->batch_id > 0 ? Batch::allOutlets()->find($movement->batch_id) : null;

                    $transfer->lines()->create([
                        'stock_item_id' => $line['item']->id,
                        'batch_id' => $movement->batch_id,
                        'batch_no' => $batch?->batch_no,
                        'expiry_date' => $batch?->expiry_date,
                        'made_on' => $batch?->made_on,
                        'qty' => -$movement->qty,
                        'cost_rate' => $movement->cost_rate,
                        'value_paise' => -$movement->value_paise,
                    ]);

                    $worth -= $movement->value_paise;
                }
            }

            $transfer->update(['value_paise' => $worth]);

            return $transfer;
        });
    }

    /**
     * Take a transfer in at the outlet it was sent to.
     *
     * Everything that was sent is booked in, and whatever did not arrive is
     * then written off as wastage against the transfer. Both rows, rather
     * than just the smaller number, so the ledger says what happened: ten
     * kilos were sent, two were lost on the way.
     *
     * @param  array<int, int>  $received  line id => what arrived; a line left out arrived whole
     */
    public function receive(StockTransfer $transfer, Store $into, string $onDate, array $received = []): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $into, $onDate, $received) {
            $transfer = StockTransfer::allTenants()->lockForUpdate()->findOrFail($transfer->id);

            if ($transfer->status !== StockTransfer::SENT) {
                throw new RuntimeException($transfer->number.' is not on its way any more: it was '.strtolower($transfer->statusLabel()).'.');
            }

            if ($into->outlet_id !== $transfer->to_outlet_id) {
                throw new RuntimeException('That store is not at the outlet this transfer was sent to.');
            }

            if ($onDate < $transfer->sent_on->toDateString()) {
                throw new RuntimeException($transfer->number.' was sent on '.$transfer->sent_on->format('d M Y').'. It cannot have arrived before that.');
            }

            $from = $transfer->fromName();
            $in = new Posting(StockMovement::OUTLET_IN, $transfer, 'From '.$from, businessDate: $onDate);
            $lost = new Posting(StockMovement::WASTAGE, $transfer, 'Short on '.$transfer->number.' from '.$from, businessDate: $onDate);
            $short = 0;

            foreach ($transfer->lines()->with('item')->get() as $line) {
                $got = array_key_exists($line->id, $received) ? (int) $received[$line->id] : $line->qty;

                if ($got < 0 || $got > $line->qty) {
                    throw new RuntimeException($line->item->name.': '.$line->item->show($line->qty).' was sent, so that is the most that can have arrived.');
                }

                $batch = $this->batchAt($transfer->to_outlet_id, $line);

                $this->stock->arrive($into, $line->item, $line->qty, $line->cost_rate, $in, $batch);

                if ($got < $line->qty) {
                    $short += abs($this->stock->takeFrom($into, $line->item, $batch?->id ?? 0, $line->qty - $got, $lost)->value_paise);
                }

                $line->update(['received_qty' => $got]);
            }

            $transfer->update([
                'status' => StockTransfer::RECEIVED,
                'to_store_id' => $into->id,
                'received_on' => $onDate,
                'short_paise' => $short,
                'received_by' => Auth::id(),
            ]);

            return $transfer;
        });
    }

    /**
     * Call back a transfer nobody has received: the stock goes back on the
     * shelf it left, into the batches it left from, at the cost it left at.
     */
    public function cancel(StockTransfer $transfer): StockTransfer
    {
        return DB::transaction(function () use ($transfer) {
            $transfer = StockTransfer::allTenants()->lockForUpdate()->findOrFail($transfer->id);

            if ($transfer->status !== StockTransfer::SENT) {
                throw new RuntimeException('Only a transfer that is still on its way can be cancelled.');
            }

            $sent = StockMovement::allOutlets()
                ->where('reference_type', $transfer->getMorphClass())
                ->where('reference_id', $transfer->id)
                ->where('type', StockMovement::OUTLET_OUT)
                ->orderBy('id')
                ->get();

            // Booked as the same kind of movement, the other way: what this
            // outlet sent away then adds up to nothing, which is the truth.
            $this->stock->reverse($sent, new Posting(
                StockMovement::OUTLET_OUT,
                $transfer,
                $transfer->number.' cancelled: put back',
                businessDate: Outlets::businessDate($transfer->from_outlet_id),
            ));

            $transfer->update(['status' => StockTransfer::CANCELLED]);

            return $transfer;
        });
    }

    /**
     * What an outlet has sent that nobody has received yet, at cost. Still
     * the sender's money, though it is on nobody's shelf.
     */
    public function onTheWayFrom(string $outletId): int
    {
        return (int) StockTransfer::allTenants()
            ->where('from_outlet_id', $outletId)
            ->where('status', StockTransfer::SENT)
            ->sum('value_paise');
    }

    /**
     * The receiving outlet's own copy of the batch a line left as. A batch
     * belongs to one outlet, so the number and the dates are carried across
     * and the row is made here the first time that batch arrives.
     */
    private function batchAt(string $outletId, StockTransferLine $line): ?Batch
    {
        if ($line->batch_no === null) {
            return null;
        }

        $batch = Batch::allOutlets()->firstOrCreate(
            ['outlet_id' => $outletId, 'stock_item_id' => $line->stock_item_id, 'batch_no' => $line->batch_no],
            ['expiry_date' => $line->expiry_date, 'made_on' => $line->made_on],
        );

        // The same number already here with no date: the sender knew it.
        if ($batch->expiry_date === null && $line->expiry_date !== null) {
            $batch->update(['expiry_date' => $line->expiry_date]);
        }

        return $batch;
    }
}
