<?php

namespace Tests\Feature;

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
use App\Services\PaymentService;
use App\Services\StockService;
use App\Support\CurrentShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Collections, and the account they move.
 *
 * The invariants:
 *
 *   - customers.balance always equals the ledger's running total
 *   - a payment settles the oldest invoice first unless told otherwise,
 *     which is what keeps the ageing report meaningful
 *   - a cheque changes nothing until it clears
 *   - a bounce or a reversal puts the debt back and leaves both entries
 *     visible; nothing is ever deleted or edited
 */
class PaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private Warehouse $warehouse;

    private StockService $stock;

    private InvoiceService $invoices;

    private PaymentService $payments;

    private LedgerService $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        CurrentShop::forget();

        $this->shop = Shop::firstOrFail();
        $this->warehouse = Warehouse::allShops()->where('shop_id', $this->shop->id)->firstOrFail();

        $this->stock = new StockService();
        $this->ledger = new LedgerService();
        /*
         | Built through the container rather than by hand.
         |
         | Constructed literally, this breaks every time the service gains a
         | dependency - which it has, twice - and the failure lands on four
         | test files that have nothing to do with the change. What these
         | tests DO care about is holding on to the same collaborators, so
         | those are bound explicitly and the rest is resolved.
         */
        $this->app->instance(StockService::class, $this->stock);
        $this->app->instance(LedgerService::class, $this->ledger);
        $this->invoices = $this->app->make(InvoiceService::class);
        $this->payments = new PaymentService($this->ledger, new AlertService());
    }

    protected function tearDown(): void
    {
        CurrentShop::forget();

        parent::tearDown();
    }

    /* ------------------------------------------------------------ fixtures */

    private function product(): Product
    {
        static $counter = 0;
        $counter++;

        $product = Product::create([
            'name' => 'Payment Test Product '.$counter,
            'slug' => Product::uniqueSlug('Payment Test Product '.$counter),
            'sku' => Product::generateSku('PAY'.$counter),
            'unit_id' => Unit::where('code', 'PCS')->value('id'),
            'tax_rate_id' => TaxRate::where('name', 'Exempt / Nil')->value('id'),
            'purchase_price' => 50,
            'selling_price' => 100,
            'tax_inclusive' => true,
        ]);

        $this->stock->receive($product, 1000, $this->warehouse, null, 50,
            StockMovement::PURCHASE, null, null, $this->shop->id);

        return $product;
    }

    private function customer(array $attributes = []): Customer
    {
        static $counter = 0;
        $counter++;

        $customer = new Customer([
            'shop_id' => $this->shop->id,
            'name' => 'Debtor '.$counter,
            'mobile' => '95000000'.str_pad((string) $counter, 2, '0', STR_PAD_LEFT),
            'type' => 'farmer',
            'allow_credit' => true,
            'credit_limit' => 100000,
            'credit_days' => 30,
            ...$attributes,
        ]);

        $customer->saveQuietly();

        return $customer;
    }

    /** An unpaid invoice for a round amount, dated N days ago. */
    private function creditSale(Customer $customer, float $amount, int $daysAgo = 0): Invoice
    {
        $product = $this->product();

        return $this->invoices->create([
            'shop' => $this->shop,
            'warehouse' => $this->warehouse,
            'customer' => $customer,
            'channel' => Invoice::MANUAL,
            'invoiced_at' => now()->subDays($daysAgo),
            'items' => [
                ['product_id' => $product->id, 'quantity' => $amount / 100],
            ],
            'payments' => [],
        ]);
    }

    /* -------------------------------------------------------- collecting */

    public function test_a_collection_settles_the_invoice_and_moves_the_account(): void
    {
        $customer = $this->customer();
        $invoice = $this->creditSale($customer, 1000);

        $this->assertSame(1000.00, (float) $customer->fresh()->balance);

        $this->payments->collect($customer, 1000, Payment::CASH);

        $this->assertSame(0.00, (float) $customer->fresh()->balance);
        $this->assertSame(Invoice::PAID, $invoice->fresh()->status);
        $this->assertSame(0.00, (float) $invoice->fresh()->due_total);
    }

    public function test_a_collection_settles_the_oldest_invoice_first(): void
    {
        /*
         | Not merely tidy: paying the newest first would leave the oldest
         | debt on the books for ever and make the ageing report say the
         | shop has a 90-day problem it does not have.
         */
        $customer = $this->customer();

        $oldest = $this->creditSale($customer, 500, 60);
        $middle = $this->creditSale($customer, 500, 30);
        $newest = $this->creditSale($customer, 500, 1);

        $this->payments->collect($customer, 700, Payment::CASH);

        $this->assertSame(0.00, (float) $oldest->fresh()->due_total);
        $this->assertSame(300.00, (float) $middle->fresh()->due_total);
        $this->assertSame(500.00, (float) $newest->fresh()->due_total);
        $this->assertSame(800.00, (float) $customer->fresh()->balance);
    }

    public function test_a_named_allocation_beats_the_oldest_first_rule(): void
    {
        $customer = $this->customer();

        $oldest = $this->creditSale($customer, 500, 60);
        $newest = $this->creditSale($customer, 500, 1);

        // The customer says "this one" - usually because they are disputing
        // the other.
        $this->payments->collect($customer, 500, Payment::CASH, [], [$newest->id => 500]);

        $this->assertSame(500.00, (float) $oldest->fresh()->due_total);
        $this->assertSame(0.00, (float) $newest->fresh()->due_total);
    }

    public function test_an_overpayment_stays_on_the_account_as_an_advance(): void
    {
        // A farmer paying ahead of the season is normal; refusing the money
        // would send them away with it.
        $customer = $this->customer();
        $this->creditSale($customer, 500);

        $payment = $this->payments->collect($customer, 800, Payment::CASH);

        $this->assertSame(-300.00, (float) $customer->fresh()->balance);
        $this->assertStringContainsString('advance', (string) $payment->fresh()->notes);
    }

    public function test_a_payment_of_zero_or_less_is_refused(): void
    {
        $customer = $this->customer();

        $this->expectException(RuntimeException::class);

        $this->payments->collect($customer, 0, Payment::CASH);
    }

    public function test_the_balance_always_matches_the_ledger(): void
    {
        $customer = $this->customer();

        $this->creditSale($customer, 1200);
        $this->payments->collect($customer, 400, Payment::CASH);
        $this->payments->collect($customer, 300, Payment::UPI);

        $reconciliation = $this->ledger->reconcile($customer->fresh());

        $this->assertSame(0.0, $reconciliation['drift']);
        $this->assertSame(500.00, $reconciliation['actual']);
    }

    /* ----------------------------------------------------------- cheques */

    public function test_a_cheque_changes_nothing_until_it_clears(): void
    {
        $customer = $this->customer();
        $invoice = $this->creditSale($customer, 1000);

        $payment = $this->payments->collect($customer, 1000, Payment::CHEQUE, [
            'transaction_ref' => '123456',
        ]);

        $this->assertSame(Payment::PENDING, $payment->status);
        $this->assertSame(1000.00, (float) $customer->fresh()->balance);
        $this->assertSame(1000.00, (float) $invoice->fresh()->due_total);

        $this->payments->clear($payment);

        $this->assertSame(Payment::CLEARED, $payment->fresh()->status);
        $this->assertSame(0.00, (float) $customer->fresh()->balance);
        $this->assertSame(0.00, (float) $invoice->fresh()->due_total);
    }

    public function test_clearing_something_already_cleared_is_refused(): void
    {
        $customer = $this->customer();
        $this->creditSale($customer, 500);

        $payment = $this->payments->collect($customer, 500, Payment::CASH);

        $this->expectException(RuntimeException::class);

        $this->payments->clear($payment);
    }

    public function test_a_bounced_cheque_puts_the_debt_back(): void
    {
        $customer = $this->customer();
        $invoice = $this->creditSale($customer, 1000);

        $payment = $this->payments->collect($customer, 1000, Payment::CHEQUE);
        $this->payments->clear($payment);

        $this->assertSame(0.00, (float) $customer->fresh()->balance);

        $this->payments->bounce($payment->fresh(), 'Insufficient funds');

        $this->assertSame(Payment::BOUNCED, $payment->fresh()->status);
        $this->assertSame(1000.00, (float) $customer->fresh()->balance);
        $this->assertSame(1000.00, (float) $invoice->fresh()->due_total);
        $this->assertSame(Invoice::ISSUED, $invoice->fresh()->status);
    }

    public function test_bouncing_a_cheque_that_never_cleared_leaves_the_balance_alone(): void
    {
        $customer = $this->customer();
        $this->creditSale($customer, 1000);

        $payment = $this->payments->collect($customer, 1000, Payment::CHEQUE);

        // Never cleared, so it never reduced anything - bouncing it must not
        // double the debt.
        $this->payments->bounce($payment, 'Post-dated and then withdrawn');

        $this->assertSame(1000.00, (float) $customer->fresh()->balance);
    }

    public function test_a_bounced_cheque_stays_on_the_record(): void
    {
        $customer = $this->customer();
        $this->creditSale($customer, 500);

        $payment = $this->payments->collect($customer, 500, Payment::CHEQUE);
        $this->payments->clear($payment);
        $this->payments->bounce($payment->fresh(), 'Signature mismatch');

        // A bounced cheque is a fact about the customer worth remembering.
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => Payment::BOUNCED,
        ]);
        $this->assertStringContainsString('Signature mismatch', (string) $payment->fresh()->notes);
    }

    /* --------------------------------------------------------- reversals */

    public function test_reversing_writes_a_mirror_entry_and_keeps_both(): void
    {
        $customer = $this->customer();
        $invoice = $this->creditSale($customer, 1000);

        $payment = $this->payments->collect($customer, 1000, Payment::CASH);
        $reversal = $this->payments->reverse($payment->fresh(), 'Entered against the wrong customer');

        $this->assertSame(Payment::CANCELLED, $payment->fresh()->status);
        $this->assertSame(Payment::OUT, $reversal->direction);
        $this->assertSame($payment->id, $reversal->reverses_payment_id);

        // Both rows survive; the pair nets to nothing.
        $this->assertSame(2, Payment::allShops()->count());

        $this->assertSame(1000.00, (float) $customer->fresh()->balance);
        $this->assertSame(1000.00, (float) $invoice->fresh()->due_total);
    }

    public function test_reversing_twice_is_refused(): void
    {
        $customer = $this->customer();
        $this->creditSale($customer, 500);

        $payment = $this->payments->collect($customer, 500, Payment::CASH);
        $this->payments->reverse($payment->fresh(), 'Duplicate');

        $this->expectException(RuntimeException::class);

        $this->payments->reverse($payment->fresh(), 'Again');
    }

    public function test_reversing_without_a_reason_is_refused(): void
    {
        $customer = $this->customer();
        $this->creditSale($customer, 500);

        $payment = $this->payments->collect($customer, 500, Payment::CASH);

        $this->expectException(RuntimeException::class);

        $this->payments->reverse($payment->fresh(), '   ');
    }

    public function test_a_reversal_reopens_the_newest_invoices_first(): void
    {
        /*
         | The mirror of how the payment was applied. A reversal should leave
         | the oldest debt where the ageing report already put it, rather
         | than un-ageing a bill that was settled months ago.
         */
        $customer = $this->customer();

        $oldest = $this->creditSale($customer, 500, 60);
        $newest = $this->creditSale($customer, 500, 1);

        // Settles the oldest, then 200 of the newest.
        $payment = $this->payments->collect($customer, 700, Payment::CASH);

        $this->assertSame(0.00, (float) $oldest->fresh()->due_total);
        $this->assertSame(300.00, (float) $newest->fresh()->due_total);

        $this->payments->reverse($payment->fresh(), 'Cash was never handed over');

        // The newest gets its 200 back first, then the oldest its 500.
        $this->assertSame(500.00, (float) $newest->fresh()->due_total);
        $this->assertSame(500.00, (float) $oldest->fresh()->due_total);
        $this->assertSame(1000.00, (float) $customer->fresh()->balance);
    }

    /* --------------------------------------------------------- write-off */

    public function test_a_write_off_clears_the_debt_under_its_own_entry_type(): void
    {
        $customer = $this->customer();
        $this->creditSale($customer, 900);

        $this->ledger->writeOff($customer, 900, 'Bad debt after two years');

        $this->assertSame(0.00, (float) $customer->fresh()->balance);

        $entry = CustomerLedger::allShops()
            ->where('customer_id', $customer->id)
            ->where('type', CustomerLedger::WRITE_OFF)
            ->sole();

        // Its own type, so it never reads as money the shop received.
        $this->assertSame(900.00, (float) $entry->credit);
        $this->assertStringContainsString('Bad debt', $entry->description);
    }

    /* ----------------------------------------------------------- numbers */

    public function test_receipt_numbers_run_in_a_per_shop_series(): void
    {
        $customer = $this->customer();
        $this->creditSale($customer, 1000);

        $first = $this->payments->collect($customer, 100, Payment::CASH);
        $second = $this->payments->collect($customer, 100, Payment::CASH);

        $this->assertNotSame($first->number, $second->number);
        $this->assertStringStartsWith('RCP/'.$this->shop->code, $first->number);
    }

    public function test_a_reversal_takes_a_number_from_the_opposite_series(): void
    {
        $customer = $this->customer();
        $this->creditSale($customer, 500);

        $payment = $this->payments->collect($customer, 500, Payment::CASH);
        $reversal = $this->payments->reverse($payment->fresh(), 'Wrong account');

        // Money going back out is a payment, not a receipt.
        $this->assertStringStartsWith('PAY/'.$this->shop->code, $reversal->number);
    }
}
