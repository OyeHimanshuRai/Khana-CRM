<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Models\Shop;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PrintService;
use App\Support\CurrentShop;
use App\Support\CurrentTenant;
use App\Support\Modules;
use App\Support\PlanAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The bill printer, the print log and the POS screen, end to end over HTTP.
 *
 * BillPrintingTest pins the happy path. This is the rest: other shops' rows,
 * missing printers, every line a bill can carry, and the receipt pages.
 */
class PrintFlowAuditTest extends TestCase
{
    use RefreshDatabase;

    /** @var resource|null */
    private $server = null;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');
        $this->seed();
        $this->resetState();

        $this->shop = Shop::query()->withoutGlobalScopes()->orderBy('id')->firstOrFail();
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            fclose($this->server);
        }

        $this->resetState();

        parent::tearDown();
    }

    private function resetState(): void
    {
        CurrentShop::forget();
        CurrentTenant::forget();
        PlanAccess::forget();
        Modules::forget();
    }

    /** @param array<int, string> $permissions */
    private function staff(array $permissions, ?Shop $shop = null): User
    {
        $shop ??= $this->shop;

        $user = User::create([
            'tenant_id' => $shop->tenant_id,
            'name' => 'Biller',
            'email' => 'biller'.uniqid().'@example.test',
            'password' => 'biller-password-1',
            'is_admin' => true,
            'is_active' => true,
        ]);

        $user->shops()->attach($shop->id, ['is_default' => true]);
        $user->forceFill(['current_shop_id' => $shop->id, 'all_shops_view' => false])->save();
        $user->givePermissionTo(array_merge(['dashboard.overview.view'], $permissions));

        return $user->fresh();
    }

    /** @param array<string, mixed> $overrides */
    private function printer(array $overrides = []): Printer
    {
        return Printer::create(array_merge([
            'shop_id' => $this->shop->id, 'name' => 'Counter', 'code' => 'counter-'.uniqid(),
            'kind' => Printer::BILL, 'driver' => Printer::NETWORK,
            'host' => '127.0.0.1', 'port' => 1, 'columns' => 42, 'copies' => 1,
            'is_active' => true, 'is_default' => true, 'auto_cut' => true,
        ], $overrides));
    }

    /** A bill printer on a socket this test holds open. */
    private function listening(array $overrides = []): Printer
    {
        $this->server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $port = (int) substr(strrchr(stream_socket_get_name($this->server, false), ':'), 1);

        return $this->printer(array_merge(['port' => $port], $overrides));
    }

    /** Everything each connection sent. @return array<int, string> */
    private function received(int $connections = 1): array
    {
        $all = [];

        for ($i = 0; $i < $connections; $i++) {
            $connection = stream_socket_accept($this->server, 1);
            stream_set_timeout($connection, 1);
            $all[] = (string) stream_get_contents($connection);
            fclose($connection);
        }

        return $all;
    }

    /** @param array<string, mixed> $overrides */
    private function invoice(array $overrides = [], ?Shop $shop = null): Invoice
    {
        $shop ??= $this->shop;

        $invoice = Invoice::unguarded(fn () => Invoice::query()->withoutGlobalScopes()->create(array_merge([
            'shop_id' => $shop->id, 'number' => 'INV-'.uniqid(), 'channel' => 'pos', 'status' => 'issued',
            'invoiced_at' => now(), 'subtotal' => 100, 'tax_total' => 0, 'grand_total' => 100, 'due_total' => 0,
        ], $overrides)));

        InvoiceItem::unguarded(fn () => InvoiceItem::create([
            'invoice_id' => $invoice->id, 'product_name' => 'Masala Dosa',
            'quantity' => 1, 'unit_price' => 100, 'line_total' => 100,
        ]));

        return $invoice;
    }

    private function otherShop(): Shop
    {
        $tenant = Tenant::create(['name' => 'Rival Cafe', 'code' => 'RIVAL', 'slug' => 'rival-cafe']);

        return Shop::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'name' => 'Rival Cafe', 'code' => 'RV', 'slug' => 'rival-cafe',
        ]);
    }

    /* ---------------------------------------------------- other people's */

    public function test_another_companys_invoice_cannot_be_sent_to_a_printer(): void
    {
        $this->printer();
        $theirs = $this->invoice([], $this->otherShop());

        $this->resetState();
        $this->actingAs($this->staff(['sales.invoices.print']))
            ->postJson(route('admin.invoices.send-to-printer', $theirs))
            ->assertNotFound();

        $this->assertSame(0, PrintJob::query()->withoutGlobalScopes()->count());
    }

    public function test_another_companys_failed_job_cannot_be_retried(): void
    {
        $other = $this->otherShop();
        $job = PrintJob::query()->withoutGlobalScopes()->create([
            'shop_id' => $other->id, 'kind' => Printer::BILL, 'status' => PrintJob::FAILED,
        ]);

        $this->resetState();
        $this->actingAs($this->staff(['settings.printers.print']))
            ->postJson(route('admin.printers.retry', $job))
            ->assertNotFound();
    }

    /* -------------------------------------------------------- the retry */

    public function test_retry_over_http_needs_the_right_and_then_sends(): void
    {
        $printer = $this->listening();
        $good = $printer->port;
        $printer->forceFill(['port' => 1])->save();

        $failed = app(PrintService::class)->send($this->invoice(), Printer::BILL);
        $printer->forceFill(['port' => $good])->save();

        $this->resetState();
        $this->actingAs($this->staff(['settings.printers.view']))
            ->postJson(route('admin.printers.retry', $failed))
            ->assertForbidden();

        $this->resetState();
        $this->actingAs($this->staff(['settings.printers.view', 'settings.printers.print']))
            ->postJson(route('admin.printers.retry', $failed))
            ->assertOk();

        $this->assertSame(2, PrintJob::count());
        $this->assertSame(1, PrintJob::where('status', PrintJob::SENT)->count());
    }

    public function test_the_log_offers_retry_only_on_failures_and_only_to_those_allowed(): void
    {
        $failed = app(PrintService::class)->send($this->invoice(), Printer::BILL);
        PrintJob::whereKey($failed->id)->update(['status' => PrintJob::FAILED, 'error' => 'Printer offline']);

        $this->resetState();
        $this->actingAs($this->staff(['settings.printers.view', 'settings.printers.print']))
            ->get(route('admin.printers.jobs'))
            ->assertOk()
            ->assertSee('data-retry-job', false);

        $this->resetState();
        $this->actingAs($this->staff(['settings.printers.view']))
            ->get(route('admin.printers.jobs'))
            ->assertOk()
            ->assertDontSee('data-retry-job=', false);
    }

    public function test_a_retry_with_nowhere_to_go_leaves_no_phantom_job(): void
    {
        $printer = $this->printer();
        $failed = app(PrintService::class)->send($this->invoice(), Printer::BILL);
        $this->assertSame(PrintJob::FAILED, $failed->status);

        $printer->delete();

        $this->resetState();
        $this->actingAs($this->staff(['settings.printers.print']))
            ->postJson(route('admin.printers.retry', $failed))
            ->assertStatus(422);

        // Nothing was printed and nobody is at a screen to print it: a queued
        // row here would sit in the log as a job that never ends.
        $this->assertSame(0, PrintJob::where('status', PrintJob::QUEUED)->count());
    }

    public function test_a_restaurant_owner_runs_their_own_printers_and_nobody_elses(): void
    {
        $owner = $this->staff([]);
        $owner->assignRole('Tenant Owner');

        $this->printer(['name' => 'Our Counter']);

        $other = $this->otherShop();
        $theirs = Printer::query()->withoutGlobalScopes()->create([
            'shop_id' => $other->id, 'name' => 'Their Counter', 'code' => 'theirs',
            'kind' => Printer::BILL, 'driver' => Printer::BROWSER,
        ]);

        $this->resetState();
        $this->actingAs($owner->fresh())->get(route('admin.printers.index'))
            ->assertOk()
            ->assertSee('Our Counter')
            ->assertDontSee('Their Counter');

        $this->resetState();
        $this->actingAs($owner->fresh())->get(route('admin.printers.jobs'))->assertOk();

        $this->resetState();
        $this->actingAs($owner->fresh())->get(route('admin.printers.edit', $theirs))->assertNotFound();
    }

    /* ---------------------------------------------- nothing to send it to */

    public function test_no_printer_says_so_and_logs_no_phantom_job(): void
    {
        $this->resetState();
        $this->actingAs($this->staff(['sales.invoices.print']))
            ->postJson(route('admin.invoices.send-to-printer', $this->invoice()))
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame(0, PrintJob::where('status', PrintJob::QUEUED)->count());
    }

    public function test_a_dead_printer_is_reported_with_its_name(): void
    {
        $this->printer(['name' => 'Front Counter']);

        $this->resetState();
        $response = $this->actingAs($this->staff(['sales.invoices.print']))
            ->postJson(route('admin.invoices.send-to-printer', $this->invoice()));

        $response->assertStatus(422);
        $this->assertStringContainsString('Front Counter', $response->json('message'));
    }

    /* --------------------------------------------------------- the paper */

    public function test_every_line_a_bill_can_carry_comes_out_to_the_paisa(): void
    {
        $this->listening();

        $invoice = $this->invoice([
            'subtotal' => 1000, 'line_discount_total' => 50, 'invoice_discount' => 25,
            'is_inter_state' => true, 'igst_total' => 45.5, 'tax_total' => 45.5,
            'round_off' => -0.5, 'grand_total' => 970, 'paid_total' => 500, 'due_total' => 470,
        ]);

        Payment::unguarded(fn () => $invoice->payments()->create([
            'shop_id' => $this->shop->id, 'party_type' => 'customer', 'direction' => 'in',
            'number' => 'PAY-1', 'method' => Payment::CASH,
            'amount' => 500, 'paid_at' => now(), 'status' => 'confirmed',
        ]));

        app(PrintService::class)->send($invoice, Printer::BILL);

        [$paper] = $this->received();

        foreach (['Discount', '-75.00', 'IGST', '45.50', 'Round off', '-0.50', 'TOTAL', '970.00', '500.00', 'Balance due 470.00'] as $line) {
            $this->assertStringContainsString($line, $paper);
        }

        $this->assertStringNotContainsString('CGST', $paper);
    }

    public function test_a_cancelled_bill_says_so_and_a_reprint_says_duplicate(): void
    {
        $this->listening();
        $invoice = $this->invoice(['status' => Invoice::CANCELLED, 'cancelled_at' => now()]);

        app(PrintService::class)->send($invoice, Printer::BILL, null, true, 'Audit copy');

        [$paper] = $this->received();

        $this->assertStringContainsString('CANCELLED', $paper);
        $this->assertStringContainsString('** DUPLICATE **', $paper);
    }

    public function test_copies_print_as_many_bills_as_the_printer_asks(): void
    {
        $this->listening(['copies' => 2]);
        $invoice = $this->invoice();

        $job = app(PrintService::class)->send($invoice, Printer::BILL);

        [$paper] = $this->received();

        $this->assertSame(2, substr_count($paper, 'Bill '.$invoice->number));
        $this->assertSame(strlen($paper), $job->bytes);
    }

    public function test_a_bill_is_printed_under_its_own_shops_name_not_the_viewers(): void
    {
        $this->listening();
        $invoice = $this->invoice();
        $this->shop->forceFill(['name' => 'Dosa Corner'])->save();

        $this->resetState();
        app(PrintService::class)->send($invoice->fresh(), Printer::BILL);

        [$paper] = $this->received();

        $this->assertStringContainsString('Dosa Corner', $paper);
    }

    /* ------------------------------------------------------- the web page */

    public function test_a_format_nobody_offers_falls_back_to_a4(): void
    {
        $this->resetState();
        $this->actingAs($this->staff(['sales.invoices.print']))
            ->get(route('admin.invoices.print', [$this->invoice(), 'format' => '../etc']))
            ->assertOk()
            ->assertSee('class="print-a4', false);
    }

    public function test_every_format_links_to_the_other_two(): void
    {
        $invoice = $this->invoice();
        $staff = $this->staff(['sales.invoices.print']);

        foreach (['receipt' => ['receipt58', 'a4'], 'receipt58' => ['receipt', 'a4'], 'a4' => ['receipt', 'receipt58']] as $format => $others) {
            $this->resetState();
            $page = $this->actingAs($staff)->get(route('admin.invoices.print', [$invoice, 'format' => $format]))->assertOk();

            foreach ($others as $other) {
                $page->assertSee('format='.$other, false);
            }
        }
    }

    public function test_the_logo_is_on_the_receipt_and_its_absence_leaves_no_broken_image(): void
    {
        Storage::fake('public');

        $invoice = $this->invoice();
        $staff = $this->staff(['sales.invoices.print']);

        $this->resetState();
        $this->actingAs($staff)->get(route('admin.invoices.print', [$invoice, 'format' => 'receipt']))
            ->assertOk()
            ->assertDontSee('class="doc-logo"', false);

        $path = UploadedFile::fake()->image('logo.png', 200, 80)->store('shops', 'public');
        $this->shop->forceFill(['logo_path' => $path])->save();

        $this->resetState();
        $this->actingAs($staff)->get(route('admin.invoices.print', [$invoice, 'format' => 'receipt']))
            ->assertOk()
            ->assertSee('class="doc-logo"', false);
    }

    public function test_the_invoice_screen_offers_the_bill_printer_only_with_the_right(): void
    {
        $invoice = $this->invoice();

        $this->resetState();
        $this->actingAs($this->staff(['sales.invoices.view', 'sales.invoices.print']))
            ->get(route('admin.invoices.show', $invoice))
            ->assertOk()
            ->assertSee('data-send-bill="', false);

        $this->resetState();
        $this->actingAs($this->staff(['sales.invoices.view']))
            ->get(route('admin.invoices.show', $invoice))
            ->assertOk()
            ->assertDontSee('data-send-bill="', false);
    }

    /* ------------------------------------------------------------- FSSAI */

    public function test_fssai_is_fourteen_digits_or_nothing_and_reaches_the_bill(): void
    {
        $owner = User::query()->where('email', 'admin@erp.test')->firstOrFail();
        $payload = ['name' => $this->shop->name, 'code' => $this->shop->code];

        foreach (['1234', '1234567890123a', '123456789012345'] as $bad) {
            $this->resetState();
            $this->actingAs($owner)
                ->putJson(route('admin.shops.update', $this->shop), [...$payload, 'fssai_no' => $bad])
                ->assertStatus(422)
                ->assertJsonValidationErrors('fssai_no');
        }

        $this->resetState();
        $this->actingAs($owner)
            ->putJson(route('admin.shops.update', $this->shop), [...$payload, 'fssai_no' => '12345678901234'])
            ->assertOk();

        $this->assertSame('12345678901234', $this->shop->fresh()->fssai_no);

        $this->resetState();
        $this->actingAs($owner)
            ->get(route('admin.invoices.print', $this->invoice()))
            ->assertSee('FSSAI 12345678901234');

        // And it can be taken off again.
        $this->resetState();
        $this->actingAs($owner)
            ->putJson(route('admin.shops.update', $this->shop), [...$payload, 'fssai_no' => ''])
            ->assertOk();

        $this->assertNull($this->shop->fresh()->fssai_no);
    }

    public function test_the_fssai_migration_rolls_back_and_forward(): void
    {
        $migration = require database_path('migrations/2026_10_25_150000_add_fssai_no_to_shops_table.php');

        $migration->down();
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('shops', 'fssai_no'));

        $migration->up();
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('shops', 'fssai_no'));
    }

    /* ---------------------------------------------------------------- POS */

    public function test_the_till_has_no_recent_orders_strip_and_still_opens(): void
    {
        $this->resetState();
        $this->actingAs($this->staff(['pos.terminal.view', 'pos.terminal.create', 'sales.orders.view']))
            ->get(route('admin.pos.terminal'))
            ->assertOk()
            ->assertDontSee('Recent orders')
            ->assertDontSee('till-recent', false);
    }
}
