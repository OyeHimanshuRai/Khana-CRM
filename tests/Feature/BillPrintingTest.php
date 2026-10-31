<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Models\Shop;
use App\Models\User;
use App\Services\PrintService;
use App\Support\CurrentShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Bills going to a network printer, and the print log's guards.
 *
 * A real listening socket on 127.0.0.1 stands in for the printer, so what is
 * asserted is the exact bytes a thermal printer would receive. That is a test
 * of our own output, not of any hardware.
 */
class BillPrintingTest extends TestCase
{
    use RefreshDatabase;

    /** @var resource|null */
    private $server = null;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');
        $this->seed();
        CurrentShop::forget();
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            fclose($this->server);
        }

        CurrentShop::forget();

        parent::tearDown();
    }

    private function shop(): Shop
    {
        return Shop::query()->orderBy('id')->firstOrFail();
    }

    /** @param array<int, string> $permissions */
    private function staff(array $permissions): User
    {
        $user = User::create([
            'tenant_id' => $this->shop()->tenant_id,
            'name' => 'Biller',
            'email' => 'biller@example.test',
            'password' => 'biller-password-1',
            'is_admin' => true,
        ]);

        $user->shops()->syncWithoutDetaching([$this->shop()->id => ['is_default' => true]]);
        $user->forceFill(['current_shop_id' => $this->shop()->id, 'all_shops_view' => false])->save();
        $user->givePermissionTo(array_merge(['dashboard.overview.view'], $permissions));

        return $user->fresh();
    }

    /** A bill printer pointed at a socket this test is listening on. */
    private function listeningPrinter(): Printer
    {
        $this->server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $this->assertNotFalse($this->server, "Could not listen: {$error}");

        $port = (int) substr(strrchr(stream_socket_get_name($this->server, false), ':'), 1);

        return Printer::create([
            'shop_id' => $this->shop()->id,
            'name' => 'Counter',
            'code' => 'counter',
            'kind' => Printer::BILL,
            'driver' => Printer::NETWORK,
            'host' => '127.0.0.1',
            'port' => $port,
            'columns' => 32,
            'copies' => 1,
            'is_active' => true,
            'is_default' => true,
        ]);
    }

    /** What the printer was sent. */
    private function received(): string
    {
        $connection = @stream_socket_accept($this->server, 1);
        $this->assertNotFalse($connection, 'The printer was never connected to.');

        stream_set_timeout($connection, 1);
        $bytes = (string) stream_get_contents($connection);
        fclose($connection);

        return $bytes;
    }

    private function invoice(): Invoice
    {
        $this->shop()->forceFill(['gstin' => '27ABCDE1234F1Z5', 'fssai_no' => '12345678901234'])->save();

        $invoice = Invoice::unguarded(fn () => Invoice::create([
            'shop_id' => $this->shop()->id,
            'number' => 'POS/MAIN/2026/00077',
            'channel' => 'pos',
            'status' => 'issued',
            'invoiced_at' => now(),
            'subtotal' => 250,
            'cgst_total' => 6.25,
            'sgst_total' => 6.25,
            'tax_total' => 12.5,
            'grand_total' => 262.5,
            'due_total' => 0,
        ]));

        InvoiceItem::unguarded(fn () => InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'product_name' => 'Paneer Butter Masala With Extra Long Description That Must Wrap',
            'quantity' => 1,
            'unit_price' => 250,
            'line_total' => 250,
        ]));

        return $invoice;
    }

    public function test_the_bill_reaches_the_printer_with_its_own_numbers(): void
    {
        $this->listeningPrinter();
        $invoice = $this->invoice();

        $job = app(PrintService::class)->send($invoice, Printer::BILL);

        $this->assertSame(PrintJob::SENT, $job->status);

        $paper = $this->received();

        $this->assertStringContainsString('Bill POS/MAIN/2026/00077', $paper);
        $this->assertStringContainsString('27ABCDE1234F1Z5', $paper);
        $this->assertStringContainsString('FSSAI 12345678901234', $paper);
        $this->assertStringContainsString('262.50', $paper);
        $this->assertStringContainsString('CGST', $paper);

        // Wrapped, never cut: the end of the long name is still on the paper.
        $this->assertStringContainsString('Wrap', $paper);

        // And no line is wider than the 32-column roll.
        // Printer commands are not printed: remove ESC @/E/a n and GS ! n / V A n first.
        $text = preg_replace('/\x1B[@]|\x1B[Ea]./s', '', $paper);
        $text = preg_replace('/\x1D!.|\x1DVA./s', '', $text);

        foreach (explode("\n", $text) as $line) {
            $this->assertLessThanOrEqual(32, mb_strlen($line), "Line too wide: {$line}");
        }
    }

    public function test_a_hindi_item_name_stays_valid_text_when_it_wraps(): void
    {
        $this->listeningPrinter();
        $invoice = $this->invoice();
        $invoice->items()->update(['product_name' => 'पनीर बटर मसाला विशेष घर का स्वाद वाला बड़ा पैकेट']);

        app(PrintService::class)->send($invoice->fresh(), Printer::BILL);

        // A wrap that lands inside a character sends the printer broken bytes.
        $this->assertTrue(mb_check_encoding($this->received(), 'UTF-8'));
    }

    public function test_a_double_click_is_one_print(): void
    {
        $this->listeningPrinter();
        $invoice = $this->invoice();

        $first = app(PrintService::class)->send($invoice, Printer::BILL);
        $second = app(PrintService::class)->send($invoice, Printer::BILL);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, PrintJob::count());
    }

    public function test_a_deliberate_reprint_is_a_new_job(): void
    {
        $this->listeningPrinter();
        $invoice = $this->invoice();

        app(PrintService::class)->send($invoice, Printer::BILL);
        $copy = app(PrintService::class)->send($invoice, Printer::BILL, null, true, 'Customer lost it');

        $this->assertTrue($copy->is_reprint);
        $this->assertSame(2, PrintJob::count());
    }

    public function test_a_failed_print_can_be_retried_as_a_new_row(): void
    {
        $printer = $this->listeningPrinter();
        $invoice = $this->invoice();

        // Point it somewhere that refuses, fail once, then fix the address.
        $good = $printer->port;
        $printer->forceFill(['port' => 1])->save();

        $failed = app(PrintService::class)->send($invoice, Printer::BILL);
        $this->assertSame(PrintJob::FAILED, $failed->status);

        $printer->forceFill(['port' => $good])->save();

        $retry = app(PrintService::class)->retry($failed);

        $this->assertSame(PrintJob::SENT, $retry->status);
        $this->assertNotSame($failed->id, $retry->id);
        $this->assertFalse($retry->is_reprint);
        $this->assertSame(PrintJob::FAILED, $failed->fresh()->status);
    }

    public function test_only_a_failed_job_can_be_retried(): void
    {
        $this->listeningPrinter();
        $sent = app(PrintService::class)->send($this->invoice(), Printer::BILL);

        $this->expectException(\RuntimeException::class);

        app(PrintService::class)->retry($sent);
    }

    public function test_a_second_copy_over_http_needs_a_reason(): void
    {
        $this->listeningPrinter();
        $invoice = $this->invoice();
        $staff = $this->staff(['sales.invoices.print']);

        $this->actingAs($staff)
            ->postJson(route('admin.invoices.send-to-printer', $invoice))
            ->assertOk();

        $this->actingAs($staff)
            ->postJson(route('admin.invoices.send-to-printer', $invoice))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        $this->actingAs($staff)
            ->postJson(route('admin.invoices.send-to-printer', $invoice), ['reason' => 'Customer lost it'])
            ->assertOk();

        $this->assertSame(1, PrintJob::reprints()->count());
    }

    public function test_sending_without_the_right_is_refused(): void
    {
        $this->actingAs($this->staff([]))
            ->postJson(route('admin.invoices.send-to-printer', $this->invoice()))
            ->assertForbidden();
    }

    public function test_the_receipt_page_shows_fssai_and_the_58mm_layout(): void
    {
        $invoice = $this->invoice();
        $staff = $this->staff(['sales.invoices.print']);

        $this->actingAs($staff)
            ->get(route('admin.invoices.print', [$invoice, 'format' => 'receipt58']))
            ->assertOk()
            ->assertSee('FSSAI 12345678901234')
            ->assertSee('print-receipt58', false);
    }
}
