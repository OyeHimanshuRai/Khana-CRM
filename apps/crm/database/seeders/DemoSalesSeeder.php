<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SalesReturn;
use App\Models\SalesReturnItem;
use App\Models\Warehouse;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use App\Services\SalesReturnService;
use App\Services\StockService;
use App\Support\CurrentShop;
use Database\Seeders\Concerns\SeedsDemoData;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * The selling side: invoices, the money against them, and what came back.
 *
 * More than twenty invoices, deliberately. The dashboard reads sales by day -
 * today, yesterday, a seven-day sparkline, month to date - so twenty rows
 * dropped anywhere in the last two months would leave most of those tiles
 * reading zero and the trend line flat. Sixty spread across sixty days,
 * including today, is the smallest number that makes every one of them say
 * something true.
 *
 * Billed through InvoiceService, which is what takes the stock, splits the
 * GST, numbers the invoice and posts to the customer's ledger. The mix of
 * settlements is chosen so each downstream screen has work on it: cash sales
 * that are done with, credit sales that are not, cheques that have not
 * cleared, and a tail of overdue accounts for the dues and reminder modules.
 */
class DemoSalesSeeder extends Seeder
{
    use SeedsDemoData;

    /** Enough days of trading for the dashboard's week and month tiles. */
    private const INVOICES = 60;

    private const WINDOW_DAYS = 60;

