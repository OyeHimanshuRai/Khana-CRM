<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Batch;
use App\Models\Customer;
use App\Models\CustomerLedger;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Shop;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Raising, and reversing, a sales invoice.
 *
 * The whole of billing happens in one transaction: number, lines, tax,
 * stock, payments and the customer's ledger. Half a sale is worse than no sale
 * - stock gone with no invoice, or an invoice with no stock movement - so there
 * is no path here that can leave one without the others.
 *
 * Rounding is decided in one place and stated once: money to 2 decimals at
 * every step, and the grand total rounded to the nearest rupee with the
 * difference kept as `round_off` so the arithmetic on the paper still adds
 * up.
 */
class InvoiceService
{
    public function __construct(
        private readonly StockService $stock,
        private readonly LedgerService $ledger,
        private readonly AlertService $alerts,
        private readonly LoyaltyService $loyalty,
    ) {}

    /**
     * Raise an invoice.
     *
     * @param  array{
     *     shop: Shop,
     *     warehouse?: Warehouse|null,
     *     customer?: Customer|null,
     *     channel?: string,
     *     invoiced_at?: \DateTimeInterface|null,
     *     items: array<int, array<string, mixed>>,
     *     payments?: array<int, array<string, mixed>>,
     *     invoice_discount?: float,
     *     invoice_discount_percent?: float,
     *     is_credit?: bool,
     *     due_date?: string|null,
     *     notes?: string|null,
     *     walk_in_name?: string|null,
     *     walk_in_mobile?: string|null,
     * }  $data
     */
    public function create(array $data): Invoice
    {
        $shop = $data['shop'];
        $customer = $data['customer'] ?? null;
        $channel = $data['channel'] ?? Invoice::POS;
        // Set for a dine-in bill, so a split shows as several invoices off
        // one sitting. Null everywhere else. See TableBillService.
        $sessionId = $data['table_session_id'] ?? null;

        $warehouse = $data['warehouse']
            ?? Warehouse::defaultFor($shop->id)
            ?? throw new RuntimeException('That shop has no warehouse to sell from.');

        if (empty($data['items'])) {
            throw new RuntimeException('An invoice needs at least one line.');
        }

        $invoice = DB::transaction(function () use ($data, $shop, $customer, $channel, $warehouse, $sessionId) {
            /*
             | Whether GST splits as CGST+SGST or as IGST is decided once,
             | here, from the two addresses as they stand today - and then
             | stored. Re-deriving it at print time would let an edited
             | address restate the tax on an invoice already filed.
             */
            $interState = $this->isInterState($shop, $customer);

            $invoice = new Invoice([
                'shop_id' => $shop->id,
                'warehouse_id' => $warehouse->id,
                'customer_id' => $customer?->id,
                'table_session_id' => $sessionId,
                'customer_name' => $customer?->name ?? ($data['walk_in_name'] ?? null),
                'customer_mobile' => $customer?->mobile ?? ($data['walk_in_mobile'] ?? null),
                'customer_gstin' => $customer?->gstin,
                'customer_state' => $customer?->state,
                'billing_address' => $customer?->addressLine(),
                'number' => $shop->nextNumber($channel === Invoice::POS ? 'pos' : 'invoice'),
                'channel' => $channel,
                'status' => Invoice::ISSUED,
                'invoiced_at' => $data['invoiced_at'] ?? now(),
                'is_inter_state' => $interState,
                'place_of_supply' => $customer?->state ?: $shop->state,
                'notes' => $data['notes'] ?? null,
            ]);

            $user = Auth::user();
            $invoice->forceFill([
                'created_by' => $user?->id,
                'created_by_name' => $user?->name ?? 'System',
            ]);

            $invoice->save();

            // 1. Work out the lines, allocating batches where needed.
            $lines = $this->buildLines($data['items'], $shop, $warehouse, $interState);

            // 2. Spread any invoice-level discount across them, so the tax
            //    on each line reflects what was actually charged for it.
            $lines = $this->applyInvoiceDiscount(
                $lines,
                (float) ($data['invoice_discount'] ?? 0),
                (float) ($data['invoice_discount_percent'] ?? 0),
                $interState,
            );

            // 3. Persist the lines and take the stock.
            $this->commitLines($invoice, $lines, $warehouse, $shop);

            // 4. Total it up.
            $this->settleTotals($invoice, $lines, (float) ($data['invoice_discount_percent'] ?? 0));

            // 5. Take the money, and put the rest on the account.
            $this->settlePayments($invoice, $customer, $data['payments'] ?? [], $shop);

            $this->settleCredit($invoice, $customer, $data);

            ActivityLog::record(
                'invoice.created',
                sprintf('Raised invoice %s for %s (₹%s)',
                    $invoice->number,
                    $invoice->billedTo(),
                    number_format((float) $invoice->grand_total, 2),
                ),
                $invoice,
            );

            /*
             | The SRS 15 "invoice generated" notification.
             |
             | Inside the transaction with everything else, so an alert can
             | never survive a bill that was rolled back - the one failure
             | mode worse than no notification is a notification about
             | something that did not happen.
             */
            $this->alerts->invoiceRaised($invoice);

            return $invoice->refresh()->load('items');
        });

        /*
         | Loyalty points (§15).
         |
         | After the transaction and outside it, deliberately. The money is
         | taken and the bill exists; a points programme that could roll a
         | settled invoice back would be the most expensive feature in this
         | system.
         |
         | LoyaltyService::award returns null rather than throwing for every
         | ordinary reason - no programme, no customer, a bill below the
         | minimum - and the catch covers the rest. Nothing here may fail a
         | sale that has already happened.
         */
        if ($invoice->customer_id !== null) {
            try {
                $member = $invoice->customer()->first();

                if ($member !== null) {
                    $this->loyalty->award($member, (float) $invoice->grand_total, $invoice);
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $invoice;
    }

    /* --------------------------------------------------------- the lines */

    /**
     * Turn the submitted rows into priced, taxed lines.
     *
     * A batch-tracked product with no batch chosen is allocated across lots
     * oldest-expiry-first, which can turn one submitted row into several
     * lines. That is correct rather than tidy: two lots bought at different
     * costs are two different things on the shelf, and the invoice has to
     * say which one the customer got.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return Collection<int, array<string, mixed>>
     */
    private function buildLines(array $items, Shop $shop, Warehouse $warehouse, bool $interState): Collection
    {
        $lines = collect();

        foreach ($items as $row) {
            $product = Product::with(['unit', 'taxRate'])->findOrFail((int) $row['product_id']);
            $quantity = (float) $row['quantity'];

            if ($quantity <= 0) {
                throw new RuntimeException("A line for \"{$product->name}\" has no quantity.");
            }

            if ($product->unit && ! $product->unit->allow_decimal) {
                $quantity = $product->unit->normalise($quantity);
            }

            $batchId = isset($row['batch_id']) && $row['batch_id'] !== '' ? (int) $row['batch_id'] : null;

            $allocations = match (true) {
                /*
                 | A dish is made, not taken off a shelf.
                 |
                 | No lot, no availability check and - below - no movement.
                 | A restaurant has no stock of Butter Naan, and refusing to
                 | bill a table because a counter says there are none would be
                 | refusing money for food that has already been eaten.
                 |
                 | See the migration, and §10's recipes, which will deduct the
                 | ingredients this dish was actually made from.
                 */
                (bool) $product->is_made_to_order => [['batch' => null, 'quantity' => $quantity]],

                $batchId !== null || ! $product->track_batches
                    => [['batch' => $batchId ? Batch::findOrFail($batchId) : null, 'quantity' => $quantity]],

                default => $this->allocate($product, $quantity, $warehouse, $shop),
            };

            foreach ($allocations as $allocation) {
                $lines->push($this->priceLine(
                    $product,
                    $allocation['batch'],
                    $allocation['quantity'],
                    $row,
                    $shop,
                    $warehouse,
                    $interState,
                ));
            }
        }

        return $lines;
    }

    /**
     * Split a quantity across lots, oldest expiry first.
     *
     * @return array<int, array{batch: Batch|null, quantity: float}>
     */
    private function allocate(Product $product, float $quantity, Warehouse $warehouse, Shop $shop): array
    {
        $plan = $this->stock->planPick($product, $quantity, $warehouse, $shop->id);
        $picked = (float) $plan->sum('quantity');

        if ($picked + 0.0005 < $quantity && ! $shop->allow_negative_stock) {
            throw new RuntimeException(sprintf(
                'Only %s of "%s" is available to sell%s.',
                rtrim(rtrim(number_format($picked, 3, '.', ''), '0'), '.') ?: '0',
                $product->name,
                $shop->block_expired_sale ? ' (expired lots excluded)' : '',
            ));
        }

        if ($plan->isEmpty()) {
            // Nothing on the shelf and the shop permits going negative:
            // sell against no lot rather than refusing the customer.
            return [['batch' => null, 'quantity' => $quantity]];
        }

        $allocations = $plan
            ->map(fn (array $row) => ['batch' => $row['batch'], 'quantity' => (float) $row['quantity']])
            ->all();

        // A shortfall the shop has allowed goes onto the last lot, so the
        // quantities still add up to what was asked for.
        $shortfall = $quantity - $picked;

        if ($shortfall > 0.0005) {
            $allocations[count($allocations) - 1]['quantity'] += $shortfall;
        }

        return $allocations;
    }

    /**
     * Price one line, tax and all.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function priceLine(
        Product $product,
        ?Batch $batch,
        float $quantity,
        array $row,
        Shop $shop,
        Warehouse $warehouse,
        bool $interState,
    ): array {
        $taxRate = $product->taxRate;
        $rate = (float) ($taxRate?->rate ?? 0);

        // What the cashier is charging per unit, before any discount.
        $entered = match (true) {
            /*
             | An explicit price wins. A negotiated figure is a real thing at a
             | counter, and refusing it would only mean the discount gets fudged
             | into the line price - which would then be wrong on the printed
             | bill and in the ledger.
             */
            array_key_exists('unit_price', $row) && $row['unit_price'] !== null && $row['unit_price'] !== ''
                => (float) $row['unit_price'],

            $batch && (float) $batch->selling_price > 0 => (float) $batch->selling_price,

            default => $product->sellingPriceFor($shop->id),
        };

        /*
         | Whether the figure above already contains GST.
         |
         | Normally the product says. The POS overrides it per line, because
         | the counter works entirely in what the customer pays: a cashier
         | typing "500" means five hundred rupees handed over, not five
         | hundred plus tax, whatever convention the catalogue uses.
         */
        $inclusive = match (true) {
            array_key_exists('price_includes_tax', $row) => (bool) $row['price_includes_tax'],
            default => (bool) $product->tax_inclusive,
        };

        /*
         | A price that already contains GST has to be stripped back to its
         | taxable part, or the tax would be charged twice - once inside the
         | price and once on top of it.
         */
        $unitExclusive = $inclusive && $rate > 0
            ? $entered / (1 + $rate / 100)
            : $entered;

        $gross = $quantity * $unitExclusive;

        $discountPercent = (float) ($row['discount_percent'] ?? 0);
        $discount = array_key_exists('discount_amount', $row) && $row['discount_amount'] !== null && $row['discount_amount'] !== ''
            ? (float) $row['discount_amount']
            : $gross * $discountPercent / 100;

        $discount = min(max($discount, 0), $gross);
        $taxable = $gross - $discount;

        $split = $taxRate
            ? $taxRate->split($taxable, $interState)
            : ['cgst' => 0.0, 'sgst' => 0.0, 'igst' => 0.0, 'cess' => 0.0];

        return [
            'product' => $product,
            'batch' => $batch,
            'quantity' => $quantity,
            'mrp' => $batch && (float) $batch->mrp > 0 ? (float) $batch->mrp : $product->mrpFor($shop->id),
            'unit_price' => $unitExclusive,
            'entered_price' => $entered,
            'discount_percent' => $discountPercent,
            'discount_amount' => $discount,
            'gross' => $gross,
            'taxable_value' => $taxable,
            'tax_rate' => $rate,
            'tax_rate_model' => $taxRate,
            'cgst' => $split['cgst'],
            'sgst' => $split['sgst'],
            'igst' => $split['igst'],
            'cess' => $split['cess'],
            'unit_cost' => $this->costOf($product, $batch, $warehouse, $shop),
            'note' => $row['note'] ?? null,
            /*
             | What the bill calls this line, when the caller knows better
             | than the product does.
             |
             | A dine-in line is "Margherita (7 inch) + Cheese burst" and its
             | price is the sum of those choices - the size and the add-ons
             | are rows in other tables, and the product's own name would
             | print a bill the guest cannot reconcile with what they ate.
             | Snapshotted like every other name on an invoice.
             */
            'name' => filled($row['name'] ?? null) ? (string) $row['name'] : null,
        ];
    }

    /**
     * What this line's goods cost the shop.
     *
     * Read from the exact slot being sold from, so a batch bought dear and a
     * batch bought cheap are costed differently on the same invoice. Falls
     * back to the product's purchase price when the slot has no cost yet -
     * without which the margin on a first sale would read as pure profit.
     */
    private function costOf(Product $product, ?Batch $batch, Warehouse $warehouse, Shop $shop): float
    {
        $cost = (float) (ProductStock::allShops()
            ->where('shop_id', $shop->id)
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->where('batch_id', $batch?->id)
            ->value('average_cost') ?? 0);

        if ($cost > 0) {
            return $cost;
        }

        $batchCost = (float) ($batch?->purchase_price ?? 0);

        return $batchCost > 0 ? $batchCost : (float) $product->purchasePriceFor($shop->id);
    }

    /**
     * Spread an invoice-level discount across the lines.
     *
     * Apportioned pro-rata by taxable value rather than deducted from the
     * total, because GST is charged per line: a bill discount that did not
     * reduce each line's taxable value would leave the tax overstated and
     * the return wrong.
     *
     * @param  Collection<int, array<string, mixed>>  $lines
     * @return Collection<int, array<string, mixed>>
     */
    private function applyInvoiceDiscount(
        Collection $lines,
        float $amount,
        float $percent,
        bool $interState,
    ): Collection {
        $subtotal = (float) $lines->sum('taxable_value');

        if ($subtotal <= 0) {
            return $lines;
        }

        if ($percent > 0) {
            $amount = $subtotal * $percent / 100;
        }

        if ($amount <= 0.004) {
            return $lines;
        }

        $amount = min($amount, $subtotal);
        $share = $amount / $subtotal;

        return $lines->map(function (array $line) use ($share, $interState) {
            $reduction = $line['taxable_value'] * $share;

            $line['invoice_discount_share'] = $reduction;
            $line['discount_amount'] += $reduction;
            $line['taxable_value'] -= $reduction;

            $split = $line['tax_rate_model']
                ? $line['tax_rate_model']->split($line['taxable_value'], $interState)
                : ['cgst' => 0.0, 'sgst' => 0.0, 'igst' => 0.0, 'cess' => 0.0];

            return [...$line, ...[
                'cgst' => $split['cgst'],
                'sgst' => $split['sgst'],
                'igst' => $split['igst'],
                'cess' => $split['cess'],
            ]];
        });
    }

    /**
     * Write the lines and take the stock.
     *
     * @param  Collection<int, array<string, mixed>>  $lines
     */
    private function commitLines(Invoice $invoice, Collection $lines, Warehouse $warehouse, Shop $shop): void
    {
        foreach ($lines as $line) {
            /** @var Product $product */
            $product = $line['product'];
            /** @var Batch|null $batch */
            $batch = $line['batch'];

            $tax = $line['cgst'] + $line['sgst'] + $line['igst'] + $line['cess'];

            $invoice->items()->create([
                'product_id' => $product->id,
                'batch_id' => $batch?->id,
                'product_name' => $line['name'] ?? $product->name,
                'sku' => $product->sku,
                'hsn_code' => $product->hsn_code,
                'unit_code' => $product->unit?->code,
                'batch_no' => $batch?->batch_no,
                'expiry_date' => $batch?->expiry_date,
                'quantity' => $line['quantity'],
                'mrp' => $line['mrp'],
                'unit_price' => $line['unit_price'],
                'discount_percent' => $line['discount_percent'],
                'discount_amount' => round($line['discount_amount'], 2),
                'taxable_value' => round($line['taxable_value'], 2),
                'tax_rate' => $line['tax_rate'],
                'cgst_amount' => round($line['cgst'], 2),
                'sgst_amount' => round($line['sgst'], 2),
                'igst_amount' => round($line['igst'], 2),
                'cess_amount' => round($line['cess'], 2),
                'line_total' => round($line['taxable_value'] + $tax, 2),
                'unit_cost' => $line['unit_cost'],
            ]);

            // Nothing moves for a dish: there was never a count of it to
            // move. See buildLines().
            if (! $product->is_made_to_order) {
                $this->stock->issue(
                    $product,
                    $line['quantity'],
                    $warehouse,
                    $batch,
                    StockMovement::SALE,
                    $invoice,
                    'Invoice '.$invoice->number,
                    $shop->id,
                );
            }
        }
    }

    /**
     * Add the invoice up.
     *
     * @param  Collection<int, array<string, mixed>>  $lines
     */
    private function settleTotals(Invoice $invoice, Collection $lines, float $discountPercent): void
    {
        $subtotal = round((float) $lines->sum('taxable_value'), 2);

        $lineDiscount = round((float) $lines->sum(
            fn (array $l) => $l['discount_amount'] - ($l['invoice_discount_share'] ?? 0)
        ), 2);

        $invoiceDiscount = round((float) $lines->sum(fn (array $l) => $l['invoice_discount_share'] ?? 0), 2);

        $cgst = round((float) $lines->sum('cgst'), 2);
        $sgst = round((float) $lines->sum('sgst'), 2);
        $igst = round((float) $lines->sum('igst'), 2);
        $cess = round((float) $lines->sum('cess'), 2);
        $tax = round($cgst + $sgst + $igst + $cess, 2);

        $raw = round($subtotal + $tax, 2);

        // Rounded to the nearest rupee, with the difference shown - so the
        // customer can see why the total is not the sum of the lines.
        $grand = round($raw);
        $roundOff = round($grand - $raw, 2);

        $invoice->forceFill([
            'subtotal' => $subtotal,
            'line_discount_total' => $lineDiscount,
            'invoice_discount' => $invoiceDiscount,
            'invoice_discount_percent' => $discountPercent,
            'cgst_total' => $cgst,
            'sgst_total' => $sgst,
            'igst_total' => $igst,
            'cess_total' => $cess,
            'tax_total' => $tax,
            'round_off' => $roundOff,
            'grand_total' => $grand,
            'due_total' => $grand,
            'cost_total' => round((float) $lines->sum(
                fn (array $l) => $l['quantity'] * $l['unit_cost']
            ), 4),
        ])->save();
    }

    /**
     * Record what was tendered.
     *
     * @param  array<int, array<string, mixed>>  $payments
     */
    private function settlePayments(Invoice $invoice, ?Customer $customer, array $payments, Shop $shop): void
    {
        $paid = 0.0;

        foreach ($payments as $row) {
            $amount = round((float) ($row['amount'] ?? 0), 2);

            if ($amount <= 0) {
                continue;
            }

            $method = $row['method'] ?? Payment::CASH;

            $payment = Payment::query()->create([
                'shop_id' => $shop->id,
                'number' => Payment::nextNumber($shop, Payment::IN),
                'direction' => Payment::IN,
                'method' => $method,
                'party_type' => $customer ? $customer::class : null,
                'party_id' => $customer?->id,
                'party_name' => $invoice->billedTo(),
                'reference_type' => $invoice::class,
                'reference_id' => $invoice->id,
                'amount' => $amount,
                'paid_at' => $invoice->invoiced_at,
                'transaction_ref' => $row['transaction_ref'] ?? null,
                'bank_name' => $row['bank_name'] ?? null,
                'cheque_date' => $row['cheque_date'] ?? null,
                'status' => Payment::initialStatus($method),
            ]);

            $user = Auth::user();
            $payment->forceFill([
                'created_by' => $user?->id,
                'created_by_name' => $user?->name ?? 'System',
            ])->save();

            // A cheque has not paid anything until it clears, so it does not
            // reduce the amount due yet.
            if ($payment->isEffective()) {
                $paid += $amount;
            }
        }

        $paid = round($paid, 2);
        $due = round((float) $invoice->grand_total - $paid, 2);

        $invoice->forceFill([
            'paid_total' => $paid,
            'due_total' => max(0, $due),
            'status' => $invoice->statusForPaid($paid),
        ])->save();
    }

    /**
     * Put anything unpaid on the customer's account.
     *
     * @param  array<string, mixed>  $data
     */
    private function settleCredit(Invoice $invoice, ?Customer $customer, array $data): void
    {
        $due = (float) $invoice->due_total;

        if ($due <= 0.004) {
            // Fully paid, but a named customer's account should still show
            // the sale and the payment against it.
            if ($customer) {
                $this->postToLedger($invoice, $customer);
            }

            return;
        }

        if (! $customer) {
            throw new RuntimeException(
                'A part-paid or credit sale needs a named customer — a walk-in has no account to owe on.'
            );
        }

        if (! $customer->allow_credit) {
            throw new RuntimeException(sprintf(
                '%s is a cash-only customer. Take the full ₹%s, or enable credit on their account first.',
                $customer->name,
                number_format((float) $invoice->grand_total, 2),
            ));
        }

        if (! $customer->canTakeCredit($due)) {
            throw new RuntimeException(sprintf(
                '%s has ₹%s of credit left and this would add ₹%s. Take a larger payment, or raise their limit.',
                $customer->name,
                number_format($customer->availableCredit(), 2),
                number_format($due, 2),
            ));
        }

        $days = (int) ($customer->credit_days ?? 0);

        $invoice->forceFill([
            'is_credit' => true,
            'due_date' => $data['due_date'] ?? $invoice->invoiced_at->copy()->addDays($days)->toDateString(),
        ])->save();

        $this->postToLedger($invoice, $customer);
    }

    /**
     * Post the sale, and any payment taken with it, to the account.
     */
    private function postToLedger(Invoice $invoice, Customer $customer): void
    {
        $this->ledger->debit(
            $customer,
            (float) $invoice->grand_total,
            CustomerLedger::INVOICE,
            'Invoice '.$invoice->number,
            $invoice,
            $invoice->invoiced_at,
        );

        $paid = (float) $invoice->paid_total;

        if ($paid > 0.004) {
            $this->ledger->credit(
                $customer,
                $paid,
                CustomerLedger::PAYMENT,
                'Paid against '.$invoice->number,
                $invoice,
                $invoice->invoiced_at,
            );
        }
    }

    /* ------------------------------------------------------------ cancel */

    /**
     * Cancel an invoice: put the stock back and reverse the account.
     *
     * Cancellation rather than deletion, always. The number stays in the
     * series, the document stays readable, and the reversal is visible on
     * both the stock ledger and the customer's statement - which is what
     * the SRS means by "replaced by cancellation/reversal to preserve audit
     * history".
     */
    public function cancel(Invoice $invoice, string $reason): Invoice
    {
        if ($invoice->isCancelled()) {
            throw new RuntimeException('That invoice is already cancelled.');
        }

        if (trim($reason) === '') {
            throw new RuntimeException('Cancelling an invoice needs a reason.');
        }

        return DB::transaction(function () use ($invoice, $reason) {
            $shop = Shop::withTrashed()->findOrFail($invoice->shop_id);
            $warehouse = $invoice->warehouse;

            foreach ($invoice->items as $item) {
                if ($item->product === null || $warehouse === null) {
                    continue;
                }

                $this->stock->receive(
                    $item->product,
                    (float) $item->quantity,
                    $warehouse,
                    $item->batch,
                    (float) $item->unit_cost,
                    StockMovement::SALE_RETURN,
                    $invoice,
                    'Cancelled invoice '.$invoice->number,
                    $shop->id,
                );
            }

            $customer = $invoice->customer;

            if ($customer) {
                // One net entry rather than mirroring each original: the
                // statement should read "invoice cancelled", not replay the
                // whole sale backwards.
                $net = (float) $invoice->grand_total - (float) $invoice->paid_total;

                if (abs($net) > 0.004) {
                    $this->ledger->credit(
                        $customer,
                        $net,
                        CustomerLedger::ADJUSTMENT,
                        'Cancelled invoice '.$invoice->number.' — '.$reason,
                        $invoice,
                    );
                }
            }

            // Payments taken against it are voided, not deleted: the till
            // still has to be able to show that money came in and went back.
            $invoice->payments()->where('status', '!=', Payment::CANCELLED)
                ->each(fn (Payment $payment) => $payment->forceFill([
                    'status' => Payment::CANCELLED,
                    'notes' => trim(($payment->notes ? $payment->notes."\n" : '')
                        .'Voided with invoice '.$invoice->number),
                ])->save());

            $user = Auth::user();

            $invoice->forceFill([
                'status' => Invoice::CANCELLED,
                'due_total' => 0,
                'cancelled_by' => $user?->id,
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
            ])->save();

            ActivityLog::record(
                'invoice.cancelled',
                "Cancelled invoice {$invoice->number}: {$reason}",
                $invoice,
                ['grand_total' => (float) $invoice->grand_total],
            );

            return $invoice->refresh();
        });
    }

    /* ----------------------------------------------------------- helpers */

    /**
     * Whether this sale crosses a state line for GST.
     *
     * Unknown addresses count as intra-state, which is the right default for
     * a counter sale: the customer standing in the shop is in the shop's
     * state unless they say otherwise.
     */
    private function isInterState(Shop $shop, ?Customer $customer): bool
    {
        $shopState = trim((string) $shop->state);
        $customerState = trim((string) $customer?->state);

        if ($shopState === '' || $customerState === '') {
            return false;
        }

        return strcasecmp($shopState, $customerState) !== 0;
    }
}
