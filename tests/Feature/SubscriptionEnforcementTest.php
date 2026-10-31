<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Plan;
use App\Models\Shop;
use App\Models\Subscription;
use App\Models\User;
use App\Services\OrderService;
use App\Support\CurrentShop;
use App\Support\CurrentTenant;
use App\Support\Modules;
use App\Support\PlanAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Tests\TestCase;

/**
 * What a plan actually stops (SRS 2, 21): a lapsed outlet and a spent order
 * allowance. Each case checks the backend, never the UI.
 */
class SubscriptionEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');

        $this->seed();

        $this->resetState();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
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

    /* ---------------------------------------------------------- fixtures */

    private function shop(): Shop
    {
        return Shop::query()->withoutGlobalScopes()->orderBy('id')->firstOrFail();
    }

    private function plan(?int $orders = null): Plan
    {
        return Plan::create([
            'name' => 'Capped', 'code' => 'capped'.uniqid(), 'slug' => 'capped'.uniqid(),
            'monthly_price' => 100, 'currency' => 'INR',
            'max_orders_per_month' => $orders,
        ]);
    }

    private function subscribe(Plan $plan, ?Carbon $endsAt, bool $perShop = true): Subscription
    {
        $shop = $this->shop();

        return Subscription::create([
            'tenant_id' => $shop->tenant_id,
            'shop_id' => $perShop ? $shop->id : null,
            'plan_id' => $plan->id,
            'billing_period' => 'monthly',
            'price' => 100,
            'currency' => 'INR',
            'starts_at' => now()->subMonths(2),
            'ends_at' => $endsAt,
            'grace_days' => 0,
        ]);
    }

    private function staff(): User
    {
        $shop = $this->shop();

        $user = User::create([
            'tenant_id' => $shop->tenant_id,
            'name' => 'Counter Staff',
            'email' => 'staff'.uniqid().'@example.test',
            'password' => 'staff-password-1',
            'is_admin' => true,
        ]);

        $user->shops()->syncWithoutDetaching([$shop->id => ['is_default' => true]]);
        $user->forceFill(['current_shop_id' => $shop->id, 'all_shops_view' => false])->save();
        $user->givePermissionTo(['dashboard.overview.view', 'support.tickets.view']);

        return $user->fresh();
    }

    private function fillOrders(int $count, string $status = Order::PENDING): void
    {
        for ($i = 0; $i < $count; $i++) {
            Order::create([
                'shop_id' => $this->shop()->id,
                'order_type' => Order::DINE_IN,
                'order_number' => 'T-'.uniqid(),
                'status' => $status,
                'payment_status' => Order::PAYMENT_PENDING,
                'placed_at' => now(),
                'subtotal' => 0,
                'grand_total' => 0,
            ]);
        }
    }

    /* ---------------------------------------------------------- the gate */

    public function test_an_outlet_with_no_subscription_is_never_stopped(): void
    {
        $this->actingAs($this->staff())->get('/superadmin/support')->assertOk();

        PlanAccess::assertCanTakeOrder($this->shop()->id);
        $this->assertNull(PlanAccess::remainingOrders($this->shop()->id));
    }

    public function test_an_active_outlet_works(): void
    {
        $this->subscribe($this->plan(), now()->addMonth());

        $this->actingAs($this->staff())->get('/superadmin/support')->assertOk();
    }

    public function test_a_lapsed_outlet_is_refused_but_keeps_its_session_and_dashboard(): void
    {
        $this->subscribe($this->plan(), now()->subDay());
        $user = $this->staff();

        $this->actingAs($user)
            ->get('/superadmin/support')
            ->assertRedirect(route('admin.dashboard'))
            ->assertSessionHas('error');

        $this->assertAuthenticatedAs($user);
        $this->actingAs($user)->get('/superadmin/dashboard')->assertOk();
    }

    public function test_a_lapsed_outlet_can_still_reach_the_billing_screen_to_pay(): void
    {
        $this->subscribe($this->plan(), now()->subDay());
        $owner = $this->staff();
        $owner->givePermissionTo('settings.subscriptions.view');

        $this->actingAs($owner)->get('/superadmin/subscription')->assertOk();
    }

    public function test_whoever_can_pay_for_a_lapsed_outlet_is_sent_to_its_plans(): void
    {
        $this->subscribe($this->plan(), now()->subDay());
        $owner = $this->staff();
        $owner->givePermissionTo(['settings.subscriptions.view', 'settings.subscriptions.pay']);

        $this->actingAs($owner->fresh())
            ->get('/superadmin/support')
            ->assertRedirect(route('admin.subscription.mine'))
            ->assertSessionHas('error');
    }

    public function test_a_lapsed_outlet_gets_a_403_from_json_requests(): void
    {
        $this->subscribe($this->plan(), now()->subDay());

        $this->actingAs($this->staff())
            ->getJson('/superadmin/support')
            ->assertForbidden();
    }

    public function test_a_cancelled_outlet_is_refused(): void
    {
        $subscription = $this->subscribe($this->plan(), now()->addMonth());
        $subscription->forceFill(['cancelled_at' => now()->subMinute()])->save();

        $this->actingAs($this->staff())
            ->get('/superadmin/support')
            ->assertRedirect(route('admin.dashboard'));
    }

    public function test_super_admin_is_never_stopped(): void
    {
        $this->subscribe($this->plan(), now()->subDay());
        $admin = User::query()->where('email', 'admin@erp.test')->firstOrFail();

        $this->actingAs($admin);
        PlanAccess::assertCanTakeOrder($this->shop()->id);
        $this->addToAssertionCount(1);
    }

    /* ---------------------------------------------------- the order limit */

    public function test_orders_under_the_monthly_cap_are_allowed(): void
    {
        $this->subscribe($this->plan(3), now()->addMonth());
        $this->fillOrders(2);

        $this->assertSame(1, PlanAccess::remainingOrders($this->shop()->id));
        PlanAccess::assertCanTakeOrder($this->shop()->id);
    }

    public function test_the_order_after_the_cap_is_refused(): void
    {
        $this->subscribe($this->plan(2), now()->addMonth());
        $this->fillOrders(2);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('order limit');

        PlanAccess::assertCanTakeOrder($this->shop()->id);
    }

    public function test_cancelled_orders_do_not_use_up_the_allowance(): void
    {
        $this->subscribe($this->plan(2), now()->addMonth());
        $this->fillOrders(2, Order::CANCELLED);

        $this->assertSame(2, PlanAccess::remainingOrders($this->shop()->id));
    }

    public function test_last_months_orders_do_not_count(): void
    {
        $this->subscribe($this->plan(2), now()->addMonth());
        $this->fillOrders(2);
        Order::query()->withoutGlobalScopes()->update(['created_at' => now()->subMonths(2)]);

        $this->assertSame(2, PlanAccess::remainingOrders($this->shop()->id));
    }

    public function test_the_api_entry_point_enforces_the_cap(): void
    {
        $this->subscribe($this->plan(1), now()->addMonth());
        $this->fillOrders(1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('order limit');

        app(OrderService::class)->placeExternal([
            'shop_id' => $this->shop()->id,
            'order_type' => Order::DINE_IN,
            'items' => [['product_id' => 1, 'quantity' => 1]],
        ]);
    }

    public function test_a_lapsed_subscription_stops_new_orders_at_the_service(): void
    {
        $this->subscribe($this->plan(), now()->subDay());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not taking orders');

        app(OrderService::class)->placeExternal([
            'shop_id' => $this->shop()->id,
            'order_type' => Order::DINE_IN,
            'items' => [['product_id' => 1, 'quantity' => 1]],
        ]);
    }
}
