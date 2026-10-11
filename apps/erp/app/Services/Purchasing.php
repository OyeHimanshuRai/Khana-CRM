<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\Expense;
use App\Models\GoodsReceipt;
use App\Models\PaidOut;
use App\Models\PurchaseOrder;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\SupplierPayment;
use App\Stock\Posting;
use App\Support\Numbering;
use App\Support\Outlets;
use App\Support\Units;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Khana\Kit\Support\Decimal;
use RuntimeException;

/**
 * Buying, from the order to the last rupee paid.
 *
 * Each public method is one thing a person does - raise an order, take a
 * delivery in, pay a supplier, send goods back, file an expense - and each
 * does all of it or none of it: the document, the stock, the bill and the
 * balance move together, in one transaction.
 *
 * Stock itself is never touched here directly. It goes through StockService,
 * like everything else that moves stock.
 */
class Purchasing
{
    public function __construct(private readonly StockService $stock) {}

    /* ------------------------------------------------------------ order */

    /**
     * @param  list<array{item: StockItem, qty: int, entered_qty: string, unit: string, amount_paise: int}>  $lines
     */
    public function order(string $outletId, Supplier $supplier, string $orderedOn, ?string $expectedOn, array $lines, ?string $notes = null): PurchaseOrder
    {
        return DB::transaction(function () use ($outletId, $supplier, $orderedOn, $expectedOn, $lines, $notes) {
            $order = PurchaseOrder::allOutlets()->create([
                'outlet_id' => $outletId,
                'supplier_id' => $supplier->id,
                'number' => Numbering::next($outletId, 'PO'),
                'ordered_on' => $orderedOn,
                'expected_on' => $expectedOn,
                'status' => PurchaseOrder::OPEN,
                'total_paise' => array_sum(array_column($lines, 'amount_paise')),
                'notes' => $notes,
                'user_id' => Auth::id(),
            ]);

            foreach ($lines as $line) {
                $order->lines()->create([
                    'stock_item_id' => $line['item']->id,
                    'qty' => $line['qty'],
                    'entered_qty' => $line['entered_qty'],
                    'entered_unit' => $line['unit'],
                    'cost_rate' => Units::rate($line['amount_paise'], $line['qty']),
                    'amount_paise' => $line['amount_paise'],
                ]);
            }

            return $order;
        });
    }

    public function cancelOrder(PurchaseOrder $order): void
    {
        if ($order->status !== PurchaseOrder::OPEN) {
            throw new RuntimeException('Only an order with nothing received yet can be cancelled.');
        }

        $order->update(['status' => PurchaseOrder::CANCELLED]);
    }

    /* ---------------------------------------------------------- receive */

    /**
     * Take a delivery in.
     *
     * Books the stock into the store at what it cost, makes the batches for
     * items that go off, ticks the order off if there was one, and - when a
     * supplier is named - raises the bill for what is now owed.
     *
     * `amount_paise` on a line is what the supplier charged for it before
     * tax. Input GST is worked out from the item, and counts towards the
     * cost of the stock unless the outlet claims it back.
     *
     * @param  array{store: Store, supplier?: ?Supplier, order?: ?PurchaseOrder, received_on: string, bill_no?: ?string, notes?: ?string, due_on?: ?string, raise_bill?: bool}  $head
     * @param  list<array{item: StockItem, qty: int, entered_qty: string, unit: string, amount_paise: int, batch_no: ?string, expiry: ?string}>  $lines
     */
    public function receive(string $outletId, array $head, array $lines): GoodsReceipt
    {
        if ($lines === []) {
            throw new RuntimeException('A delivery has at least one item in it.');
        }

        $store = $head['store'];
        $supplier = $head['supplier'] ?? null;
        $order = $head['order'] ?? null;

        if ($store->outlet_id !== $outletId) {
            throw new RuntimeException('That store belongs to another outlet.');
        }

        return DB::transaction(function () use ($outletId, $head, $lines, $store, $supplier, $order) {
            $taxIsCost = Outlets::settings($outletId)->input_tax_is_cost ?? true;

            $receipt = GoodsReceipt::allOutlets()->create([
                'outlet_id' => $outletId,
                'store_id' => $store->id,
                'supplier_id' => $supplier?->id,
                'purchase_order_id' => $order?->id,
                'number' => Numbering::next($outletId, 'GRN'),
                'received_on' => $head['received_on'],
                'bill_no' => $head['bill_no'] ?? null,
                'notes' => $head['notes'] ?? null,
                'user_id' => Auth::id(),
            ]);

            $why = new Posting(
                StockMovement::RECEIPT,
                reference: $receipt,
                reason: $supplier ? 'From '.$supplier->name : null,
                businessDate: $head['received_on'],
            );

            $net = 0;
            $tax = 0;

            foreach ($lines as $line) {
                $item = $line['item'];
                $lineTax = Decimal::mulDiv($line['amount_paise'], $item->gst_rate_bp, 10_000);
                $rate = Units::rate($line['amount_paise'] + ($taxIsCost ? $lineTax : 0), $line['qty']);
                $batch = $this->batchFor($outletId, $item, $line, $receipt);

                $receipt->lines()->create([
                    'stock_item_id' => $item->id,
                    'batch_id' => $batch?->id ?? 0,
                    'qty' => $line['qty'],
                    'entered_qty' => $line['entered_qty'],
                    'entered_unit' => $line['unit'],
                    'cost_rate' => $rate,
                    'net_paise' => $line['amount_paise'],
                    'tax_paise' => $lineTax,
                ]);

                $this->stock->receive($store, $item, $line['qty'], $rate, $why, $batch);

                $net += $line['amount_paise'];
                $tax += $lineTax;
            }

            $receipt->forceFill(['net_paise' => $net, 'tax_paise' => $tax, 'total_paise' => $net + $tax]);

            if ($order !== null) {
                $this->tickOff($order, $lines);
            }

            if ($supplier !== null && ($head['raise_bill'] ?? true) && $net + $tax > 0) {
                $bill = SupplierBill::allOutlets()->create([
                    'outlet_id' => $outletId,
                    'supplier_id' => $supplier->id,
                    'bill_no' => $head['bill_no'] ?? $receipt->number,
                    'billed_on' => $head['received_on'],
                    'due_on' => $head['due_on'] ?? now()->parse($head['received_on'])->addDays($supplier->terms_days)->toDateString(),
                    'total_paise' => $net + $tax,
                    'user_id' => Auth::id(),
                ]);

                $receipt->supplier_bill_id = $bill->id;
            }

            $receipt->save();

            return $receipt;
        });
    }

