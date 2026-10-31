<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\Customer;
use App\Models\CustomerLedger;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Shop;
use App\Models\StockMovement;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\AlertService;
use App\Services\InvoiceService;
use App\Services\LedgerService;
use App\Services\StockService;
use App\Support\CurrentShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Billing.
 *
 * The invariants here are the ones a shop is audited on:
 *
 *   - what the customer was charged equals lines + tax - discount, rounded
 *     once and shown
 *   - GST splits the right way for the place of supply
 *   - an invoice-level discount reduces each line's taxable value, so the
 *     tax on the return matches the tax on the paper
 *   - stock leaves exactly when an invoice is raised, and comes back exactly
 *     when it is cancelled
 *   - credit is refused when it should be, before anything else happens
 */
class InvoiceServiceTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private Warehouse $warehouse;

    private StockService $stock;

    private InvoiceService $invoices;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        CurrentShop::forget();

        $this->shop = Shop::firstOrFail();
        $this->shop->forceFill(['state' => 'Maharashtra', 'state_code' => '27'])->save();

        $this->warehouse = Warehouse::allShops()->where('shop_id', $this->shop->id)->firstOrFail();

        $this->stock = new StockService();
        /*
         | Built through the container rather than by hand.
         |
         | Constructed literally, this breaks every time the service gains a
         | dependency - which it has, twice - and the failure lands on four
         | test files that have nothing to do with the change. The one thing
         | these tests DO care about is holding on to the same StockService,
         | so that is bound explicitly and the rest is resolved.
         */
        $this->app->instance(StockService::class, $this->stock);
        $this->invoices = $this->app->make(InvoiceService::class);
    }

    protected function tearDown(): void
    {
        CurrentShop::forget();

        parent::tearDown();
    }

    /* ------------------------------------------------------------ fixtures */

    /** A product priced tax-inclusive at 18%, with stock on the shelf. */
    private function product(array $attributes = [], float $stock = 100, float $cost = 200): Product
    {
        $unit = Unit::where('code', 'PCS')->firstOrFail();
        $gst18 = TaxRate::where('name', 'GST 18%')->firstOrFail();

        static $counter = 0;
        $counter++;

        $product = Product::create([
            'name' => 'Test Product '.$counter,
            'slug' => Product::uniqueSlug('Test Product '.$counter),
            'sku' => Product::generateSku('TEST'.$counter),
            'unit_id' => $unit->id,
            'tax_rate_id' => $gst18->id,
            'purchase_price' => $cost,
            'mrp' => 300,
            'selling_price' => 236,
            'tax_inclusive' => true,
            ...$attributes,
        ]);

        if ($stock > 0) {
            $this->stock->receive(
                $product, $stock, $this->warehouse, null, $cost,
                StockMovement::PURCHASE, null, null, $this->shop->id
            );
        }

        return $product;
    }

    private function customer(array $attributes = []): Customer
    {
        static $counter = 0;
        $counter++;

        $customer = new Customer([
            'shop_id' => $this->shop->id,
            'name' => 'Test Customer '.$counter,
            'mobile' => '90000000'.str_pad((string) $counter, 2, '0', STR_PAD_LEFT),
            'type' => 'farmer',
            'state' => 'Maharashtra',
            ...$attributes,
        ]);

        $customer->saveQuietly();

        return $customer;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $extra
     */
    private function bill(array $items, array $extra = []): Invoice
    {
        return $this->invoices->create([
            'shop' => $this->shop,
            'warehouse' => $this->warehouse,
            'items' => $items,
            ...$extra,
        ]);
    }

    /* --------------------------------------------------------------- tax */

    public function test_a_tax_inclusive_price_is_split_not_added_to(): void
    {
        // â‚¹236 including 18% is â‚¹200 taxable + â‚¹36 tax. Charging tax on top
        // would bill â‚¹278.48 for something the shelf says costs â‚¹236.
        $product = $this->product();

        $invoice = $this->bill([
            ['product_id' => $product->id, 'quantity' => 1],
        ], ['payments' => [['method' => 'cash', 'amount' => 236]]]);

        $this->assertSame(200.00, (float) $invoice->subtotal);
        $this->assertSame(36.00, (float) $invoice->tax_total);
        $this->assertSame(236.00, (float) $invoice->grand_total);
    }

    public function test_a_tax_exclusive_price_has_tax_added(): void
    {
        $product = $this->product(['tax_inclusive' => false, 'selling_price' => 200]);

        $invoice = $this->bill([
            ['product_id' => $product->id, 'quantity' => 1],
        ], ['payments' => [['method' => 'cash', 'amount' => 236]]]);

        $this->assertSame(200.00, (float) $invoice->subtotal);
        $this->assertSame(36.00, (float) $invoice->tax_total);
        $this->assertSame(236.00, (float) $invoice->grand_total);
    }

    public function test_an_intra_state_sale_splits_into_cgst_and_sgst(): void
    {
        $product = $this->product();
        $customer = $this->customer(['state' => 'Maharashtra']);

        $invoice = $this->bill(
            [['product_id' => $product->id, 'quantity' => 1]],
            ['customer' => $customer, 'payments' => [['method' => 'cash', 'amount' => 236]]],
        );

        $this->assertFalse($invoice->is_inter_state);
        $this->assertSame(18.00, (float) $invoice->cgst_total);
        $this->assertSame(18.00, (float) $invoice->sgst_total);
        $this->assertSame(0.00, (float) $invoice->igst_total);
    }

    public function test_an_inter_state_sale_charges_igst_instead(): void
    {
        $product = $this->product();
        $customer = $this->customer(['state' => 'Gujarat']);

        $invoice = $this->bill(
            [['product_id' => $product->id, 'quantity' => 1]],
            ['customer' => $customer, 'payments' => [['method' => 'cash', 'amount' => 236]]],
        );

        $this->assertTrue($invoice->is_inter_state);
        $this->assertSame(0.00, (float) $invoice->cgst_total);
        $this->assertSame(0.00, (float) $invoice->sgst_total);
        $this->assertSame(36.00, (float) $invoice->igst_total);
        $this->assertSame(236.00, (float) $invoice->grand_total);
    }

    public function test_a_walk_in_with_no_address_is_treated_as_intra_state(): void
    {
        // The customer standing at the counter is in the shop's state unless
        // they say otherwise.
        $product = $this->product();

        $invoice = $this->bill(
            [['product_id' => $product->id, 'quantity' => 1]],
            ['payments' => [['method' => 'cash', 'amount' => 236]]],
        );

        $this->assertFalse($invoice->is_inter_state);
        $this->assertSame(18.00, (float) $invoice->cgst_total);
    }

    /* ---------------------------------------------------------- discounts */

    public function test_a_line_discount_reduces_the_taxable_value_and_the_tax(): void
    {
        $product = $this->product();

        // 10 x â‚¹200 taxable = â‚¹2000, less 10% = â‚¹1800, tax â‚¹324.
        $invoice = $this->bill([
            ['product_id' => $product->id, 'quantity' => 10, 'discount_percent' => 10],
        ], ['payments' => [['method' => 'cash', 'amount' => 2124]]]);

        $this->assertSame(1800.00, (float) $invoice->subtotal);
        $this->assertSame(200.00, (float) $invoice->line_discount_total);
        $this->assertSame(324.00, (float) $invoice->tax_total);
        $this->assertSame(2124.00, (float) $invoice->grand_total);
    }

    public function test_an_invoice_discount_is_apportioned_so_the_tax_stays_correct(): void
    {
        /*
         | This is the one that goes wrong in most systems: a bill discount
         | subtracted from the total leaves the tax overstated, and the GST
         | return then disagrees with the invoice. Apportioning it across the
         | lines keeps both right.
         */
        $product = $this->product();

        // 10 x â‚¹200 = â‚¹2000 taxable, less a â‚¹200 bill discount = â‚¹1800.
        $invoice = $this->bill([
            ['product_id' => $product->id, 'quantity' => 10],
        ], ['invoice_discount' => 200, 'payments' => [['method' => 'cash', 'amount' => 2124]]]);

        $this->assertSame(1800.00, (float) $invoice->subtotal);
        $this->assertSame(200.00, (float) $invoice->invoice_discount);
        $this->assertSame(0.00, (float) $invoice->line_discount_total);
        // Tax on â‚¹1800, not on â‚¹2000.
        $this->assertSame(324.00, (float) $invoice->tax_total);
        $this->assertSame(2124.00, (float) $invoice->grand_total);

        $line = $invoice->items->first();
        $this->assertSame(1800.00, (float) $line->taxable_value);
        $this->assertSame(324.00, $line->taxAmount());
    }

    public function test_an_invoice_discount_spreads_across_lines_in_proportion(): void
    {
        $cheap = $this->product();
        $dear = $this->product();

        // Taxable â‚¹200 and â‚¹600; a â‚¹80 discount should split â‚¹20 / â‚¹60.
        $invoice = $this->bill([
            ['product_id' => $cheap->id, 'quantity' => 1],
            ['product_id' => $dear->id, 'quantity' => 3],
            // â‚¹720 taxable + â‚¹129.60 tax = â‚¹849.60, billed as â‚¹850.
        ], ['invoice_discount' => 80, 'payments' => [['method' => 'cash', 'amount' => 850]]]);

        $lines = $invoice->items->keyBy('product_id');

        $this->assertSame(180.00, (float) $lines[$cheap->id]->taxable_value);
        $this->assertSame(540.00, (float) $lines[$dear->id]->taxable_value);
        $this->assertSame(720.00, (float) $invoice->subtotal);
    }

    public function test_the_grand_total_is_rounded_once_and_the_difference_shown(): void
    {
        $product = $this->product(['tax_inclusive' => false, 'selling_price' => 99.99]);

        // â‚¹99.99 + 18% = â‚¹117.99 (well, 117.9882), which bills as â‚¹118.
        $invoice = $this->bill([
            ['product_id' => $product->id, 'quantity' => 1],
        ], ['payments' => [['method' => 'cash', 'amount' => 118]]]);

        $this->assertSame(118.00, (float) $invoice->grand_total);
        $this->assertSame(
            round((float) $invoice->grand_total, 2),
            round((float) $invoice->subtotal + (float) $invoice->tax_total + (float) $invoice->round_off, 2),
        );
    }

    /* -------------------------------------------------------------- stock */

    public function test_raising_an_invoice_takes_the_stock(): void
    {
        $product = $this->product(stock: 50);

        $this->bill([
            ['product_id' => $product->id, 'quantity' => 12],
        ], ['payments' => [['method' => 'cash', 'amount' => 2832]]]);

        $this->assertSame(38.0, $product->stockOnHand($this->shop->id));

        $movement = StockMovement::allShops()
            ->where('product_id', $product->id)
            ->where('type', StockMovement::SALE)
            ->sole();

        $this->assertSame(-12.0, (float) $movement->quantity);
    }

    public function test_selling_more_than_is_on_the_shelf_is_refused(): void
    {
        $product = $this->product(stock: 5);

        $this->expectException(RuntimeException::class);

        $this->bill([
            ['product_id' => $product->id, 'quantity' => 9],
        ], ['payments' => [['method' => 'cash', 'amount' => 2124]]]);
    }

    public function test_a_refused_sale_leaves_no_invoice_and_no_stock_movement(): void
    {
        // The whole sale is one transaction: a line that cannot be filled
        // must not leave a numbered invoice behind with nothing on it.
        $product = $this->product(stock: 5);

        try {
            $this->bill([
                ['product_id' => $product->id, 'quantity' => 9],
            ], ['payments' => [['method' => 'cash', 'amount' => 2124]]]);
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(0, Invoice::allShops()->count());
        $this->assertSame(5.0, $product->stockOnHand($this->shop->id));
        $this->assertSame(0, Payment::allShops()->count());
    }

    public function test_a_batch_tracked_product_is_sold_oldest_expiry_first(): void
    {
        $unit = Unit::where('code', 'PCS')->firstOrFail();

        $product = Product::create([
            'name' => 'Batched Product',
            'slug' => Product::uniqueSlug('Batched Product'),
            'sku' => Product::generateSku('BATCH'),
            'unit_id' => $unit->id,
            'selling_price' => 100,
            'tax_inclusive' => false,
            'track_batches' => true,
        ]);

        $soon = $this->batch($product, 'SOON', now()->addMonth()->toDateString());
        $late = $this->batch($product, 'LATE', now()->addYear()->toDateString());

        $this->stock->receive($product, 4, $this->warehouse, $soon, 60,
            StockMovement::PURCHASE, null, null, $this->shop->id);
        $this->stock->receive($product, 10, $this->warehouse, $late, 70,
            StockMovement::PURCHASE, null, null, $this->shop->id);

        $invoice = $this->bill([
            ['product_id' => $product->id, 'quantity' => 6],
        ], ['payments' => [['method' => 'cash', 'amount' => 600]]]);

        // One submitted row, two lines - because two different lots left the
        // shelf and the invoice has to say which.
        $this->assertCount(2, $invoice->items);

        $first = $invoice->items[0];
        $second = $invoice->items[1];

        $this->assertSame('SOON', $first->batch_no);
        $this->assertSame(4.0, (float) $first->quantity);
        $this->assertSame('LATE', $second->batch_no);
        $this->assertSame(2.0, (float) $second->quantity);

        // Each line carries its own lot's cost.
        $this->assertSame(60.0, (float) $first->unit_cost);
        $this->assertSame(70.0, (float) $second->unit_cost);
    }

    private function batch(Product $product, string $number, string $expiry): Batch
    {
        $batch = new Batch([
            'shop_id' => $this->shop->id,
            'product_id' => $product->id,
            'batch_no' => $number,
            'expiry_date' => $expiry,
        ]);

        $batch->saveQuietly();

        return $batch;
    }

    /* ------------------------------------------------------------- credit */

    public function test_a_cash_only_customer_cannot_be_billed_on_credit(): void
    {
        $product = $this->product();
        $customer = $this->customer(['allow_credit' => false]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cash-only');

        $this->bill(
            [['product_id' => $product->id, 'quantity' => 1]],
            ['customer' => $customer, 'payments' => []],
        );
    }

    public function test_credit_beyond_the_limit_is_refused(): void
    {
        $product = $this->product();
        $customer = $this->customer(['allow_credit' => true, 'credit_limit' => 100]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('credit left');

        // â‚¹236 against a â‚¹100 limit.
        $this->bill(
            [['product_id' => $product->id, 'quantity' => 1]],
            ['customer' => $customer, 'payments' => []],
        );
    }

    public function test_a_walk_in_cannot_be_left_owing(): void
    {
        $product = $this->product();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('walk-in');

        $this->bill([['product_id' => $product->id, 'quantity' => 1]], ['payments' => []]);
    }

    public function test_a_credit_sale_posts_to_the_ledger_and_sets_a_due_date(): void
    {
        $product = $this->product();
        $customer = $this->customer([
            'allow_credit' => true,
            'credit_limit' => 10000,
            'credit_days' => 30,
        ]);

        $invoice = $this->bill(
            [['product_id' => $product->id, 'quantity' => 1]],
            ['customer' => $customer, 'payments' => []],
        );

        $this->assertTrue($invoice->is_credit);
        $this->assertSame(236.00, (float) $invoice->due_total);
        $this->assertSame(Invoice::ISSUED, $invoice->status);
        $this->assertSame(
            $invoice->invoiced_at->copy()->addDays(30)->toDateString(),
            $invoice->due_date->toDateString(),
        );

        $this->assertSame(236.00, (float) $customer->fresh()->balance);

        $entry = CustomerLedger::allShops()->where('customer_id', $customer->id)->sole();
        $this->assertSame(CustomerLedger::INVOICE, $entry->type);
        $this->assertSame(236.00, (float) $entry->debit);
        $this->assertSame(236.00, (float) $entry->balance_after);
    }

    public function test_a_part_paid_sale_records_both_sides(): void
    {
        $product = $this->product();
        $customer = $this->customer(['allow_credit' => true, 'credit_limit' => 10000, 'credit_days' => 15]);

        $invoice = $this->bill(
            [['product_id' => $product->id, 'quantity' => 1]],
            ['customer' => $customer, 'payments' => [['method' => 'cash', 'amount' => 100]]],
        );

        $this->assertSame(Invoice::PARTIAL, $invoice->status);
        $this->assertSame(100.00, (float) $invoice->paid_total);
        $this->assertSame(136.00, (float) $invoice->due_total);
        $this->assertSame(136.00, (float) $customer->fresh()->balance);

        $entries = CustomerLedger::allShops()->where('customer_id', $customer->id)->get();
        $this->assertCount(2, $entries);
        $this->assertSame(236.00, (float) $entries[0]->debit);
        $this->assertSame(100.00, (float) $entries[1]->credit);
    }

    public function test_a_cheque_does_not_settle_the_invoice_until_it_clears(): void
    {
        $product = $this->product();
        $customer = $this->customer(['allow_credit' => true, 'credit_limit' => 10000]);

        $invoice = $this->bill(
            [['product_id' => $product->id, 'quantity' => 1]],
            ['customer' => $customer, 'payments' => [['method' => 'cheque', 'amount' => 236]]],
        );

        // The cheque is recorded, but nothing has been paid yet.
        $this->assertSame(0.00, (float) $invoice->paid_total);
        $this->assertSame(236.00, (float) $invoice->due_total);

        $payment = $invoice->payments()->sole();
        $this->assertSame(Payment::PENDING, $payment->status);
    }

    /* ------------------------------------------------------------ cancel */

    public function test_cancelling_puts_the_stock_back_and_reverses_the_account(): void
    {
        $product = $this->product(stock: 50);
        $customer = $this->customer(['allow_credit' => true, 'credit_limit' => 10000]);

        $invoice = $this->bill(
            [['product_id' => $product->id, 'quantity' => 10]],
            ['customer' => $customer, 'payments' => []],
        );

        $this->assertSame(40.0, $product->stockOnHand($this->shop->id));
        $this->assertSame(2360.00, (float) $customer->fresh()->balance);

        $this->invoices->cancel($invoice, 'Customer changed their mind');

        $this->assertSame(Invoice::CANCELLED, $invoice->fresh()->status);
        $this->assertSame(50.0, $product->stockOnHand($this->shop->id));
        $this->assertSame(0.00, (float) $customer->fresh()->balance);
        $this->assertSame(0.00, (float) $invoice->fresh()->due_total);
    }

    public function test_cancelling_voids_the_payments_rather_than_deleting_them(): void
    {
        $product = $this->product();

        $invoice = $this->bill(
            [['product_id' => $product->id, 'quantity' => 1]],
            ['payments' => [['method' => 'cash', 'amount' => 236]]],
        );

        $this->invoices->cancel($invoice, 'Rung up twice');

        $payment = $invoice->payments()->withoutGlobalScopes()->sole();

        // The till still has to be able to show money came in and went back.
        $this->assertSame(Payment::CANCELLED, $payment->status);
    }

    public function test_a_cancelled_invoice_keeps_its_number_and_is_left_out_of_sales(): void
    {
        $product = $this->product();

        $invoice = $this->bill(
            [['product_id' => $product->id, 'quantity' => 1]],
            ['payments' => [['method' => 'cash', 'amount' => 236]]],
        );

        $number = $invoice->number;

        $this->invoices->cancel($invoice, 'Wrong customer');

        $this->assertSame($number, $invoice->fresh()->number);
        $this->assertSame(0, Invoice::allShops()->counted()->count());
        $this->assertSame(1, Invoice::allShops()->count());
    }

    public function test_cancelling_twice_is_refused(): void
    {
        $product = $this->product();

        $invoice = $this->bill(
            [['product_id' => $product->id, 'quantity' => 1]],
            ['payments' => [['method' => 'cash', 'amount' => 236]]],
        );

        $this->invoices->cancel($invoice, 'First');

        $this->expectException(RuntimeException::class);

        $this->invoices->cancel($invoice->fresh(), 'Second');
    }

    public function test_cancelling_without_a_reason_is_refused(): void
    {
        $product = $this->product();

        $invoice = $this->bill(
            [['product_id' => $product->id, 'quantity' => 1]],
            ['payments' => [['method' => 'cash', 'amount' => 236]]],
        );

        $this->expectException(RuntimeException::class);

        $this->invoices->cancel($invoice, '  ');
    }

    /* ------------------------------------------------------------ margin */

    public function test_the_cost_of_sale_is_captured_at_billing_time(): void
    {
        // A later, dearer purchase must not restate the margin on a sale
        // already made.
        $product = $this->product(stock: 20, cost: 150);

        $invoice = $this->bill([
            ['product_id' => $product->id, 'quantity' => 5],
        ], ['payments' => [['method' => 'cash', 'amount' => 1180]]]);

        $this->assertSame(750.0, (float) $invoice->cost_total);
        $this->assertSame(250.0, $invoice->grossProfit());

        $this->stock->receive($product, 20, $this->warehouse, null, 400,
            StockMovement::PURCHASE, null, null, $this->shop->id);

        $this->assertSame(750.0, (float) $invoice->fresh()->cost_total);
    }

    /* ---------------------------------------------------------- numbering */

    public function test_invoice_numbers_run_in_a_per_shop_series(): void
    {
        $product = $this->product(stock: 100);

        $first = $this->bill([['product_id' => $product->id, 'quantity' => 1]],
            ['payments' => [['method' => 'cash', 'amount' => 236]]]);
        $second = $this->bill([['product_id' => $product->id, 'quantity' => 1]],
            ['payments' => [['method' => 'cash', 'amount' => 236]]]);

        $this->assertNotSame($first->number, $second->number);
        $this->assertStringContainsString($this->shop->code, $first->number);
        $this->assertStringContainsString(now()->format('Y'), $first->number);
    }
}