    /** Marks a lump sum taken later, as opposed to a tender at the till. */
    private const COLLECTION_NOTE = 'Collected at the counter against the account.';

    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly PaymentService $payments,
        private readonly SalesReturnService $returns,
        private readonly StockService $stock,
    ) {}

    public function run(): void
    {
        $this->seedRandom(4);

        $this->invoices();
        $this->collections();
        $this->salesReturns();
    }

    /**
     * Every invoice is raised on account and then settled.
     *
     * Not because every sale is a credit sale, but because InvoiceService
     * needs the tender named in rupees up front - the till knows the total
     * before it takes the money, and a seeder does not. Raising it unpaid
     * and collecting against it a moment later is the same two facts in the
     * order a seeder can actually produce them, and it goes through
     * PaymentService, so the receipt, the invoice status and the ledger all
     * move together.
     *
     * The cost is that walk-in sales are not seeded: a walk-in has no
     * account to owe on for the moment between the two steps. The counter
     * still takes them - this is a limit of seeding, not of the till.
     */
    private function invoices(): void
    {
        if ($this->alreadySeeded('Invoices', Invoice::query()->count(), self::INVOICES)) {
            return;
        }

        $shop = CurrentShop::get();
        $warehouse = Warehouse::defaultFor($shop->id);
        $customers = Customer::query()
            ->where('is_active', true)
            ->where('allow_credit', true)
            ->get();

        $raised = 0;
        $skipped = 0;

        for ($i = 0; $i < self::INVOICES; $i++) {
            /*
             | Spread across the window with today and yesterday guaranteed a
             | few each, because "today's sales" is the first number anyone
             | looks at and an empty one reads as a broken dashboard rather
             | than a quiet morning.
             */
            $day = match (true) {
                $i < 4 => Carbon::today(),
                $i < 8 => Carbon::today()->subDay(),
                $i < 20 => Carbon::today()->subDays($this->between(2, 6)),
                default => Carbon::today()->subDays($this->between(0, self::WINDOW_DAYS)),
            };

            $items = $this->basket($warehouse);

            if ($items === []) {
                $skipped++;

                continue;
            }

            $customer = $this->buyer($customers);

            if (! $customer) {
                $skipped++;

                continue;
            }

            $terms = (int) ($customer->credit_days ?: 15);

            $data = [
                'shop' => $shop,
                'warehouse' => $warehouse,
                'customer' => $customer,
                'channel' => $this->chance(75) ? Invoice::POS : Invoice::MANUAL,
                'invoiced_at' => $this->tradingHour($day),
                'items' => $items,
                'is_credit' => true,
                /*
                 | The due date is what makes an account overdue, and the dues
                 | and reminder modules have nothing to work on without a
                 | spread of them. A third are dated to have already fallen
                 | due.
                 */
                'due_date' => $this->chance(33)
                    ? $day->copy()->subDays($this->between(1, 25))->toDateString()
                    : $day->copy()->addDays($terms)->toDateString(),
                'notes' => $this->chance(15) ? 'Delivered to the field.' : null,
            ];

            if ($this->chance(25)) {
                $data['invoice_discount_percent'] = $this->pick([2, 3, 5]);
            }

            try {
                $invoice = $this->invoices->create($data);
            } catch (RuntimeException) {
                // Refused: either the basket outran the shelf between the
                // plan and the pick, or this customer is at their ceiling.
                // Both are the guards working; neither is worth forcing.
                $skipped++;

                continue;
            }

            $this->tender($invoice, $customer, $day);
            $this->maybeCancel($invoice, $i);

            $raised++;
        }

        $this->say(sprintf('%d invoices raised, %d not (stock or credit limit).', $raised, $skipped));
    }

    /**
     * A customer with room on their account for one more sale.
     *
     * Tried a few at random rather than always taking whoever has the most
     * headroom, so the sales spread across the book instead of concentrating
     * on one wealthy farmer.
     *
     * @param  \Illuminate\Support\Collection<int, Customer>  $customers
     */
    private function buyer($customers): ?Customer
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $candidate = $customers->random();

            if ($candidate->fresh()->availableCredit() > 15000) {
                return $candidate->fresh();
            }
        }

        // Everyone tried is close to their limit. Fall back to whoever has
        // the most room left; if nobody has any, the sale genuinely cannot
        // be made on account and the caller skips it.
        $best = $customers
            ->map(fn (Customer $c) => $c->fresh())
            ->sortByDesc(fn (Customer $c) => $c->availableCredit())
            ->first();

        return $best?->availableCredit() > 0 ? $best : null;
    }

    /**
     * Take the money, now that the invoice knows what it is worth.
     *
     * Through PaymentService and allocated to this invoice explicitly, so
     * the receipt, the invoice's paid/partial status and the customer's
     * ledger all move in one transaction - which is the difference between
     * a demo that survives opening the ledger screen and one that does not.
     */
    private function tender(Invoice $invoice, Customer $customer, Carbon $day): void
    {
        $total = (float) $invoice->grand_total;

        if ($total <= 0) {
            return;
        }

        /*
         | A quarter are left wholly on account, which is what fills the dues,
         | ageing and reminder screens. Of the rest, most settle in full at
         | the counter and some part-pay - the three invoice statuses the list
         | screen filters on.
         */
        $share = match (true) {
            $this->chance(25) => 0.0,
            $this->chance(27) => $this->money(0.25, 0.7),
            default => 1.0,
        };

        if ($share <= 0) {
            return;
        }

        $amount = round($total * $share, 2);

        if ($amount < 1) {
            return;
        }

        $method = $this->pick([
            Payment::CASH, Payment::CASH, Payment::CASH,
            Payment::UPI, Payment::UPI,
            Payment::CARD, Payment::BANK, Payment::CHEQUE,
        ]);

        $this->payments->collect($customer, $amount, $method, [
            'paid_at' => $this->tradingHour($day),
            'transaction_ref' => $method === Payment::CASH ? null : 'REF'.$this->between(100000, 999999),
            'bank_name' => $method === Payment::CASH ? null : $this->pick(['State Bank of India', 'HDFC Bank', 'ICICI Bank']),
            'cheque_date' => $method === Payment::CHEQUE
                ? $day->copy()->addDays($this->between(5, 20))->toDateString()
                : null,
        ], [$invoice->id => $amount]);
    }

    /**
     * A basket of lines the shelf can actually cover.
     *
     * "In stock" is asked of StockService::planPick rather than worked out
     * from a SUM here, because the two answers differ in exactly the ways
     * that matter: the picker is confined to the warehouse being sold from,
     * and it refuses expired lots when the shop says to. A basket built from
     * the raw total would be accepted here and then thrown out by
     * InvoiceService, losing the sale for a reason no report would explain.
     *
     * @return array<int, array<string, mixed>>
     */
    private function basket(Warehouse $warehouse): array
    {
        $products = Product::query()
            ->active()
            ->whereHas('stocks', fn ($q) => $q->where('quantity', '>', 0))
            ->inRandomOrder()
            ->take($this->between(1, 5))
            ->get();

        $items = [];

        foreach ($products as $product) {
            $wanted = $this->between(1, 8);

            $sellable = (float) $this->stock
                ->planPick($product, $wanted, $warehouse, CurrentShop::id())
                ->sum('quantity');

            // Leave something on the shelf: a seeder that sells a product
            // down to nothing every time leaves the reorder and out-of-stock
            // screens saying the same thing about all twenty.
            $quantity = (int) floor(min($wanted, $sellable));

            if ($quantity < 1) {
                continue;
            }

            $items[] = [
                'product_id' => $product->id,
                'quantity' => $quantity,
            ];
        }

        return $items;
    }

    /**
     * Cancel a couple, so the cancelled state is reachable on the list.
     *
     * Through the service, so the stock goes back and the ledger entry is
     * reversed rather than the row simply being restamped.
     */
    private function maybeCancel(Invoice $invoice, int $index): void
    {
        if ($index % 23 !== 22) {
            return;
        }

        $this->invoices->cancel($invoice, $this->pick([
            'Billed to the wrong account.',
            'Customer changed their mind at the counter.',
        ]));
    }

    /**
     * Money taken after the sale, against the accounts that still owe.
     *
     * Distinct from the payments recorded at billing time: this is the
     * Payments module's own reason to exist - a customer walking in with a
     * lump sum days later, allocated oldest invoice first by the service.
     */
    private function collections(): void
    {
        /*
         | Counted by the note, not by "has no invoice reference": the
         | payments taken at billing time are collections too - they go
         | through the same service - so that test would see them and decide
         | this step had already run.
         */
        $existing = Payment::query()->where('notes', self::COLLECTION_NOTE)->count();

        if ($this->alreadySeeded('Collections', $existing)) {
            return;
        }

        $owing = Customer::query()
            ->where('balance', '>', 0)
            ->orderByDesc('balance')
            ->take(self::PER_MODULE)
            ->get();

        $taken = 0;

        foreach ($owing as $customer) {
            $balance = (float) $customer->balance;

            if ($balance < 100) {
                continue;
            }

            // Part payments, mostly - a customer who clears the whole book
            // every time leaves the ageing report with nothing to age.
            $amount = $this->chance(30)
                ? round($balance, 2)
                : round($balance * $this->money(0.2, 0.7), 2);

            /*
             | The first two are cheques, not left to chance: a cheque is the
             | only payment that sits at "pending" until it clears, and both
             | the dashboard's waiting-to-clear alert and the clear/bounce
             | actions on the payment screen have nothing to act on without
             | one. Everything after is drawn from the usual mix.
             */
            $method = $taken < 2 ? Payment::CHEQUE : $this->pick([
                Payment::CASH, Payment::CASH, Payment::UPI, Payment::UPI,
                Payment::BANK, Payment::CHEQUE,
            ]);

            $this->payments->collect($customer, $amount, $method, [
                'paid_at' => $this->recentDate(30),
                'transaction_ref' => $method === Payment::CASH ? null : 'TXN'.$this->between(1000000, 9999999),
                'bank_name' => $method === Payment::CASH ? null : $this->pick(['State Bank of India', 'HDFC Bank', 'Axis Bank']),
                'cheque_date' => $method === Payment::CHEQUE
                    ? Carbon::today()->addDays($this->between(3, 20))->toDateString()
                    : null,
                'notes' => self::COLLECTION_NOTE,
            ]);

            $taken++;
        }

        $this->say(sprintf('%d collections.', $taken));
    }

    /**
     * Goods brought back.
     *
     * Approved through the service so the stock returns to the shelf and the
     * customer is credited or refunded - the settlement is what the dues
     * screen then has to account for.
     */
    private function salesReturns(): void
    {
        if ($this->alreadySeeded('Sales returns', SalesReturn::query()->count(), 8)) {
            return;
        }

        $shop = CurrentShop::get();
        $user = auth()->user();

        $invoices = Invoice::query()
            ->whereNotIn('status', [Invoice::CANCELLED, Invoice::DRAFT])
            ->whereNotNull('customer_id')
            ->with('items')
            ->latest('invoiced_at')
            ->take(8)
            ->get();

        $approved = 0;

        foreach ($invoices as $index => $invoice) {
            $line = $invoice->items->first();

            if (! $line) {
                continue;
            }

            $quantity = max(1, (int) floor((float) $line->quantity / 2));

            $return = SalesReturn::query()->create([
                'shop_id' => $shop->id,
                'warehouse_id' => $invoice->warehouse_id,
                'invoice_id' => $invoice->id,
                'customer_id' => $invoice->customer_id,
                'customer_name' => $invoice->customer_name,
                'reference' => SalesReturn::nextReference($shop),
                'returned_on' => $invoice->invoiced_at->copy()->addDays($this->between(1, 12)),
                'status' => SalesReturn::DRAFT,
                'reason_code' => $this->pickKey(SalesReturn::REASONS),
                'reason' => 'Brought back against '.$invoice->number.'.',
                'settlement' => $this->pick([SalesReturn::CREDIT, SalesReturn::REFUND]),
                'created_by' => $user?->id,
                'created_by_name' => $user?->name ?? 'System',
            ]);

            $taxable = round($quantity * (float) $line->unit_price, 2);
            $rate = (float) $line->tax_rate;
            $tax = round($taxable * $rate / 100, 2);

            SalesReturnItem::query()->create([
                'sales_return_id' => $return->id,
                'invoice_item_id' => $line->id,
                'product_id' => $line->product_id,
                'batch_id' => $line->batch_id,
                'product_name' => $line->product_name,
                'sku' => $line->sku,
                'unit_code' => $line->unit_code,
                'batch_no' => $line->batch_no,
                'quantity' => $quantity,
                'condition' => $this->pick(['resaleable', 'damaged']),
                'unit_price' => $line->unit_price,
                'taxable_value' => $taxable,
                'tax_rate' => $rate,
                'tax_amount' => $tax,
                'line_total' => round($taxable + $tax, 2),
                'unit_cost' => $line->unit_cost,
            ]);

            $return->forceFill([
                'subtotal' => $taxable,
                'tax_total' => $tax,
                'grand_total' => round($taxable + $tax, 2),
                'cost_total' => round($quantity * (float) $line->unit_cost, 2),
            ])->save();

            // Half left pending, so the approvals screen has a queue.
            if ($index % 2 === 0) {
                $this->returns->approve($return, 'Checked at the counter, stock back on the shelf.');
                $approved++;
            }
        }

        $this->say(sprintf('%d sales returns (%d approved).', $invoices->count(), $approved));
    }
}