    /* -------------------------------------------------------------- pay */

    /**
     * Pay a supplier.
     *
     * The money settles the bill named first, if one is, and then the
     * supplier's other open bills at this outlet, oldest first. Whatever is
     * left over when nothing more is owed is kept as an advance.
     *
     * @return Collection<int, SupplierPayment> one row per bill settled
     */
    public function pay(
        string $outletId,
        Supplier $supplier,
        int $amountPaise,
        string $method,
        string $paidOn,
        ?SupplierBill $bill = null,
        ?string $reference = null,
        ?string $notes = null,
        string $kind = SupplierPayment::PAYMENT,
    ): Collection {
        if ($amountPaise <= 0) {
            throw new RuntimeException('A payment is for an amount above zero.');
        }

        if ($bill !== null && ($bill->supplier_id !== $supplier->id || $bill->outlet_id !== $outletId)) {
            throw new RuntimeException('That bill is not from this supplier at this outlet.');
        }

        return DB::transaction(function () use ($outletId, $supplier, $amountPaise, $method, $paidOn, $bill, $reference, $notes, $kind) {
            $bills = SupplierBill::allOutlets()
                ->where('outlet_id', $outletId)
                ->where('supplier_id', $supplier->id)
                ->where('status', SupplierBill::OPEN)
                ->orderBy('billed_on')->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->sortBy(fn (SupplierBill $open) => $bill !== null && $open->id === $bill->id ? 0 : 1)
                ->values();

            $written = collect();
            $left = $amountPaise;

            $record = fn (?SupplierBill $against, int $amount) => $written->push(SupplierPayment::allOutlets()->create([
                'outlet_id' => $outletId,
                'supplier_id' => $supplier->id,
                'supplier_bill_id' => $against?->id,
                'kind' => $kind,
                'amount_paise' => $amount,
                'method' => $method,
                'paid_on' => $paidOn,
                'reference' => $reference,
                'notes' => $notes,
                'user_id' => Auth::id(),
            ]));

            foreach ($bills as $open) {
                if ($left <= 0) {
                    break;
                }

                $settle = min($left, $open->duePaise());

                if ($settle <= 0) {
                    continue;
                }

                $open->paid_paise += $settle;
                $open->status = $open->duePaise() === 0 ? SupplierBill::PAID : SupplierBill::OPEN;
                $open->save();

                $record($open, $settle);
                $left -= $settle;
            }

            if ($left > 0) {
                $record(null, $left);
            }

            return $written;
        });
    }

    /**
     * What an outlet owes a supplier right now: open bills, less anything
     * paid in advance.
     */
    public function owed(string $outletId, Supplier $supplier): int
    {
        $due = (int) SupplierBill::allOutlets()
            ->where('outlet_id', $outletId)
            ->where('supplier_id', $supplier->id)
            ->where('status', SupplierBill::OPEN)
            ->selectRaw('COALESCE(SUM(total_paise - paid_paise), 0) as due')
            ->value('due');

        $advance = (int) SupplierPayment::allOutlets()
            ->where('outlet_id', $outletId)
            ->where('supplier_id', $supplier->id)
            ->whereNull('supplier_bill_id')
            ->sum('amount_paise');

        return $due - $advance;
    }

