<?php

namespace Tests\Feature;

use App\Models\Floor;
use App\Models\Plan;
use App\Models\RestaurantTable;
use App\Models\Shop;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\TableQrService;
use App\Support\CurrentShop;
use App\Support\CurrentTenant;
use App\Support\Modules;
use App\Support\PlanAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Tests\TestCase;

/**
 * The doors a guest walks through - the storefront and the QR code on the
 * table - answer to the same question as the admin panel: is this outlet
 * trading right now?
 *
 * A company somebody suspended, or one whose subscription has lapsed, used
 * to keep its storefront open and keep seating guests from the QR code; a
 * suspended company even kept accepting their orders. And the storefront is
 * a line of business like any other, so it has its own module: an outlet
 * can switch it off and a plan can leave it out.
 */
class PublicTradingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');
        $this->seed();
        $this->resetState();
    }

    private function resetState(): void
    {
        CurrentShop::forget();
        CurrentTenant::forget();
        PlanAccess::forget();
        Modules::forget();
    }

    private function shop(): Shop
    {
        return Shop::query()->withoutGlobalScopes()->orderBy('id')->firstOrFail();
    }

    private function qrToken(): string
    {
        $shop = $this->shop();
        $floor = Floor::create(['shop_id' => $shop->id, 'name' => 'Ground', 'code' => 'GF', 'is_active' => true]);
        $table = RestaurantTable::create([
            'shop_id' => $shop->id, 'floor_id' => $floor->id, 'name' => '4', 'code' => 'GF-04',
            'capacity' => 4, 'status' => RestaurantTable::AVAILABLE, 'is_active' => true,
        ]);

        return app(TableQrService::class)->issue($table)->token;
    }

    private function lapse(): void
    {
        $shop = $this->shop();
        Subscription::create([
            'tenant_id' => $shop->tenant_id, 'shop_id' => null,
            'plan_id' => Plan::query()->where('code', 'chain')->value('id'),
            'billing_period' => 'monthly', 'price' => 1, 'currency' => 'INR',
            'starts_at' => now()->subMonths(2), 'ends_at' => now()->subDays(10), 'grace_days' => 0,
        ]);
        $this->resetState();
    }

    private function suspend(): void
    {
        Tenant::query()->withoutGlobalScopes()->findOrFail($this->shop()->tenant_id)->suspend('Chargeback dispute');
        $this->resetState();
    }

    /* ------------------------------------------------------------ control */

    public function test_a_trading_outlet_is_open_on_every_door(): void
    {
        $token = $this->qrToken();

        $this->get('/shop/'.$this->shop()->slug)->assertOk();
        $this->get('/t/'.$token)->assertRedirect(route('table.show'));
        PlanAccess::assertCanTakeOrder($this->shop()->id);
        $this->assertTrue(true);
    }

    /* ------------------------------------------------------- suspended */

    public function test_a_suspended_company_takes_no_orders_from_anywhere(): void
    {
        $this->suspend();

        $this->expectException(RuntimeException::class);
        PlanAccess::assertCanTakeOrder($this->shop()->id);
    }

    public function test_a_suspended_companys_storefront_says_it_is_closed(): void
    {
        $this->suspend();

        $this->get('/shop/'.$this->shop()->slug)
            ->assertStatus(503)
            ->assertSee('not taking orders online right now');
        $this->get('/shop/'.$this->shop()->slug.'/catalog')->assertStatus(503);
        $this->post('/shop/'.$this->shop()->slug.'/cart/add', ['product_id' => 1])->assertStatus(503);
    }

    public function test_a_suspended_companys_qr_code_seats_nobody(): void
    {
        $token = $this->qrToken();
        $this->suspend();

        $this->get('/t/'.$token)->assertRedirect(route('table.closed'));
    }

    /* ----------------------------------------------------------- lapsed */

    public function test_a_lapsed_outlets_storefront_and_qr_code_are_closed(): void
    {
        $token = $this->qrToken();
        $this->lapse();

        $this->get('/shop/'.$this->shop()->slug)->assertStatus(503);
        $this->resetState();
        $this->get('/t/'.$token)->assertRedirect(route('table.closed'));
    }

    /* --------------------------------------------------- the module */

    public function test_an_outlet_can_switch_its_storefront_off(): void
    {
        $this->assertArrayHasKey('storefront', Modules::catalogue());

        $this->shop()->forceFill(['modules' => ['pos', 'dining']])->saveQuietly();
        $this->resetState();

        $this->get('/shop/'.$this->shop()->slug)->assertNotFound();

        // The QR code is Dine-in's, not the storefront's.
        $this->resetState();
        $this->get('/t/'.$this->qrToken())->assertRedirect(route('table.show'));
    }

    public function test_a_plan_without_the_storefront_has_no_storefront(): void
    {
        $plan = Plan::query()->where('code', 'starter')->firstOrFail();
        $plan->forceFill(['modules' => ['retail', 'pos']])->save();
        $shop = $this->shop();
        Subscription::create([
            'tenant_id' => $shop->tenant_id, 'shop_id' => null, 'plan_id' => $plan->id,
            'billing_period' => 'monthly', 'price' => 1, 'currency' => 'INR',
            'starts_at' => now(), 'ends_at' => now()->addMonth(), 'grace_days' => 0,
        ]);
        $this->resetState();

        $this->get('/shop/'.$shop->slug)->assertNotFound();
    }

    public function test_the_seeded_plans_sell_the_storefront(): void
    {
        foreach (['starter', 'restaurant'] as $code) {
            $this->assertContains('storefront', Plan::query()->where('code', $code)->firstOrFail()->moduleKeys());
        }
    }

    public function test_upgrading_keeps_every_existing_storefront_open(): void
    {
        $plan = Plan::query()->where('code', 'starter')->firstOrFail();
        $plan->forceFill(['modules' => ['retail', 'pos']])->save();
        $this->shop()->forceFill(['modules' => ['pos', 'dining']])->saveQuietly();

        $migration = require database_path('migrations/2026_10_30_000100_add_storefront_module.php');
        $migration->up();

        $this->assertContains('storefront', $plan->fresh()->moduleKeys());
        $this->assertContains('storefront', $this->shop()->fresh()->modules);
    }
}
