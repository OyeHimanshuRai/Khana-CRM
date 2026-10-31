<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\Category;
use App\Models\Floor;
use App\Models\KitchenStation;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Reservation;
use App\Models\RestaurantTable;
use App\Models\Shop;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\User;
use App\Services\SubscriptionService;
use App\Services\TableCartService;
use App\Services\TableOrderService;
use App\Services\TableSessionService;
use App\Support\CurrentShop;
use App\Support\CurrentTenant;
use App\Support\PermissionRegistry;
use App\Support\PlatformAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The administration screens that had no HTTP test: roles and users, company
 * and branch switches, subscriptions, reservations, the kitchen board.
 */
class AdminManagementHttpTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');
        $this->seed();
        CurrentShop::forget();
        CurrentTenant::forget();

        $this->shop = Shop::query()->orderBy('id')->firstOrFail();
        $this->actingAs(User::query()->withoutGlobalScopes()->where('email', 'admin@erp.test')->firstOrFail());
        auth()->user()->forceFill(['current_shop_id' => $this->shop->id, 'all_shops_view' => false])->save();
        CurrentShop::forget();
    }

    protected function tearDown(): void
    {
        CurrentShop::forget();
        CurrentTenant::forget();

        parent::tearDown();
    }

    private function staff(string $role = 'Cashier', ?Tenant $tenant = null): User
    {
        static $n = 0;
        $n++;

        $user = User::create([
            'tenant_id' => ($tenant ?? Tenant::query()->orderBy('id')->first())->id, 'name' => "Staff {$n}", 'email' => "staff{$n}@example.test",
            'password' => 'staff-password-1', 'is_admin' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    /* ----------------------------------------------------- roles and users */

    public function test_a_role_can_be_cloned_and_its_permissions_replaced(): void
    {
        $source = Role::findByName('Manager');

        $this->post(route('admin.roles.clone', $source->id))->assertRedirect();

        $clone = Role::query()->where('name', 'like', 'Manager%')->where('id', '!=', $source->id)->first();
        $this->assertNotNull($clone);
        $this->assertSame($source->permissions->count(), $clone->permissions->count());

        $this->postJson(route('admin.roles.permissions', $clone->id), ['permissions' => ['', 'inventory.products.view']])->assertOk();
        $this->assertSame(['inventory.products.view'], $clone->fresh()->permissions->pluck('name')->all());
    }

    public function test_the_super_admin_role_cannot_be_changed(): void
    {
        $this->postJson(route('admin.roles.permissions', Role::findByName('Super Admin')->id), ['permissions' => ['x']])->assertForbidden();
    }

    public function test_a_permission_granted_to_one_user_takes_effect_at_once_and_unknown_names_are_ignored(): void
    {
        $user = $this->staff('Employee');
        $lacking = PermissionRegistry::names()->first(fn ($name) => ! $user->can($name) && str_ends_with($name, '.view')
            && ! PlatformAbilities::deniedFor($user, $name));
        $this->assertNotNull($lacking);

        $this->postJson(route('admin.users.permissions', $user->id), ['permissions' => [$lacking, 'not.a.real.permission']])->assertOk();

        $fresh = $user->fresh();
        $this->assertTrue($fresh->can($lacking));
        $this->assertSame([$lacking], $fresh->getDirectPermissions()->pluck('name')->all());
    }

    public function test_a_platform_only_right_does_nothing_for_a_companys_staff_even_when_granted_by_hand(): void
    {
        $user = $this->staff('Tenant Owner');
        $platformOnly = PermissionRegistry::names()->first(fn ($name) => PlatformAbilities::deniedFor($user, $name));
        $this->assertNotNull($platformOnly, 'there are rights reserved for the platform');

        $this->postJson(route('admin.users.permissions', $user->id), ['permissions' => [$platformOnly]])->assertOk();

        $this->assertTrue($user->fresh()->hasDirectPermission($platformOnly), 'it was saved...');
        $this->assertFalse($user->fresh()->can($platformOnly), '...but it grants nothing');
    }

    public function test_users_can_be_removed_in_bulk_but_never_the_person_doing_it(): void
    {
        $a = $this->staff();
        $b = $this->staff();
        $me = auth()->user();

        $this->delete(route('admin.users.bulk-destroy'), ['ids' => "{$a->id},{$b->id},{$me->id}"])->assertRedirect();

        $this->assertNull(User::query()->withoutGlobalScopes()->find($a->id));
        $this->assertNull(User::query()->withoutGlobalScopes()->find($b->id));
        $this->assertNotNull(User::query()->withoutGlobalScopes()->find($me->id), 'you cannot delete yourself');
    }

    public function test_the_user_list_exports_as_a_csv(): void
    {
        $this->staff();

        $response = $this->get(route('admin.users.export'))->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
    }

    /* ---------------------------------------- companies, branches, plans */

    public function test_suspending_a_company_locks_its_staff_out_and_reinstating_lets_them_in(): void
    {
        $tenant = Tenant::create(['name' => 'Second Co', 'code' => 'SEC', 'slug' => 'second-co', 'is_active' => true]);
        $staff = $this->staff('Tenant Owner', $tenant);

        $this->putJson(route('admin.tenants.status', $tenant->id), ['reason' => 'Unpaid invoice'])->assertOk()->assertJsonPath('data.is_active', false);

        $this->assertTrue($tenant->fresh()->isSuspended());
        CurrentTenant::forget();
        $this->actingAs($staff);
        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));

        $this->actingAs(User::query()->withoutGlobalScopes()->where('email', 'admin@erp.test')->firstOrFail());
        CurrentTenant::forget();
        $this->putJson(route('admin.tenants.status', $tenant->id))->assertOk()->assertJsonPath('data.is_active', true);
        $this->assertFalse($tenant->fresh()->isSuspended());
    }

    public function test_the_last_active_company_cannot_be_suspended(): void
    {
        $only = Tenant::query()->where('is_active', true)->get();
        $this->assertCount(1, $only);

        $this->putJson(route('admin.tenants.status', $only->first()->id))->assertStatus(422);
        $this->assertTrue($only->first()->fresh()->is_active);
    }

    public function test_a_branch_can_be_switched_off_and_on(): void
    {
        $second = Shop::create(['tenant_id' => $this->shop->tenant_id, 'name' => 'Branch Two', 'code' => 'BR2', 'slug' => 'branch-two', 'is_active' => true]);

        $this->putJson(route('admin.shops.status', $second->id))->assertOk();
        $this->assertFalse((bool) $second->fresh()->is_active);

        $this->putJson(route('admin.shops.status', $second->id))->assertOk();
        $this->assertTrue((bool) $second->fresh()->is_active);
    }

    public function test_a_plan_is_created_and_validated(): void
    {
        $this->postJson(route('admin.plans.store'), ['name' => 'Bad', 'code' => 'bad', 'monthly_price' => -5, 'currency' => 'INR'])
            ->assertStatus(422)->assertJsonValidationErrors('monthly_price');

        $this->postJson(route('admin.plans.store'), ['name' => 'Cafe Lite', 'code' => 'cafe-lite', 'monthly_price' => 499, 'currency' => 'INR'])
            ->assertOk()->assertJsonPath('success', true);

        $this->assertTrue(Plan::query()->where('code', 'cafe-lite')->exists());
    }

    public function test_a_company_is_put_on_a_plan_pays_and_is_cancelled_and_resumed(): void
    {
        $tenant = Tenant::create(['name' => 'Plan Co', 'code' => 'PLC', 'slug' => 'plan-co', 'is_active' => true]);
        $plan = Plan::query()->where('slug', 'starter')->firstOrFail();

        $this->postJson(route('admin.subscriptions.store', $tenant->id), ['plan_id' => $plan->id, 'billing_period' => 'monthly', 'trial_days' => 7])
            ->assertOk();
        $this->assertTrue($tenant->subscription()->first()->onTrial());

        $this->postJson(route('admin.subscriptions.payment.store', $tenant->id), ['amount' => 799, 'method' => 'upi', 'provider_reference' => 'UTR123456'])
            ->assertOk();
        $this->assertFalse($tenant->subscription()->first()->onTrial(), 'paying ends the trial');

        $this->putJson(route('admin.subscriptions.cancel', $tenant->id), ['note' => 'Closing down'])->assertOk();
        $this->assertNotNull($tenant->subscription()->first()->cancelled_at);

        $this->putJson(route('admin.subscriptions.resume', $tenant->id))->assertOk();
        $this->assertNull($tenant->subscription()->first()->cancelled_at);
    }

    public function test_a_subscription_payment_needs_a_known_method(): void
    {
        $tenant = Tenant::create(['name' => 'Pay Co', 'code' => 'PAY', 'slug' => 'pay-co', 'is_active' => true]);
        app(SubscriptionService::class)->subscribe($tenant, Plan::query()->where('slug', 'starter')->firstOrFail());

        $this->postJson(route('admin.subscriptions.payment.store', $tenant->id), ['amount' => 100, 'method' => 'barter'])
            ->assertStatus(422)->assertJsonValidationErrors('method');
    }

    /* ----------------------------------------------------------- alerts */

    public function test_alerts_are_marked_read_one_by_one_and_all_at_once(): void
    {
        foreach (['a', 'b', 'c'] as $k) {
            Alert::query()->create([
                'shop_id' => $this->shop->id, 'type' => 'test', 'level' => 'info', 'title' => "Alert {$k}",
                'dedupe_key' => "t-{$k}", 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $first = Alert::query()->withoutGlobalScopes()->where('dedupe_key', 't-a')->first();

        $this->putJson(route('admin.alerts.read', $first->id))->assertOk();
        $this->assertNotNull($first->fresh()->read_at);

        $this->putJson(route('admin.alerts.read-all'))->assertOk();
        $this->assertSame(0, Alert::query()->withoutGlobalScopes()->where('shop_id', $this->shop->id)->whereNull('read_at')->count());
    }

    /* ------------------------------------------------------ reservations */

    private function table(int $seats = 4): RestaurantTable
    {
        static $n = 0;
        $n++;

        $floor = Floor::query()->first() ?? Floor::create(['shop_id' => $this->shop->id, 'name' => 'Main', 'code' => 'MN', 'is_active' => true]);

        return RestaurantTable::create([
            'shop_id' => $this->shop->id, 'floor_id' => $floor->id, 'name' => "T{$n}", 'code' => "MN-{$n}",
            'capacity' => $seats, 'status' => RestaurantTable::AVAILABLE, 'is_active' => true,
        ]);
    }

    private function book(?RestaurantTable $table = null, int $hours = 3): int
    {
        $this->postJson(route('admin.reservations.store'), array_filter([
            'guest_name' => 'Mehta Family', 'guest_mobile' => '9811100002', 'party_size' => 4,
            'reserved_for' => now()->addHours($hours)->format('Y-m-d H:i:s'), 'duration_minutes' => 90,
            'restaurant_table_id' => $table?->id, 'source' => 'phone',
        ]))->assertOk()->assertJsonPath('success', true);

        return (int) Reservation::query()->withoutGlobalScopes()->latest('id')->value('id');
    }

    public function test_a_booking_is_confirmed_seated_and_completed(): void
    {
        $table = $this->table();
        $id = $this->book($table);

        $this->putJson(route('admin.reservations.confirm', $id))->assertOk();
        $this->assertSame('confirmed', Reservation::query()->withoutGlobalScopes()->find($id)->status);

        $this->putJson(route('admin.reservations.seat', $id))->assertOk();
        $this->assertSame('seated', Reservation::query()->withoutGlobalScopes()->find($id)->status);
        $this->assertSame('occupied', $table->fresh()->status, 'seating a party occupies the table');

        $this->putJson(route('admin.reservations.complete', $id))->assertOk();
        $this->assertSame('completed', Reservation::query()->withoutGlobalScopes()->find($id)->status);
    }

    public function test_a_cancelled_or_no_show_booking_frees_the_slot_for_somebody_else(): void
    {
        $table = $this->table();
        $first = $this->book($table);

        // The same table and time is taken...
        $this->postJson(route('admin.reservations.store'), [
            'guest_name' => 'Rival Party', 'party_size' => 2, 'reserved_for' => now()->addHours(3)->format('Y-m-d H:i:s'),
            'duration_minutes' => 90, 'restaurant_table_id' => $table->id,
        ])->assertStatus(422);

        // ...until the first booking is let go.
        $this->putJson(route('admin.reservations.cancel', $first), ['outcome_note' => 'Called to cancel'])->assertOk();

        $this->postJson(route('admin.reservations.store'), [
            'guest_name' => 'Rival Party', 'party_size' => 2, 'reserved_for' => now()->addHours(3)->format('Y-m-d H:i:s'),
            'duration_minutes' => 90, 'restaurant_table_id' => $table->id,
        ])->assertOk();

        $second = (int) Reservation::query()->withoutGlobalScopes()->latest('id')->value('id');
        $this->putJson(route('admin.reservations.no-show', $second))->assertOk();
        $this->assertSame('no_show', Reservation::query()->withoutGlobalScopes()->find($second)->status);
    }

    public function test_a_booking_needs_a_guest_a_party_size_and_a_time(): void
    {
        $this->postJson(route('admin.reservations.store'), [])->assertStatus(422)
            ->assertJsonValidationErrors(['guest_name', 'party_size', 'reserved_for']);
    }

    /* ---------------------------------------------------- kitchen board */

    private function orderOnTheBoard(): Order
    {
        $station = KitchenStation::create(['shop_id' => $this->shop->id, 'name' => 'Main', 'code' => 'MK', 'prep_minutes' => 20, 'is_default' => true, 'is_active' => true]);
        $category = Category::create(['tenant_id' => $this->shop->tenant_id, 'name' => 'Mains', 'slug' => Category::uniqueSlug('Mains'), 'is_active' => true]);

        $dish = new Product([
            'name' => 'Dal Makhani', 'slug' => Product::uniqueSlug('Dal Makhani'), 'sku' => Product::generateSku('DAL'),
            'category_id' => $category->id, 'unit_id' => Unit::where('code', 'PCS')->value('id'), 'tax_rate_id' => TaxRate::where('rate', 5)->value('id'),
            'purchase_price' => 60, 'mrp' => 260, 'selling_price' => 260, 'food_type' => 'veg',
            'is_made_to_order' => true, 'is_active' => true, 'is_published' => true,
        ]);
        $dish->tenant_id = $this->shop->tenant_id;
        $dish->save();

        $session = app(TableSessionService::class)->openFor($this->table());
        app(TableCartService::class)->add($session, $dish, null, 2);

        return app(TableOrderService::class)->place($session, ['name' => 'Guest']);
    }

    public function test_the_kitchen_moves_a_ticket_forward_and_can_recall_it(): void
    {
        $order = $this->orderOnTheBoard();
        $line = $order->items()->first();
        $this->assertSame('pending', $line->fresh()->kitchen_status);

        $this->get(route('admin.kitchen.index'))->assertOk()->assertSee('Dal Makhani');

        $rung = fn () => array_search($line->fresh()->kitchen_status, Order::KITCHEN_FLOW, true);

        $this->putJson(route('admin.kitchen.bump-line', $line->id))->assertOk();
        $afterLine = $rung();
        $this->assertGreaterThan(0, $afterLine, 'bumping a line moves it up the ladder');

        $this->putJson(route('admin.kitchen.bump', $order->id))->assertOk();
        $afterTicket = $rung();
        $this->assertGreaterThan($afterLine, $afterTicket);

        $this->putJson(route('admin.kitchen.recall', $order->id), ['to' => Order::CONFIRMED, 'reason' => 'Plate came back cold'])->assertOk();
        $this->assertLessThan($afterTicket, $rung(), 'a recall steps the ticket back');
    }

    public function test_a_cashier_cannot_bump_a_kitchen_ticket(): void
    {
        $order = $this->orderOnTheBoard();
        $line = $order->items()->first();

        $cashier = $this->staff('Cashier');
        $cashier->shops()->syncWithoutDetaching([$this->shop->id => ['is_default' => true]]);
        $cashier->forceFill(['current_shop_id' => $this->shop->id, 'all_shops_view' => false])->save();
        CurrentShop::forget();
        $this->actingAs($cashier);

        $this->putJson(route('admin.kitchen.bump-line', $line->id))->assertForbidden();
        $this->assertSame('pending', $line->fresh()->kitchen_status);
    }
}
