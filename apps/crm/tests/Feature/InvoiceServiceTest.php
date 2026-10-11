<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerLedger;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Shop;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Services\AlertService;
use App\Services\InvoiceService;
use App\Services\LedgerService;
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
 *   - the cost of a sale is captured at billing time and never restated
 *   - credit is refused when it should be, before anything else happens
 */
class InvoiceServiceTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private InvoiceService $invoices;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        CurrentShop::forget();

        $this->shop = Shop::firstOrFail();
        $this->shop->forceFill(['state' => 'Maharashtra', 'state_code' => '27'])->save();

        $this->invoices = $this->app->make(InvoiceService::class);
    }

    protected function tearDown(): void
    {
        CurrentShop::forget();

        parent::tearDown();
    }

    /* ------------------------------------------------------------ fixtures */

    /** A product priced tax-inclusive at 18%. */
    private function product(array $attributes = [], float $cost = 200): Product
    {
        $unit = Unit::where('code', 'PCS')->firstOrFail();
        $gst18 = TaxRate::where('name', 'GST 18%')->firstOrFail();

        static $counter = 0;
        $counter++;

        return Product::create([
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

    public function test_cancelling_reverses_the_account(): void
    {
        $product = $this->product();
        $customer = $this->customer(['allow_credit' => true, 'credit_limit' => 10000]);

        $invoice = $this->bill(
            [['product_id' => $product->id, 'quantity' => 10]],
            ['customer' => $customer, 'payments' => []],
        );

        $this->assertSame(2360.00, (float) $customer->fresh()->balance);

        $this->invoices->cancel($invoice, 'Customer changed their mind');

        $this->assertSame(Invoice::CANCELLED, $invoice->fresh()->status);
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
        // A later, dearer catalogue update must not restate the margin on a
        // sale already made - cost_total is a snapshot, not a live read.
        $product = $this->product(cost: 150);

        $invoice = $this->bill([
            ['product_id' => $product->id, 'quantity' => 5],
        ], ['payments' => [['method' => 'cash', 'amount' => 1180]]]);

        $this->assertSame(750.0, (float) $invoice->cost_total);
        $this->assertSame(250.0, $invoice->grossProfit());

        $product->update(['purchase_price' => 400]);

        $this->assertSame(750.0, (float) $invoice->fresh()->cost_total);
    }

    /* ---------------------------------------------------------- numbering */

    public function test_invoice_numbers_run_in_a_per_shop_series(): void
    {
        $product = $this->product();

        $first = $this->bill([['product_id' => $product->id, 'quantity' => 1]],
            ['payments' => [['method' => 'cash', 'amount' => 236]]]);
        $second = $this->bill([['product_id' => $product->id, 'quantity' => 1]],
            ['payments' => [['method' => 'cash', 'amount' => 236]]]);

        $this->assertNotSame($first->number, $second->number);
        $this->assertStringContainsString($this->shop->code, $first->number);
        $this->assertStringContainsString(now()->format('Y'), $first->number);
    }
}
