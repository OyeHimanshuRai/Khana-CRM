<?php

namespace Tests\Feature;

use App\Models\Floor;
use App\Models\Plan;
use App\Models\RestaurantTable;
use App\Models\Shop;
use App\Models\Subscription;
use App\Models\TableSession;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TableOrderService;
use App\Services\TableQrService;
use App\Services\TableSessionService;
use App\Support\CurrentShop;
use App\Support\CurrentTenant;
use App\Support\Modules;
use App\Support\PlanAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Tests\TestCase;

/**
 * What each plan sells actually reaches the outlet, and nothing else does -
 * by URL, by QR sticker and by API token alike.
 *
 * The admin panel was already gated; these are the doors that were not.
 */
class PlanModulesTest extends TestCase
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

    /** Put the outlet on one of the seeded plans. */
    private function onPlan(string $code): Subscription
    {
        $shop = $this->shop();
        $plan = Plan::query()->where('code', $code)->firstOrFail();

        $subscription = Subscription::create([
            'tenant_id' => $shop->tenant_id,
            'shop_id' => $shop->id,
            'plan_id' => $plan->id,
            'billing_period' => 'monthly',
            'price' => $plan->monthly_price,
            'currency' => 'INR',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'grace_days' => 0,
        ]);

        $this->resetState();

        return $subscription;
    }

    private function staff(array $permissions = []): User
    {
        $shop = $this->shop();

        $user = User::create([
            'tenant_id' => $shop->tenant_id,
            'name' => 'Outlet Manager',
            'email' => 'manager'.uniqid().'@example.test',
            'password' => 'manager-password-1',
            'is_admin' => true,
        ]);

        $user->shops()->syncWithoutDetaching([$shop->id => ['is_default' => true]]);
        $user->forceFill(['current_shop_id' => $shop->id, 'all_shops_view' => false])->save();
        $user->givePermissionTo($permissions);

        return $user->fresh();
    }

    private function codedTable(): RestaurantTable
    {
        $floor = Floor::create([
            'shop_id' => $this->shop()->id,
            'name' => 'Ground Floor',
            'code' => 'GF',
            'is_active' => true,
        ]);

        $table = RestaurantTable::create([
            'shop_id' => $floor->shop_id,
            'floor_id' => $floor->id,
            'name' => '4',
            'code' => 'GF-04',
            'capacity' => 4,
            'status' => RestaurantTable::AVAILABLE,
            'is_active' => true,
        ]);

        app(TableQrService::class)->issue($table);

        return $table->fresh(['activeQr']);
    }

    /** @return array<string, string> */
    private function apiHeaders(User $user, array $abilities): array
    {
        // A token is never more than its owner, so the owner holds the rights behind it.
        $user->givePermissionTo(array_map(\App\Support\ApiAbilities::permissionFor(...), $abilities));

        return [
            'Authorization' => 'Bearer '.$user->createToken('test', $abilities)->plainTextToken,
            'Accept' => 'application/json',
        ];
    }

    /* ----------------------------------------------------------- the menu */

    public function test_a_starter_outlet_can_build_its_menu(): void
    {
        $this->onPlan('starter');

        $this->actingAs($this->staff(['inventory.products.view', 'inventory.products.create']));

        // The till is sold; a till with no way to add a dish is not.
        $this->get(route('admin.products.index'))->assertOk();
        $this->get(route('admin.products.create'))->assertOk();
    }

    public function test_a_starter_outlet_still_does_not_get_the_stock_ledger_or_the_floor(): void
    {
        $this->onPlan('starter');

        $this->actingAs($this->staff());

        $this->assertTrue(Modules::allows('inventory.products.create'));
        $this->assertTrue(Modules::allows('inventory.categories.edit'));
        $this->assertFalse(Modules::allows('inventory.stock.view'));
        $this->assertFalse(Modules::allows('inventory.recipes.view'));
        $this->assertFalse(Modules::allows('dining.tables.view'));
        $this->assertFalse(Modules::allows('kitchen.tickets.view'));
        $this->assertFalse(Modules::allows('purchasing.orders.view'));
    }

    public function test_an_outlet_running_inventory_without_the_till_keeps_its_menu(): void
    {
        $this->shop()->forceFill(['modules' => ['inventory']])->save();

        $this->actingAs($this->staff());

        $this->assertTrue(Modules::allows('inventory.products.edit'));
        $this->assertTrue(Modules::allows('inventory.stock.view'));
    }

    /* ---------------------------------------------------- the QR sticker */

    public function test_a_sticker_stops_seating_guests_on_a_plan_without_dine_in(): void
    {
        $table = $this->codedTable();

        $this->onPlan('starter');

        $this->get('/t/'.$table->activeQr->token)
            ->assertRedirect(route('table.closed'));

        $this->assertSame(0, TableSession::allShops()->count());
    }

    public function test_a_sticker_stops_seating_guests_once_the_outlet_unticks_dine_in(): void
    {
        $table = $this->codedTable();

        $this->shop()->forceFill(['modules' => ['retail', 'pos', 'kitchen']])->save();
        $this->resetState();

        $this->get('/t/'.$table->activeQr->token)
            ->assertRedirect(route('table.closed'));
    }

    public function test_a_sticker_still_seats_guests_on_the_restaurant_plan(): void
    {
        $table = $this->codedTable();

        $this->onPlan('restaurant');

        $this->get('/t/'.$table->activeQr->token)
            ->assertRedirect(route('table.show'));

        $this->assertSame(1, TableSession::allShops()->count());
    }

    public function test_a_sitting_opened_before_a_downgrade_cannot_send_more_tickets(): void
    {
        $table = $this->codedTable();
        $session = app(TableSessionService::class)->openFor($table, $table->activeQr);

        $this->onPlan('starter');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not taking orders');

        app(TableOrderService::class)->place($session->fresh());
    }

    /* ---------------------------------------------------------- the API */

    public function test_a_suspended_business_cannot_trade_through_its_api_token(): void
    {
        $user = $this->staff();
        $headers = $this->apiHeaders($user, ['menu:read']);

        Tenant::query()->findOrFail($user->tenant_id)->suspend('Disputed invoice');
        $this->resetState();

        $this->getJson('/api/v1/menu', $headers)
            ->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    public function test_a_lapsed_outlet_cannot_trade_through_its_api_token(): void
    {
        $user = $this->staff();
        $headers = $this->apiHeaders($user, ['menu:read']);

        $this->onPlan('restaurant')->forceFill([
            'starts_at' => now()->subMonths(2),
            'ends_at' => now()->subDay(),
        ])->save();
        $this->resetState();

        $this->getJson('/api/v1/menu', $headers)->assertStatus(403);
    }

    public function test_the_tables_api_follows_dine_in(): void
    {
        $user = $this->staff();
        $headers = $this->apiHeaders($user, ['tables:read']);

        $this->getJson('/api/v1/tables', $headers)->assertOk();

        $this->onPlan('starter');

        $this->getJson('/api/v1/tables', $headers)
            ->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    public function test_an_active_outlet_api_token_still_works(): void
    {
        $this->onPlan('starter');

        $headers = $this->apiHeaders($this->staff(), ['menu:read']);

        $this->getJson('/api/v1/menu', $headers)->assertOk();
    }
}
