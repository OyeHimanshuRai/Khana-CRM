<?php

namespace Tests\Feature;

use App\Models\KitchenStation;
use App\Models\Order;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Models\Shop;
use App\Models\User;
use App\Services\PrintService;
use App\Support\CurrentShop;
use App\Support\EscPos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Printers, and the log of what came out of them (§4, §6, §8, §21).
 *
 * The things worth testing hardest are the two that bite during service:
 *
 *   1. Routing. A drink order arriving at the tandoor is worse than a drink
 *      order arriving nowhere, so the fallback is narrow on purpose and the
 *      test pins exactly how narrow.
 *
 *   2. That a dead printer never becomes an exception. The order is already
 *      taken and the guest is already waiting; a restaurant can hand-write a
 *      ticket, but it cannot un-take an order.
 *
 * No test here opens a socket to anything that might answer.
 */
class PrinterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');

        $this->seed();

        CurrentShop::forget();

        $this->actingAs($this->staff());
        CurrentShop::forget();
    }

    protected function tearDown(): void
    {
        CurrentShop::forget();

        parent::tearDown();
    }

    /* ---------------------------------------------------------- fixtures */

    private function shop(): Shop
    {
        return Shop::query()->orderBy('id')->firstOrFail();
    }

    /** @param array<int, string> $permissions */
    private function staff(array $permissions = []): User
    {
        $user = User::query()->firstWhere('email', 'printops@example.test');

        if ($user === null) {
            $user = User::create([
                'tenant_id' => $this->shop()->tenant_id,
                'name' => 'Print Ops',
                'email' => 'printops@example.test',
                'password' => 'print-password-1',
                'is_admin' => true,
            ]);

            $user->shops()->syncWithoutDetaching([$this->shop()->id => ['is_default' => true]]);
            $user->forceFill([
                'current_shop_id' => $this->shop()->id,
                'all_shops_view' => false,
            ])->save();
        }

        $user->givePermissionTo(array_merge(['dashboard.overview.view'], $permissions));

        return $user->fresh();
    }

    private function station(string $name): KitchenStation
    {
        return KitchenStation::create([
            'shop_id' => $this->shop()->id,
            'name' => $name,
            'code' => strtoupper(substr($name, 0, 3)),
            'is_active' => true,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function printer(array $overrides = []): Printer
    {
        return Printer::create(array_merge([
            'shop_id' => $this->shop()->id,
            'name' => 'Kitchen',
            'code' => 'kitchen-'.uniqid(),
            'kind' => Printer::KOT,
            'driver' => Printer::NETWORK,
            // Port 1 refuses instantly on every platform, which is a dead
            // printer without a three-second wait.
            'host' => '127.0.0.1',
            'port' => 1,
            'columns' => 42,
            'copies' => 1,
            'is_active' => true,
        ], $overrides));
    }

    /* ---------------------------------------------------------- routing */

    public function test_a_station_printer_wins_over_the_default(): void
    {
        $tandoor = $this->station('Tandoor');

        $default = $this->printer(['name' => 'Main', 'is_default' => true]);
        $own = $this->printer(['name' => 'Tandoor printer', 'kitchen_station_id' => $tandoor->id]);

        $chosen = app(PrintService::class)->printerFor(Printer::KOT, $tandoor);

        $this->assertSame($own->id, $chosen?->id);
        $this->assertNotSame($default->id, $chosen?->id);
    }

    public function test_another_stations_printer_is_never_the_fallback(): void
    {
        $tandoor = $this->station('Tandoor');
        $bar = $this->station('Bar');

        $this->printer(['name' => 'Tandoor printer', 'kitchen_station_id' => $tandoor->id]);

        $chosen = app(PrintService::class)->printerFor(Printer::KOT, $bar);

        // Drinks arriving at the tandoor is worse than drinks arriving
        // nowhere: one is a wasted ticket, the other is a wrong one.
        $this->assertNull($chosen);
    }

    public function test_the_default_takes_anything_with_no_station_printer(): void
    {
        $bar = $this->station('Bar');
        $default = $this->printer(['name' => 'Main', 'is_default' => true]);

        $this->assertSame($default->id, app(PrintService::class)->printerFor(Printer::KOT, $bar)?->id);
        $this->assertSame($default->id, app(PrintService::class)->printerFor(Printer::KOT)?->id);
    }

    public function test_no_printer_at_all_is_a_normal_answer(): void
    {
        // Which is browser printing - what this system did before printers
        // existed, and what most installs will keep doing.
        $this->assertNull(app(PrintService::class)->printerFor(Printer::KOT));
    }

    public function test_a_bill_printer_is_not_offered_a_kitchen_ticket(): void
    {
        $this->printer(['kind' => Printer::BILL, 'is_default' => true]);

        $this->assertNull(app(PrintService::class)->printerFor(Printer::KOT));
    }

    /* ---------------------------------------------------- dead printers */

    public function test_a_printer_that_cannot_be_reached_is_recorded_not_thrown(): void
    {
        $station = $this->station('Tandoor');
        $this->printer(['kitchen_station_id' => $station->id]);

        $order = $this->orderWithLines($station);

        // Nothing here throws. The order is taken and the guest is waiting.
        $jobs = app(PrintService::class)->kot($order, $station);

        $job = $jobs->firstOrFail();

        $this->assertSame(PrintJob::FAILED, $job->status);
        $this->assertNotEmpty($job->error);
        $this->assertStringContainsString('Could not reach', $job->error);
    }

    public function test_a_browser_printer_leaves_the_job_queued_and_honest(): void
    {
        $station = $this->station('Tandoor');
        $this->printer(['driver' => Printer::BROWSER, 'host' => null, 'kitchen_station_id' => $station->id]);

        $job = app(PrintService::class)->kot($this->orderWithLines($station), $station)->firstOrFail();

        // "Queued" is the truthful state: somebody still has to press print,
        // and this system will never learn whether they did.
        $this->assertSame(PrintJob::QUEUED, $job->status);
        $this->assertNull($job->error);
    }

    public function test_a_ticket_split_across_two_stations_prints_twice(): void
    {
        $tandoor = $this->station('Tandoor');
        $bar = $this->station('Bar');

        $this->printer(['name' => 'T', 'kitchen_station_id' => $tandoor->id]);
        $this->printer(['name' => 'B', 'kitchen_station_id' => $bar->id]);

        $order = $this->orderWithLines($tandoor, $bar);

        $jobs = app(PrintService::class)->kot($order);

        // Two jobs, not a duplicate: each carries only its own station's
        // dishes, which is the entire point of station routing.
        $this->assertCount(2, $jobs);
        $this->assertEqualsCanonicalizing(
            [$tandoor->id, $bar->id],
            $jobs->pluck('kitchen_station_id')->all(),
        );
    }

    /* ------------------------------------------------------- the paper */

    public function test_a_line_too_long_for_the_paper_wraps_rather_than_vanishing(): void
    {
        $out = (new EscPos(20))->line('Paneer Tikka Masala with extra cream')->bytes();

        foreach (array_filter(explode("\n", str_replace(EscPos::INIT, '', $out))) as $line) {
            $this->assertLessThanOrEqual(20, mb_strlen($line));
        }

        // Nothing is lost, only folded.
        $this->assertStringContainsString('cream', $out);
    }

    public function test_the_columns_helper_keeps_the_price_and_trims_the_name(): void
    {
        $out = (new EscPos(20))
            ->columns('A dish with a very long name indeed', '520.00')
            ->bytes();

        $line = trim(str_replace(EscPos::INIT, '', $out), "\n");

        $this->assertSame(20, mb_strlen($line));

        // The quantity and the price are the parts nobody may lose.
        $this->assertStringEndsWith('520.00', $line);
    }

    public function test_control_characters_in_a_guest_note_cannot_reach_the_printer(): void
    {
        // An escape sequence typed into a dish note would otherwise be read
        // as a command and could genuinely reconfigure the printer.
        $out = (new EscPos(42))->line("no chilli \x1B\x40\x1D\x56\x41 please")->bytes();

        $body = str_replace(EscPos::INIT, '', $out);

        $this->assertStringNotContainsString("\x1B\x40", $body);
        $this->assertStringContainsString('no chilli', $body);
    }

    public function test_the_rupee_sign_becomes_something_a_thermal_printer_can_render(): void
    {
        $out = (new EscPos(42))->line('Total ₹520')->bytes();

        $this->assertStringNotContainsString('₹', $out);
        $this->assertStringContainsString('Rs 520', $out);
    }

    /* --------------------------------------------------------- the audit */

    public function test_opening_the_kot_page_records_the_attempt(): void
    {
        $this->actingAs($this->staff(['kitchen.tickets.view', 'kitchen.tickets.print']));
        CurrentShop::forget();

        $station = $this->station('Tandoor');
        $order = $this->orderWithLines($station);

        $this->get(route('admin.kitchen.kot', $order))->assertOk();

        $job = PrintJob::query()->latest('id')->firstOrFail();

        $this->assertSame(Printer::KOT, $job->kind);
        $this->assertFalse($job->is_reprint);
    }

    public function test_the_second_copy_of_a_ticket_is_marked_a_reprint(): void
    {
        $this->actingAs($this->staff(['kitchen.tickets.view', 'kitchen.tickets.print']));
        CurrentShop::forget();

        $station = $this->station('Tandoor');
        $order = $this->orderWithLines($station);

        $this->get(route('admin.kitchen.kot', $order))->assertOk();
        $this->get(route('admin.kitchen.kot', $order))->assertOk();

        $jobs = PrintJob::query()->orderBy('id')->get();

        // §6 asks for an audit trail, and the reprint is what it is for: a
        // second copy of the same ticket is how a dish gets made twice.
        $this->assertFalse($jobs[0]->is_reprint);
        $this->assertTrue($jobs[1]->is_reprint);
    }

    public function test_a_failed_attempt_does_not_make_the_next_one_a_reprint(): void
    {
        $station = $this->station('Tandoor');
        $this->printer(['kitchen_station_id' => $station->id]);

        $order = $this->orderWithLines($station);

        // The printer was switched off, so nothing came out.
        app(PrintService::class)->kot($order, $station);
        $this->assertSame(PrintJob::FAILED, PrintJob::query()->latest('id')->firstOrFail()->status);

        $this->actingAs($this->staff(['kitchen.tickets.view', 'kitchen.tickets.print']));
        CurrentShop::forget();

        $this->get(route('admin.kitchen.kot', $order))->assertOk();

        // There was no first copy for this to be a copy of.
        $this->assertFalse(PrintJob::query()->latest('id')->firstOrFail()->is_reprint);
    }

    /* -------------------------------------------------------- the screens */

    public function test_the_printer_screens_open(): void
    {
        $this->actingAs($this->staff(['settings.printers.view']));
        CurrentShop::forget();

        $this->get(route('admin.printers.index'))->assertOk();
        $this->get(route('admin.printers.jobs'))->assertOk();
    }

    public function test_adding_a_network_printer_without_an_address_is_refused(): void
    {
        $this->actingAs($this->staff(['settings.printers.view', 'settings.printers.create']));
        CurrentShop::forget();

        $this->postJson(route('admin.printers.store'), [
            'name' => 'Tandoor', 'code' => 'tandoor',
            'kind' => Printer::KOT, 'driver' => Printer::NETWORK,
            'columns' => 42, 'copies' => 1,
        ])->assertStatus(422);
    }

    public function test_only_one_printer_is_the_default_for_a_kind(): void
    {
        $this->actingAs($this->staff(['settings.printers.view', 'settings.printers.create']));
        CurrentShop::forget();

        $first = $this->printer(['name' => 'Old default', 'is_default' => true]);

        $this->postJson(route('admin.printers.store'), [
            'name' => 'New default', 'code' => 'new-default',
            'kind' => Printer::KOT, 'driver' => Printer::BROWSER,
            'columns' => 42, 'copies' => 1, 'is_default' => 1,
        ])->assertOk();

        // Two printers both claiming the default is a coin toss on every
        // ticket, and looks exactly like an intermittent hardware fault.
        $this->assertFalse($first->fresh()->is_default);
        $this->assertSame(1, Printer::query()->where('kind', Printer::KOT)->where('is_default', true)->count());
    }

    public function test_testing_a_browser_printer_says_why_it_cannot(): void
    {
        $this->actingAs($this->staff(['settings.printers.view', 'settings.printers.print']));
        CurrentShop::forget();

        $printer = $this->printer(['driver' => Printer::BROWSER, 'host' => null]);

        $response = $this->postJson(route('admin.printers.test', $printer));

        $response->assertStatus(422);
        $this->assertStringContainsString('browser', $response->json('message'));
    }

    public function test_testing_a_dead_printer_names_the_address(): void
    {
        $this->actingAs($this->staff(['settings.printers.view', 'settings.printers.print']));
        CurrentShop::forget();

        $printer = $this->printer();

        $response = $this->postJson(route('admin.printers.test', $printer));

        $response->assertStatus(422);
        // Naming the address is what turns "it did not work" into something
        // somebody can go and check.
        $this->assertStringContainsString('127.0.0.1:1', $response->json('message'));
    }

    public function test_the_screen_is_closed_without_the_right(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        $this->get(route('admin.printers.index'))->assertForbidden();
    }

    /* ----------------------------------------------------------- helper */

    /** An order with one line at each of the given stations. */
    private function orderWithLines(KitchenStation ...$stations): Order
    {
        $order = Order::create([
            'shop_id' => $this->shop()->id,
            'order_type' => Order::DINE_IN,
            'order_number' => 'T-'.uniqid(),
            'status' => Order::PENDING,
            'payment_status' => Order::PAYMENT_PENDING,
            'placed_at' => now(),
            'subtotal' => 0,
            'grand_total' => 0,
        ]);

        foreach ($stations as $i => $station) {
            $order->items()->create([
                'product_name' => 'Dish '.($i + 1),
                'quantity' => 1,
                'unit_price' => 100,
                'line_total' => 100,
                'kitchen_station_id' => $station->id,
                'kitchen_status' => 'placed',
            ]);
        }

        return $order->fresh(['items.kitchenStation']);
    }
}
