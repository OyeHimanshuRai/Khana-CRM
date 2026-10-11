<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\User;
use App\Support\CurrentShop;
use App\Support\Modules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Tests\Concerns\ReadsErp;
use Tests\Concerns\TalksToErp;
use Tests\TestCase;

/**
 * "Food cost today" on the home screen, read from ERP.
 *
 * Drawn for one outlet linked to ERP, for a reader who may see profit
 * figures - the same pair of checks as the profit tile - and fetched after
 * the page is up: the percentage, the cost and the sales it is a share of,
 * and a warning when dishes sold have no recipe. Left out entirely for an
 * outlet with CRM alone and in the All-shops view.
 */
class ErpFoodCostTest extends TestCase
{
    use ReadsErp, RefreshDatabase, TalksToErp;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');
        CurrentShop::forget();

        $this->shop = Shop::query()->orderBy('id')->firstOrFail();
    }

    protected function tearDown(): void
    {
        CurrentShop::forget();

        parent::tearDown();
    }

    private function reader(array $permissions = ['dashboard.overview.view', 'reports.profit_report.view']): User
    {
        static $counter = 0;
        $counter++;

        $user = User::create([
            'name' => 'Owner '.$counter,
            'email' => "foodcost{$counter}@example.test",
            'password' => 'owner-password-1',
            'is_admin' => true,
        ]);

        $user->givePermissionTo($permissions);
        $user->shops()->attach($this->shop->id, ['is_default' => true]);
        $user->forceFill(['current_shop_id' => $this->shop->id])->save();

        return $user;
    }

    /** One business day, as contracts/api/erp.yaml shapes it. */
    private function day(string $outlet, array $with = []): array
    {
        return array_replace([
            'outlet_id' => $outlet,
            'business_date' => '2026-10-01',
            'sales_paise' => 1000000,
            'cost_paise' => 325000,
            'food_cost_percent' => '32.50',
            'uncosted' => 3,
        ], $with);
    }

    private function tile(?User $as = null)
    {
        return $this->actingAs($as ?? $this->reader())->get(route('admin.dashboard.food-cost'));
    }

    /* ------------------------------------------------------------ linked */

    public function test_the_home_screen_carries_the_tile_and_does_not_wait_for_erp(): void
    {
        $this->linkToAccounts($this->shop);
        $this->erpWouldAnswerAnything();

        $this->actingAs($this->reader())->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Food cost today')
            ->assertSee('data-erp-panel="'.route('admin.dashboard.food-cost').'"', false);

        Http::assertNothingSent();
    }

    public function test_the_tile_shows_the_share_the_cost_and_the_sales_and_warns_of_dishes_with_no_recipe(): void
    {
        $ids = $this->linkToAccounts($this->shop);
        $this->erpAnswers($ids['outlet'], ['food-cost' => $this->day($ids['outlet'])]);

        $this->tile()
            ->assertOk()
            ->assertSeeInOrder(['32.5%', 'of sales on 01 Oct 2026', 'What the food cost', '₹3,250', 'Sales, before tax', '₹10,000'])
            ->assertSee('3 dishes have no recipe, so the real figure is higher.');

        // The outlet's current day: no date is named.
        Http::assertSent(fn ($request) => $request->url() === $this->erpEndpoint($ids['outlet'], 'food-cost'));
        $this->assertErpAsked(1);
    }

    public function test_one_dish_with_no_recipe_is_said_in_the_singular_and_none_says_nothing(): void
    {
        $ids = $this->linkToAccounts($this->shop);
        $this->erpAnswers($ids['outlet'], ['food-cost' => $this->day($ids['outlet'], ['uncosted' => 1])]);

        $this->tile()->assertOk()->assertSee('1 dish has no recipe, so the real figure is higher.');
    }

    public function test_a_day_with_nothing_costed_is_not_a_food_cost_of_nought(): void
    {
        $ids = $this->linkToAccounts($this->shop);
        $reader = $this->reader();

        $this->erpAnswers($ids['outlet'], ['food-cost' => $this->day($ids['outlet'], [
            'sales_paise' => 0, 'cost_paise' => 0, 'food_cost_percent' => null, 'uncosted' => 0,
        ])]);

        $this->tile($reader)
            ->assertOk()
            ->assertSee('nothing sold yet on 01 Oct 2026')
            ->assertDontSee('0%')
            ->assertDontSee('no recipe, so the real figure');
    }

    public function test_sales_with_no_recipe_behind_any_of_them_say_so(): void
    {
        $ids = $this->linkToAccounts($this->shop);

        $this->erpAnswers($ids['outlet'], ['food-cost' => $this->day($ids['outlet'], [
            'sales_paise' => 50000, 'cost_paise' => 0, 'food_cost_percent' => null, 'uncosted' => 2,
        ])]);

        $this->tile()
            ->assertOk()
            ->assertSee('nothing sold on 01 Oct 2026 has a recipe yet')
            ->assertSee('2 dishes have no recipe, so the real figure is higher.');
    }

    public function test_with_erp_down_the_tile_says_so_and_the_home_screen_still_opens(): void
    {
        $this->linkToAccounts($this->shop);
        $this->erpIsDown();
        $reader = $this->reader();

        $this->actingAs($reader)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Food cost today')
            ->assertSee('Gross margin');

        $this->tile($reader)
            ->assertOk()
            ->assertSee('ERP is not reachable right now')
            ->assertDontSee('What the food cost');
    }

    /* ----------------------------------------------------- who sees it */

    public function test_a_reader_who_may_not_see_profit_figures_gets_no_tile(): void
    {
        $this->linkToAccounts($this->shop);
        $this->erpWouldAnswerAnything();
        $cashier = $this->reader(['dashboard.overview.view', 'reports.sales_report.view']);

        $this->actingAs($cashier)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertDontSee('Food cost today')
            ->assertDontSee('data-erp-panel', false);

        $this->tile($cashier)->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_an_outlet_that_runs_no_reports_gets_no_tile_even_for_its_owner(): void
    {
        $this->linkToAccounts($this->shop);
        $this->erpWouldAnswerAnything();
        $this->shop->forceFill(['modules' => array_values(array_diff(Modules::keys(), ['reports']))])->save();
        $owner = $this->reader();

        $this->actingAs($owner)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertDontSee('Food cost today');

        $this->tile($owner)->assertForbidden();

        Http::assertNothingSent();
    }

    /* --------------------------------------------- CRM alone, All shops */

    public function test_an_outlet_with_crm_alone_gets_no_tile_and_erp_is_asked_nothing(): void
    {
        // The seeded shop holds CRM and nothing else.
        $this->erpWouldAnswerAnything();
        $owner = $this->reader();

        $this->actingAs($owner)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Gross margin')
            ->assertDontSee('Food cost today')
            ->assertDontSee('data-erp-panel', false);

        $this->tile($owner)->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_the_all_shops_view_leaves_the_tile_out(): void
    {
        $this->linkToAccounts($this->shop);
        $this->erpWouldAnswerAnything();

        $second = Shop::create([
            'tenant_id' => $this->shop->tenant_id,
            'name' => 'Second Outlet', 'code' => 'SEC', 'slug' => 'second-outlet',
            'invoice_prefix' => 'INV', 'pos_prefix' => 'POS',
            'currency' => 'INR', 'timezone' => 'Asia/Kolkata', 'is_active' => true,
        ]);
        $this->linkToAccounts($second);

        $owner = $this->reader();
        $owner->shops()->attach($second->id);
        $owner->forceFill(['current_shop_id' => null, 'all_shops_view' => true])->save();

        $this->actingAs($owner)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('All shops')
            ->assertDontSee('Food cost today');

        $this->tile($owner)->assertNotFound();

        Http::assertNothingSent();
    }
}
