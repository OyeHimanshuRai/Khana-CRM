<?php

namespace Tests\Feature;

use App\Mail\PaymentReminderMail;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentReminder;
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
use App\Services\ReminderService;
use App\Services\StockService;
use App\Support\CurrentShop;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The reminder engine.
 *
 * The two properties that matter most are both about not embarrassing the
 * shop:
 *
 *   1. Running the scheduler twice must not send anything twice. A shop
 *      whose reminders arrive in duplicate gets marked as spam, and then
 *      none of them arrive.
 *
 *   2. A customer who paid between the reminder being raised and it going
 *      out must not be chased. The check happens at the moment of sending,
 *      not at the moment of scheduling.
 */
class ReminderServiceTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private Warehouse $warehouse;

    private StockService $stock;

    private InvoiceService $invoices;

    private PaymentService $payments;

    private ReminderService $reminders;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        CurrentShop::forget();
        Mail::fake();

        /*
         | Pinned to the middle of a working day.
         |
         | ReminderService will not send outside config('reminders.send_between')
         | - nobody wants a demand for money at half past five in the morning -
         | so a suite run before 09:00 or after 19:00 in the app's timezone had
         | every dispatch test asserting on zero. The app runs in UTC, which put
         | a morning run in India squarely outside the window.
         |
         | The date is kept as today's so nothing here depends on a fixed day of
         | the week; only the clock is held.
         */
        Carbon::setTestNow(Carbon::now()->setTime(12, 0));

        $this->shop = Shop::firstOrFail();
        $this->warehouse = Warehouse::allShops()->where('shop_id', $this->shop->id)->firstOrFail();

        $this->stock = new StockService();
        $ledger = new LedgerService();
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
        $this->app->instance(LedgerService::class, $ledger);
        $this->invoices = $this->app->make(InvoiceService::class);
        $this->payments = new PaymentService($ledger, new AlertService());
        $this->reminders = new ReminderService();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CurrentShop::forget();

        parent::tearDown();
    }

    /* ------------------------------------------------------------ fixtures */

    private function product(): Product
    {
        static $counter = 0;
        $counter++;

        $product = Product::create([
            'name' => 'Reminder Product '.$counter,
            'slug' => Product::uniqueSlug('Reminder Product '.$counter),
            'sku' => Product::generateSku('REM'.$counter),
            'unit_id' => Unit::where('code', 'PCS')->value('id'),
            'tax_rate_id' => TaxRate::where('name', 'Exempt / Nil')->value('id'),
            'purchase_price' => 50,
            'selling_price' => 100,
            'tax_inclusive' => true,
        ]);

        $this->stock->receive($product, 500, $this->warehouse, null, 50,
            StockMovement::PURCHASE, null, null, $this->shop->id);

        return $product;
    }

    private function customer(array $attributes = []): Customer
    {
        static $counter = 0;
        $counter++;

        $customer = new Customer([
            'shop_id' => $this->shop->id,
            'name' => 'Reminder Customer '.$counter,
            'mobile' => '97000000'.str_pad((string) $counter, 2, '0', STR_PAD_LEFT),
            'email' => "reminder{$counter}@example.test",
            'type' => 'farmer',
            'allow_credit' => true,
            'credit_limit' => 100000,
            'credit_days' => 30,
            ...$attributes,
        ]);

        $customer->saveQuietly();

        return $customer;
    }

    /** An unpaid invoice whose due date is `$dueInDays` from today. */
    private function unpaidInvoice(Customer $customer, int $dueInDays, float $amount = 1000): Invoice
    {
        $product = $this->product();

        $invoice = $this->invoices->create([
            'shop' => $this->shop,
            'warehouse' => $this->warehouse,
            'customer' => $customer,
            'channel' => Invoice::MANUAL,
            'items' => [['product_id' => $product->id, 'quantity' => $amount / 100]],
            'payments' => [],
        ]);

        $invoice->forceFill(['due_date' => today()->addDays($dueInDays)])->save();

        return $invoice->fresh();
    }

    /* --------------------------------------------------------- scheduling */

    public function test_an_invoice_past_its_due_date_gets_an_overdue_reminder(): void
    {
        $customer = $this->customer();
        $invoice = $this->unpaidInvoice($customer, -10);

        $this->reminders->schedule();

        $reminder = PaymentReminder::allShops()
            ->where('invoice_id', $invoice->id)
            ->where('trigger', 'overdue')
            ->sole();

        $this->assertSame(PaymentReminder::PENDING, $reminder->status);
        $this->assertSame($customer->email, $reminder->recipient);
        $this->assertSame(1000.00, (float) $reminder->amount_due);
    }

    public function test_an_invoice_comfortably_in_date_gets_nothing(): void
    {
        $customer = $this->customer();
        $this->unpaidInvoice($customer, 30);

        $this->reminders->schedule();

        $this->assertSame(0, PaymentReminder::allShops()->count());
    }

    public function test_the_earlier_triggers_fire_as_the_date_approaches(): void
    {
        $customer = $this->customer();

        // Three days out: the "before it falls due" trigger, and nothing else.
        $invoice = $this->unpaidInvoice($customer, 3);

        $this->reminders->schedule();

        $triggers = PaymentReminder::allShops()
            ->where('invoice_id', $invoice->id)
            ->pluck('trigger');

        $this->assertContains('upcoming', $triggers);
        $this->assertNotContains('due_today', $triggers);
        $this->assertNotContains('overdue', $triggers);
    }

    public function test_a_long_overdue_invoice_accumulates_every_earlier_trigger(): void
    {
        // Catching up matters: an invoice loaded into the system already
        // 60 days late should not silently skip the earlier stages.
        $customer = $this->customer();
        $invoice = $this->unpaidInvoice($customer, -60);

        $this->reminders->schedule();

        $triggers = PaymentReminder::allShops()
            ->where('invoice_id', $invoice->id)
            ->pluck('trigger');

        $this->assertContains('upcoming', $triggers);
        $this->assertContains('due_today', $triggers);
        $this->assertContains('overdue', $triggers);
        $this->assertContains('long_overdue', $triggers);
    }

    public function test_running_the_scheduler_twice_schedules_nothing_twice(): void
    {
        $customer = $this->customer();
        $this->unpaidInvoice($customer, -10);

        $first = $this->reminders->schedule();
        $second = $this->reminders->schedule();

        $this->assertGreaterThan(0, $first['scheduled']);
        $this->assertSame(0, $second['scheduled']);
    }

    public function test_a_customer_with_no_email_is_skipped_rather_than_queued(): void
    {
        // Not a failure to retry - a fact about the customer. It belongs in
        // the log as such, so somebody can go and get an address.
        $customer = $this->customer(['email' => null]);
        $invoice = $this->unpaidInvoice($customer, -10);

        $this->reminders->schedule();

        $reminder = PaymentReminder::allShops()
            ->where('invoice_id', $invoice->id)
            ->where('trigger', 'overdue')
            ->sole();

        $this->assertSame(PaymentReminder::SKIPPED, $reminder->status);
        $this->assertStringContainsString('No email', (string) $reminder->skip_reason);
    }

    public function test_a_settled_invoice_is_never_scheduled(): void
    {
        $customer = $this->customer();
        $product = $this->product();

        $this->invoices->create([
            'shop' => $this->shop,
            'warehouse' => $this->warehouse,
            'customer' => $customer,
            'channel' => Invoice::MANUAL,
            'items' => [['product_id' => $product->id, 'quantity' => 10]],
            'payments' => [['method' => Payment::CASH, 'amount' => 1000]],
        ]);

        $this->reminders->schedule();

        $this->assertSame(0, PaymentReminder::allShops()->count());
    }

    /* ---------------------------------------------------------- sending */

    public function test_dispatching_sends_the_mail_and_marks_the_row(): void
    {
        $customer = $this->customer();
        $this->unpaidInvoice($customer, -10);

        $this->reminders->schedule();
        $result = $this->reminders->dispatch();

        $this->assertGreaterThan(0, $result['sent']);

        Mail::assertSent(PaymentReminderMail::class);

        $this->assertSame(
            0,
            PaymentReminder::allShops()->where('status', PaymentReminder::PENDING)->count(),
        );
    }

    public function test_a_customer_who_paid_since_is_not_chased(): void
    {
        /*
         | The whole point of checking at send time rather than at schedule
         | time. Nothing is more damaging to a shop's relationship with a
         | farmer than a demand for money they paid yesterday.
         */
        $customer = $this->customer();
        $invoice = $this->unpaidInvoice($customer, -10);

        $this->reminders->schedule();

        $this->payments->collect($customer, 1000, Payment::CASH);

        $result = $this->reminders->dispatch();

        $this->assertSame(0, $result['sent']);
        $this->assertGreaterThan(0, $result['skipped']);

        Mail::assertNothingSent();

        $reminder = PaymentReminder::allShops()
            ->where('invoice_id', $invoice->id)
            ->where('trigger', 'overdue')
            ->sole();

        $this->assertSame(PaymentReminder::SKIPPED, $reminder->status);
        $this->assertSame('Already paid', $reminder->skip_reason);
    }

    public function test_a_cancelled_invoice_is_not_chased(): void
    {
        $customer = $this->customer();
        $invoice = $this->unpaidInvoice($customer, -10);

        $this->reminders->schedule();

        $this->invoices->cancel($invoice, 'Raised in error');

        $result = $this->reminders->dispatch();

        $this->assertSame(0, $result['sent']);
        Mail::assertNothingSent();
    }

    public function test_dispatching_twice_sends_nothing_twice(): void
    {
        $customer = $this->customer();
        $this->unpaidInvoice($customer, -10);

        $this->reminders->schedule();

        $first = $this->reminders->dispatch();
        $second = $this->reminders->dispatch();

        $this->assertGreaterThan(0, $first['sent']);
        $this->assertSame(0, $second['sent']);
        $this->assertSame(0, $second['skipped']);
    }

    public function test_the_master_switch_stops_everything(): void
    {
        config(['reminders.enabled' => false]);

        $customer = $this->customer();
        $this->unpaidInvoice($customer, -10);

        $this->assertSame(0, $this->reminders->schedule()['scheduled']);
        $this->assertSame(0, $this->reminders->dispatch()['sent']);
        $this->assertSame(0, PaymentReminder::allShops()->count());
    }

    public function test_a_disabled_trigger_is_not_scheduled(): void
    {
        config(['reminders.triggers.upcoming.enabled' => false]);

        $customer = $this->customer();
        $invoice = $this->unpaidInvoice($customer, -10);

        $this->reminders->schedule();

        $triggers = PaymentReminder::allShops()
            ->where('invoice_id', $invoice->id)
            ->pluck('trigger');

        $this->assertNotContains('upcoming', $triggers);
        $this->assertContains('overdue', $triggers);
    }

    public function test_an_enabled_sms_channel_is_scheduled_and_sent(): void
    {
        config(['reminders.channels.sms.enabled' => true]);

        $customer = $this->customer();
        $this->unpaidInvoice($customer, -10);

        $this->reminders->schedule();

        $sms = PaymentReminder::allShops()->where('channel', 'sms')->get();

        $this->assertNotEmpty($sms, 'Enabling the channel should schedule it.');

        /*
         | And it now actually goes. SMS used to be declared in config with
         | nothing behind it, so this asserted an honest skip; the channel is
         | wired up now. The default driver writes to the log rather than
         | sending, which is a real delivery as far as this system is
         | concerned - see config/sms.php for why that is the default.
         */
        $this->reminders->dispatch();

        $this->assertSame(
            PaymentReminder::SENT,
            $sms->first()->fresh()->status,
        );
    }

    public function test_a_channel_with_no_provider_behind_it_skips_honestly(): void
    {
        config([
            'reminders.channels.sms.enabled' => true,
            // A driver somebody switched on and never finished: no URL.
            'sms.driver' => 'http',
            'sms.drivers.http.url' => '',
        ]);

        $customer = $this->customer();
        $this->unpaidInvoice($customer, -10);

        $this->reminders->schedule();
        $this->reminders->dispatch();

        $reminder = PaymentReminder::allShops()->where('channel', 'sms')->first()?->fresh();

        /*
         | An honest skip beats a row claiming to have sent an SMS nobody
         | sent - and beats a failure, which a retry would chase for ever
         | against a provider that was never configured.
         */
        $this->assertSame(PaymentReminder::SKIPPED, $reminder?->status);
    }

    /* ------------------------------------------------------------ scoping */

    public function test_the_scheduler_runs_across_every_shop(): void
    {
        // It runs from cron with no authenticated user and no shop context,
        // so it must not depend on either.
        $other = Shop::create([
            'name' => 'Second Branch',
            'code' => 'SECOND',
            'slug' => Shop::uniqueSlug('Second Branch'),
            'is_active' => true,
        ]);

        Warehouse::withoutEvents(fn () => Warehouse::query()->create([
            'shop_id' => $other->id,
            'name' => 'Main Store',
            'code' => 'MAIN',
            'is_default' => true,
            'is_active' => true,
        ]));

        $mine = $this->customer();
        $this->unpaidInvoice($mine, -10);

        $this->reminders->schedule();

        $this->assertGreaterThan(0, PaymentReminder::allShops()->where('shop_id', $this->shop->id)->count());
    }
}
