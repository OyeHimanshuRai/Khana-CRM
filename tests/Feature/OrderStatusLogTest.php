<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderStatusLog;
use App\Models\Shop;
use App\Models\User;
use App\Support\CurrentShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Every step an order took (§16).
 *
 * The reason this exists beside ActivityLog is one column: how long the order
 * sat in the previous status. ActivityLog's payload is a sentence, so getting
 * a duration out of it means parsing prose — and getting an average across a
 * service means parsing prose ten thousand times.
 *
 * The other property worth defending is that the trail is written by the
 * model rather than by callers. A log that depends on each service
 * remembering has holes exactly where somebody was in a hurry.
 */
class OrderStatusLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');

        $this->seed();

        CurrentShop::forget();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CurrentShop::forget();

        parent::tearDown();
    }

    /* ---------------------------------------------------------- fixtures */

    private function shop(): Shop
    {
        return Shop::query()->orderBy('id')->firstOrFail();
    }

    /**
     * Somebody attached to the branch.
     *
     * BelongsToShop refuses to save a row into a shop the signed-in user
     * cannot reach - which is the guard working, not a nuisance. A test that
     * signs in an unattached account is testing its own fixture.
     *
     * @param  array<int, string>  $permissions
     */
    private function staff(array $permissions = []): User
    {
        $user = User::factory()->create([
            'tenant_id' => $this->shop()->tenant_id,
            'is_admin' => true,
        ]);

        $user->shops()->syncWithoutDetaching([$this->shop()->id => ['is_default' => true]]);
        $user->forceFill([
            'current_shop_id' => $this->shop()->id,
            'all_shops_view' => false,
        ])->save();

        $user->givePermissionTo(array_merge(['dashboard.overview.view'], $permissions));

        return $user->fresh();
    }

    private function order(): Order
    {
        return Order::create([
            'shop_id' => $this->shop()->id,
            'order_number' => 'ORD-'.uniqid(),
            'order_type' => Order::DINE_IN,
            'status' => Order::PENDING,
            'payment_status' => Order::PAYMENT_PENDING,
            'subtotal' => 500,
            'grand_total' => 500,
            'placed_at' => now(),
        ]);
    }

    /* ------------------------------------------------------------ the trail */

    public function test_creating_an_order_opens_its_trail(): void
    {
        $order = $this->order();

        $logs = $order->statusLogs()->get();

        $this->assertCount(1, $logs);
        $this->assertNull($logs[0]->from_status);
        $this->assertSame(Order::PENDING, $logs[0]->to_status);

        // No previous status to have sat in. A zero here would drag every
        // average towards nothing.
        $this->assertNull($logs[0]->seconds_in_previous);
    }

    public function test_every_status_change_is_recorded_without_the_caller_asking(): void
    {
        $order = $this->order();

        // A plain save, the way any service would do it.
        $order->forceFill(['status' => Order::CONFIRMED])->save();
        $order->forceFill(['status' => Order::PREPARING])->save();

        $steps = $order->statusLogs()->get()->pluck('to_status')->all();

        $this->assertSame([Order::PENDING, Order::CONFIRMED, Order::PREPARING], $steps);
    }

    public function test_a_save_that_does_not_touch_the_status_writes_nothing(): void
    {
        $order = $this->order();

        $order->forceFill(['customer_note' => 'extra napkins'])->save();

        $this->assertCount(1, $order->statusLogs()->get());
    }

    public function test_the_wait_is_measured_from_the_previous_step(): void
    {
        Carbon::setTestNow(now());

        $order = $this->order();

        Carbon::setTestNow(now()->addMinutes(7));
        $order->forceFill(['status' => Order::CONFIRMED])->save();

        Carbon::setTestNow(now()->addMinutes(12));
        $order->forceFill(['status' => Order::PREPARING])->save();

        $logs = $order->statusLogs()->get();

        // Seven minutes pending, then twelve confirmed. This is the column
        // ActivityLog cannot give without parsing prose.
        $this->assertEqualsWithDelta(7 * 60, $logs[1]->seconds_in_previous, 2);
        $this->assertEqualsWithDelta(12 * 60, $logs[2]->seconds_in_previous, 2);
    }

    public function test_the_wait_reads_the_way_a_person_says_it(): void
    {
        $log = new OrderStatusLog(['seconds_in_previous' => 252]);

        // "4.2 minutes" is not a number anybody argues with.
        $this->assertSame('4m 12s', $log->waitLabel());
        $this->assertSame('45s', (new OrderStatusLog(['seconds_in_previous' => 45]))->waitLabel());
        $this->assertSame('1h 5m', (new OrderStatusLog(['seconds_in_previous' => 3900]))->waitLabel());
        $this->assertSame('—', (new OrderStatusLog())->waitLabel());
    }

    public function test_the_first_step_is_left_out_of_averages(): void
    {
        Carbon::setTestNow(now());

        $order = $this->order();

        Carbon::setTestNow(now()->addMinutes(5));
        $order->forceFill(['status' => Order::CONFIRMED])->save();

        $measured = OrderStatusLog::query()->measured()->get();

        // Two rows exist; only one measures anything.
        $this->assertCount(2, $order->statusLogs()->get());
        $this->assertCount(1, $measured);
    }

    public function test_a_change_nobody_signed_in_made_has_no_person_on_it(): void
    {
        $order = $this->order();

        $order->forceFill(['status' => Order::CONFIRMED])->save();

        // A scheduled sweep, a webhook, a guest's own tap. Inventing a member
        // of staff here would make the staff report a work of fiction.
        $this->assertNull($order->statusLogs()->latest('id')->first()->changed_by);
    }

    public function test_a_change_a_person_made_carries_their_name(): void
    {
        $user = $this->staff();

        $this->actingAs($user);
        CurrentShop::forget();

        $order = $this->order();
        $order->forceFill(['status' => Order::CONFIRMED])->save();

        $this->assertSame($user->id, $order->statusLogs()->latest('id')->first()->changed_by);
    }

    public function test_the_trail_belongs_to_its_own_branch(): void
    {
        $order = $this->order();

        $this->assertSame($this->shop()->id, $order->statusLogs()->first()->shop_id);
    }

    /* -------------------------------------------------------- the screen */

    public function test_the_order_screen_shows_the_timeline(): void
    {
        Carbon::setTestNow(now());

        $order = $this->order();

        Carbon::setTestNow(now()->addMinutes(6));
        $order->forceFill(['status' => Order::CONFIRMED])->save();

        $user = $this->staff(['sales.orders.view']);

        $this->actingAs($user);
        CurrentShop::forget();

        $this->get(route('admin.orders.show', $order))
            ->assertOk()
            ->assertSee('Timeline')
            ->assertSee('6m 0s');
    }
}
