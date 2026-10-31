<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Customer;
use App\Models\Floor;
use App\Models\KitchenStation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\RestaurantTable;
use App\Models\Shop;
use App\Models\TableSession;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ReportService;
use App\Services\TableCartService;
use App\Services\TableOrderService;
use App\Services\TableQrService;
use App\Support\CurrentShop;
use App\Support\CurrentTenant;
use App\Support\ReportFilters;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The holes a project-wide tenancy audit found, each pinned shut.
 *
 * Every one of these was a way for one restaurant's account to read or change
 * another's data - or the whole platform's - without any screen offering it:
 * a role nobody should be able to tick, a row bound from a URL with no scope,
 * a report that joined past the scope.
 */
class TenancyAuditTest extends TestCase
{
    use RefreshDatabase;

    private Shop $ourShop;

    private Tenant $theirs;

    private Shop $theirShop;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');

        $this->seed();

        $this->ourShop = Shop::query()->orderBy('id')->firstOrFail();
        $this->theirs = Tenant::create(['name' => 'Rival Foods', 'code' => 'RIVAL', 'slug' => 'rival-foods']);
        $this->theirShop = Shop::query()->create([
            'tenant_id' => $this->theirs->id, 'name' => 'Rival Kitchen', 'code' => 'RK',
            'slug' => 'rival-kitchen', 'is_active' => true,
        ]);
    }

    /** @param array<int, string> $permissions */
    private function rivalOwner(array $permissions = []): User
    {
        $owner = User::create([
            'tenant_id' => $this->theirs->id, 'name' => 'Rival Owner', 'email' => 'owner@rival.test',
            'password' => 'owner-password-1', 'is_admin' => true,
        ]);
        $owner->shops()->attach($this->theirShop->id, ['is_default' => true]);
        $owner->givePermissionTo(['dashboard.overview.view', ...$permissions]);

        return $owner->fresh();
    }

    private function signIn(User $user): void
    {
        $this->actingAs($user);
        CurrentTenant::forget();
        CurrentShop::forget();
    }

    /* ------------------------------------------------- role escalation */

    public function test_a_restaurant_owner_cannot_make_a_super_admin(): void
    {
        $this->signIn($this->rivalOwner(['settings.users.view', 'settings.users.create', 'settings.users.edit']));

        $this->get(route('admin.users.create'))->assertOk()->assertDontSee('value="'.User::SUPER_ADMIN.'"', false);

        $this->post(route('admin.users.store'), [
            'name' => 'Sneaky', 'email' => 'sneaky@rival.test',
            'password' => 'sneaky-password-1', 'password_confirmation' => 'sneaky-password-1',
            'is_admin' => 1, 'roles' => [User::SUPER_ADMIN],
        ])->assertSessionHasErrors('roles.0');

        $this->assertDatabaseMissing('users', ['email' => 'sneaky@rival.test']);
    }

    public function test_a_restaurant_owner_cannot_promote_their_staff_to_super_admin(): void
    {
        $owner = $this->rivalOwner(['settings.users.view', 'settings.users.edit']);
        $waiter = User::create([
            'tenant_id' => $this->theirs->id, 'name' => 'Waiter', 'email' => 'waiter@rival.test',
            'password' => 'waiter-password-1', 'is_admin' => true,
        ]);

        $this->signIn($owner);

        $this->put(route('admin.users.update', $waiter), [
            'name' => 'Waiter', 'email' => 'waiter@rival.test', 'is_admin' => 1, 'roles' => [User::SUPER_ADMIN],
        ])->assertSessionHasErrors('roles.0');

        $this->assertFalse($waiter->fresh()->isSuperAdmin());
    }

    public function test_the_super_admin_can_still_make_another(): void
    {
        $this->signIn(User::query()->withoutGlobalScopes()->where('email', 'admin@erp.test')->firstOrFail());

        $this->post(route('admin.users.store'), [
            'name' => 'Second Admin', 'email' => 'second@erp.test',
            'password' => 'second-password-1', 'password_confirmation' => 'second-password-1',
            'is_admin' => 1, 'roles' => [User::SUPER_ADMIN],
        ])->assertSessionHasNoErrors();

        $this->assertTrue(User::query()->withoutGlobalScopes()->where('email', 'second@erp.test')->firstOrFail()->isSuperAdmin());
    }

    /* ------------------------------------------------ kitchen lines */

    private function ourKitchenLine(): OrderItem
    {
        $station = new KitchenStation(['shop_id' => $this->ourShop->id, 'name' => 'Main', 'code' => 'MK', 'prep_minutes' => 15, 'is_active' => true]);
        $station->save();
        $station->makeDefault();

        $dish = new Product(['name' => 'Dal Makhani', 'slug' => 'dal-makhani', 'sku' => 'DM-1', 'selling_price' => 280, 'is_active' => true]);
        $dish->tenant_id = $this->ourShop->tenant_id;
        $dish->save();

        $floor = Floor::query()->firstOrCreate(['shop_id' => $this->ourShop->id, 'code' => 'GF'], ['name' => 'Ground Floor', 'is_active' => true]);
        $table = new RestaurantTable([
            'shop_id' => $this->ourShop->id, 'floor_id' => $floor->id, 'name' => '04', 'code' => 'GF-04',
            'capacity' => 4, 'status' => RestaurantTable::AVAILABLE, 'is_active' => true,
        ]);
        $table->save();
        app(TableQrService::class)->issue($table);

        $this->get('/t/'.$table->fresh('activeQr')->activeQr->token)->assertRedirect(route('table.show'));
        $session = TableSession::allShops()->live()->firstOrFail();
        app(TableCartService::class)->add($session, $dish);

        return app(TableOrderService::class)->place($session)->items()->firstOrFail();
    }

    public function test_another_restaurants_kitchen_line_cannot_be_bound_from_a_url(): void
    {
        $line = $this->ourKitchenLine();

        $this->signIn($this->rivalOwner());
        $this->assertNull((new OrderItem())->resolveRouteBinding($line->id));

        $this->signIn(User::query()->withoutGlobalScopes()->where('email', 'admin@erp.test')->firstOrFail());
        $this->assertNotNull((new OrderItem())->resolveRouteBinding($line->id));
    }

    /* ------------------------------------------------------ reports */

    public function test_the_coupon_report_counts_only_this_restaurants_redemptions(): void
    {
        foreach ([[$this->ourShop, 'OURS10'], [$this->theirShop, 'THEIRS10']] as [$shop, $code]) {
            $coupon = Coupon::allShops()->create([
                'shop_id' => $shop->id, 'code' => $code, 'type' => 'percent', 'value' => 10, 'is_active' => true,
            ]);

            $customer = Customer::allShops()->create([
                'shop_id' => $shop->id, 'code' => Customer::nextCode($shop->id), 'name' => 'Guest '.$code,
                'mobile' => '90000'.$shop->id.'0000', 'type' => 'retail', 'is_active' => true,
            ]);

            $order = Order::allShops()->create([
                'shop_id' => $shop->id, 'order_number' => 'ORD-'.$code, 'placed_at' => now(), 'customer_id' => $customer->id,
            ]);

            CouponRedemption::query()->create([
                'shop_id' => $shop->id, 'coupon_id' => $coupon->id, 'customer_id' => $customer->id,
                'order_id' => $order->id, 'discount_amount' => 50,
            ]);
        }

        $this->signIn($this->rivalOwner());

        $codes = app(ReportService::class)
            ->couponsRedeemed(ReportFilters::fromRequest(Request::create('/', 'GET', ['preset' => 'this_month'])))
            ->pluck('code')
            ->all();

        $this->assertSame(['THEIRS10'], $codes);
    }
}
