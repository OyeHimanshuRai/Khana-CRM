<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Floor;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\RestaurantTable;
use App\Models\Shop;
use App\Models\TableSession;
use App\Models\User;
use App\Services\TableSessionService;
use App\Support\CurrentShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The four reports §13 asks for that were missing.
 *
 * Table sales, discounts and coupons, cancelled orders, customer history.
 *
 * One of them turned up a real bug worth its own test: Invoice::counted()
 * filtered on an unqualified `status`, which is fine until a report joins a
 * table that has its own - and then it is not a wrong answer but a fatal
 * error. Every joined report that comes after this one depends on that fix.
 */
class NewReportsTest extends TestCase
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

    private function staff(): User
    {
        $user = User::query()->firstWhere('email', 'reports@example.test');

        if ($user === null) {
            $user = User::create([
                'tenant_id' => $this->shop()->tenant_id,
                'name' => 'Reports',
                'email' => 'reports@example.test',
                'password' => 'reports-password-1',
                'is_admin' => true,
            ]);

            $user->shops()->syncWithoutDetaching([$this->shop()->id => ['is_default' => true]]);
            $user->forceFill([
                'current_shop_id' => $this->shop()->id,
                'all_shops_view' => false,
            ])->save();
        }

        $user->givePermissionTo([
            'dashboard.overview.view',
            'reports.sales_report.view',
        ]);

        return $user->fresh();
    }

    private function sitting(string $tableName = '4'): TableSession
    {
        $floor = Floor::firstOrCreate(
            ['shop_id' => $this->shop()->id, 'code' => 'GF'],
            ['name' => 'Ground Floor', 'is_active' => true],
        );

        $table = RestaurantTable::create([
            'shop_id' => $floor->shop_id,
            'floor_id' => $floor->id,
            'name' => $tableName,
            'code' => 'GF-'.$tableName,
            'capacity' => 4,
            'status' => RestaurantTable::AVAILABLE,
            'is_active' => true,
        ]);

        $session = app(TableSessionService::class)->openFor($table);
        $session->forceFill(['covers' => 3])->save();

        return $session->fresh();
    }

    /**
     * A settled bill.
     *
     * Money columns are forceFilled rather than mass-assigned because the
     * model deliberately keeps them out of $fillable - a total a form could
     * set is a total nobody can trust. The test respects that rather than
     * loosening it.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function invoice(array $overrides = []): Invoice
    {
        $money = array_intersect_key($overrides, array_flip([
            'subtotal', 'grand_total', 'cost_total',
            'line_discount_total', 'invoice_discount', 'due_total',
        ]));

        $invoice = Invoice::create(array_merge([
            'shop_id' => $this->shop()->id,
            'number' => 'INV-'.uniqid(),
            'channel' => Invoice::POS,
            'status' => 'issued',
            'invoiced_at' => now(),
        ], array_diff_key($overrides, $money)));

        $invoice->forceFill(array_merge([
            'subtotal' => 1000,
            'grand_total' => 1000,
            'cost_total' => 400,
            'line_discount_total' => 0,
            'invoice_discount' => 0,
            'due_total' => 0,
        ], $money))->save();

        return $invoice->refresh();
    }

    /**
     * An order somebody cancelled.
     *
     * The cancellation columns are forceFilled: Order keeps them out of
     * $fillable so a cancellation is an audited action rather than something
     * a payload can assert. The test respects that rather than loosening it -
     * and mass-assigning them silently dropped every one, which is exactly
     * the bug the model is arranged to prevent.
     */
    private function cancelledOrder(string $number, float $total, ?int $by = null, ?string $reason = null): Order
    {
        $order = Order::create([
            'shop_id' => $this->shop()->id,
            'order_number' => $number,
            'order_type' => Order::DINE_IN,
            'status' => Order::CANCELLED,
            'payment_status' => Order::PAYMENT_PENDING,
            'subtotal' => $total,
            'grand_total' => $total,
            'placed_at' => now()->subHour(),
        ]);

        $order->forceFill([
            'cancelled_at' => now(),
            'cancelled_by' => $by,
            'cancel_reason' => $reason,
        ])->save();

        return $order->refresh();
    }

    /* ------------------------------------------------------ table sales */

    public function test_table_sales_counts_covers_and_the_average_bill(): void
    {
        $session = $this->sitting('7');

        $this->invoice(['table_session_id' => $session->id, 'grand_total' => 900]);
        $this->invoice(['table_session_id' => $session->id, 'grand_total' => 300]);

        $this->get(route('admin.reports.show', 'tables'))
            ->assertOk()
            ->assertSee('Ground Floor')
            // Two bills, 1,200 between them, so 600 each.
            ->assertSee('600.00')
            ->assertSee('1,200.00');
    }

    public function test_a_counter_sale_is_not_counted_as_a_table(): void
    {
        $this->sitting('7');

        // No table_session_id: a takeaway has no table to be busy, and
        // including it would make the busiest "table" the till.
        $this->invoice(['grand_total' => 5000]);

        $this->get(route('admin.reports.show', 'tables'))
            ->assertOk()
            ->assertSee('No table bills in this range');
    }

    public function test_the_counted_scope_survives_a_join(): void
    {
        $session = $this->sitting('7');

        // table_sessions has its own `status`, which is what made the
        // unqualified scope fatal rather than merely wrong.
        $this->invoice(['table_session_id' => $session->id]);
        $this->invoice(['table_session_id' => $session->id, 'status' => 'cancelled', 'grand_total' => 9999]);

        $response = $this->get(route('admin.reports.show', 'tables'));

        $response->assertOk();
        // And the cancelled one is still excluded, which is what the scope is
        // actually for.
        $response->assertDontSee('9,999');
    }

    /* -------------------------------------------------------- discounts */

    public function test_line_and_bill_discounts_are_reported_apart(): void
    {
        $this->invoice([
            'line_discount_total' => 40,
            'invoice_discount' => 60,
            'grand_total' => 900,
        ]);

        $this->get(route('admin.reports.show', 'discounts'))
            ->assertOk()
            // Kept apart because they are two different problems: a menu
            // priced wrong, and somebody being generous.
            ->assertSee('40.00')
            ->assertSee('60.00')
            ->assertSee('100.00');
    }

    public function test_the_discount_report_is_empty_when_nothing_was_given(): void
    {
        $this->invoice();

        $this->get(route('admin.reports.show', 'discounts'))
            ->assertOk()
            ->assertSee('Nothing was discounted');
    }

    /* -------------------------------------------------------- cancelled */

    public function test_cancelled_orders_show_their_reason_and_who_cancelled(): void
    {
        $who = $this->staff();

        $this->cancelledOrder('ORD-9', 700, $who->id, 'Guest left before the food came');

        $this->get(route('admin.reports.show', 'cancelled'))
            ->assertOk()
            ->assertSee('ORD-9')
            // A pattern in the reasons is the entire point of the report.
            ->assertSee('Guest left before the food came')
            ->assertSee('Reports');
    }

    public function test_a_cancellation_with_no_reason_is_called_out(): void
    {
        $this->cancelledOrder('ORD-10', 500);

        // It is the line an auditor asks about, so it is shown as an absence
        // rather than left blank.
        $this->get(route('admin.reports.show', 'cancelled'))
            ->assertOk()
            ->assertSee('No reason recorded');
    }

    /* -------------------------------------------------------- customers */

    public function test_customer_history_counts_visits_and_the_last_one(): void
    {
        $customer = Customer::create([
            'shop_id' => $this->shop()->id,
            'code' => 'C-1',
            'name' => 'Mrs Mehta',
            'mobile' => '9876543210',
            'is_active' => true,
        ]);

        $this->invoice(['customer_id' => $customer->id, 'grand_total' => 800]);
        $this->invoice(['customer_id' => $customer->id, 'grand_total' => 400]);

        $this->get(route('admin.reports.show', 'customers'))
            ->assertOk()
            ->assertSee('Mrs Mehta')
            ->assertSee('9876543210')
            ->assertSee('1,200.00');
    }

    public function test_walk_ins_are_left_out_rather_than_lumped_together(): void
    {
        // "Walk-in: 4,812 visits" is a number nobody can do anything with,
        // and it would sit at the top of every sort.
        $this->invoice(['grand_total' => 5000]);

        $this->get(route('admin.reports.show', 'customers'))
            ->assertOk()
            ->assertSee('No named customers in this range');
    }

    /* ---------------------------------------------------------- the list */

    public function test_all_four_appear_on_the_reports_index(): void
    {
        $this->get(route('admin.reports.index'))
            ->assertOk()
            ->assertSee('Table Sales')
            ->assertSee('Discounts & Coupons')
            ->assertSee('Cancelled Orders')
            ->assertSee('Customer History');
    }
}