    /* ------------------------------------------------------------ return */

    /**
     * Send goods back to a supplier: the stock leaves, and what it was
     * worth comes off what is owed.
     */
    public function sendBack(string $outletId, Supplier $supplier, Store $store, StockItem $item, int $batchId, int $qty, string $returnedOn, string $reason): SupplierPayment
    {
        if (trim($reason) === '') {
            throw new RuntimeException('Say why the goods are going back.');
        }

        return DB::transaction(function () use ($outletId, $supplier, $store, $item, $batchId, $qty, $returnedOn, $reason) {
            $movement = $this->stock->takeFrom($store, $item, $batchId, $qty, new Posting(
                StockMovement::SUPPLIER_RETURN,
                reason: 'To '.$supplier->name.': '.$reason,
                businessDate: $returnedOn,
            ));

            $worth = abs($movement->value_paise);

            if ($worth === 0) {
                throw new RuntimeException('That stock has no cost on record, so there is nothing to credit.');
            }

            return $this->pay($outletId, $supplier, $worth, 'return', $returnedOn, notes: $reason, kind: SupplierPayment::CREDIT)->first();
        });
    }

    /* ---------------------------------------------------------- expenses */

    public function spend(string $outletId, string $category, int $amountPaise, string $spentOn, string $method = 'cash', ?string $note = null): Expense
    {
        if ($amountPaise <= 0) {
            throw new RuntimeException('An expense is for an amount above zero.');
        }

        return Expense::allOutlets()->create([
            'outlet_id' => $outletId,
            'category' => $category,
            'amount_paise' => $amountPaise,
            'spent_on' => $spentOn,
            'method' => $method,
            'note' => $note,
            'user_id' => Auth::id(),
        ]);
    }

    /* --------------------------------------------------------- paid-outs */

    /** Cash from the till was a payment to this supplier. */
    public function filePaidOutAsPayment(PaidOut $paidOut, Supplier $supplier, ?SupplierBill $bill = null): void
    {
        $this->file($paidOut, function () use ($paidOut, $supplier, $bill) {
            $payment = $this->pay(
                $paidOut->outlet_id, $supplier, $paidOut->amount_paise, 'drawer',
                $paidOut->business_date->toDateString(), $bill, notes: $paidOut->note,
            )->first();

            return ['supplier_payment_id' => $payment->id];
        });
    }

    /** Cash from the till was an expense. */
    public function filePaidOutAsExpense(PaidOut $paidOut, string $category): void
    {
        $this->file($paidOut, function () use ($paidOut, $category) {
            $expense = $this->spend(
                $paidOut->outlet_id, $category, $paidOut->amount_paise,
                $paidOut->business_date->toDateString(), 'drawer', $paidOut->note,
            );

            return ['expense_id' => $expense->id];
        });
    }

    /* ---------------------------------------------------------- private */

    private function file(PaidOut $paidOut, callable $as): void
    {
        DB::transaction(function () use ($paidOut, $as) {
            $paidOut = PaidOut::allOutlets()->lockForUpdate()->findOrFail($paidOut->id);

            if ($paidOut->status !== PaidOut::PENDING) {
                throw new RuntimeException('That paid-out has already been filed.');
            }

            $paidOut->forceFill($as() + ['status' => PaidOut::FILED])->save();
        });
    }

    /**
     * The batch a received line belongs to. Only items that go off have
     * one. A missing number is made from the receipt's own, and a missing
     * date from the item's shelf life - a batch with neither is still
     * better than expiry-tracked stock with no batch at all.
     */
    private function batchFor(string $outletId, StockItem $item, array $line, GoodsReceipt $receipt): ?Batch
    {
        if (! $item->track_expiry) {
            return null;
        }

        $expiry = $line['expiry'] ?? null;

        if ($expiry === null && $item->shelf_life_days) {
            $expiry = $receipt->received_on->copy()->addDays($item->shelf_life_days)->toDateString();
        }

        $batch = Batch::allOutlets()->firstOrCreate(
            ['outlet_id' => $outletId, 'stock_item_id' => $item->id, 'batch_no' => $line['batch_no'] ?? $receipt->number],
            ['expiry_date' => $expiry],
        );

        // The same batch arriving again with a date it did not have before.
        if ($batch->expiry_date === null && $expiry !== null) {
            $batch->update(['expiry_date' => $expiry]);
        }

        return $batch;
    }

    private function tickOff(PurchaseOrder $order, array $lines): void
    {
        $order->load('lines');

        foreach ($lines as $line) {
            $ordered = $order->lines->first(fn ($row) => $row->stock_item_id === $line['item']->id);

            if ($ordered !== null) {
                $ordered->received_qty += $line['qty'];
                $ordered->save();
            }
        }

        $order->status = $order->lines->every(fn ($row) => $row->outstanding() === 0)
            ? PurchaseOrder::RECEIVED
            : PurchaseOrder::PART_RECEIVED;
        $order->save();
    }
}
