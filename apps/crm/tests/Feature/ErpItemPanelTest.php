<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\Unit;
use App\Models\User;
use App\Support\CurrentShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Khana\Kit\Support\Ulid;
use Tests\Concerns\ReadsErp;
use Tests\Concerns\TalksToErp;
use Tests\TestCase;

/**
 * Stock and cost on a menu item's page, read from ERP.
 *
 * For an outlet linked to ERP the page carries a panel that is fetched after
 * it has drawn: how many portions the stock covers, what one costs, the food
 * cost percentage and whether ERP has it on sale - for the dish, or for each
 * size under the name this menu gives it. ERP being down is said in that
 * panel and nowhere else.
 *
 * An outlet with CRM alone sees a line saying what ERP would add, and ERP is
 * asked nothing on its behalf.
 *
 * And because ERP's cost overwrites the one on the form, the form says where
 * the figure comes from and stops offering to take one.
 */
class ErpItemPanelTest extends TestCase
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

    /* ---------------------------------------------------------- fixtures */

    private function staff(array $permissions = ['inventory.products.view']): User
    {
        static $counter = 0;
        $counter++;

        $user = User::create([
            'name' => 'Menu Staff '.$counter,
            'email' => "erppanel{$counter}@example.test",
            'password' => 'staff-password-1',
            'is_admin' => true,
        ]);

        $user->givePermissionTo($permissions);
        $user->shops()->attach($this->shop->id, ['is_default' => true]);
        $user->forceFill(['current_shop_id' => $this->shop->id])->save();

        return $user;
    }

    private function dish(string $name, float $price = 250): Product
    {
        return Product::create([
            'name' => $name,
            'slug' => Product::uniqueSlug($name),
            'sku' => Product::generateSku($name),
            'unit_id' => Unit::query()->withoutGlobalScopes()->where('code', 'PCS')->value('id'),
            'selling_price' => $price,
            'purchase_price' => 81.25,
            'tax_inclusive' => true,
            'is_active' => true,
        ])->fresh();
    }

    private function sizeOf(Product $dish, string $name, int $order): ProductVariant
    {
        return ProductVariant::create([
            'product_id' => $dish->id, 'name' => $name, 'price' => 200, 'sort_order' => $order,
        ])->fresh();
    }

    /** One size's entry, as contracts/api/erp.yaml shapes it. */
    private function entry(?string $variant, array $with = []): array
    {
        return array_replace([
            'variant_id' => $variant,
            'portions_available' => '12.0000',
            'cost_paise' => 8125,
            'food_cost_percent' => '32.50',
            'available' => true,
        ], $with);
    }

    private function panel(Product $dish, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->staff())->get(route('admin.products.erp-stock', $dish));
    }

    /* ------------------------------------------------------------ linked */

    public function test_a_linked_outlets_item_page_carries_the_panel_and_does_not_wait_for_erp(): void
    {
        $this->linkToAccounts($this->shop);
        $this->erpWouldAnswerAnything();
        $dish = $this->dish('Paneer Tikka');

        $this->actingAs($this->staff())->get(route('admin.products.show', $dish))
            ->assertOk()
            ->assertSee('Stock and cost, from ERP')
            ->assertSee('data-erp-panel="'.route('admin.products.erp-stock', $dish).'"', false)
            ->assertDontSee('CRM does not keep stock');

        // Drawn without a word to ERP; the panel is a second request.
        Http::assertNothingSent();
    }

    public function test_the_panel_shows_what_the_stock_covers_and_what_a_portion_costs(): void
    {
        $ids = $this->linkToAccounts($this->shop);
        $dish = $this->dish('Paneer Tikka');

        $this->erpAnswers($ids['outlet'], ["items/{$dish->ulid}/stock" => [
            'item_id' => $dish->ulid,
            'has_recipe' => true,
            'sizes' => [$this->entry(null, ['portions_available' => '12.7500'])],
        ]]);

        $this->panel($dish)
            ->assertOk()
            ->assertSeeInOrder(['Stock covers', '12 portions', 'Cost of a portion', '₹81.25', 'Food cost', '32.5%', 'On sale'])
            ->assertDontSee('badge-danger', false);

        Http::assertSent(fn ($request) => $request->url() === $this->erpEndpoint($ids['outlet'], "items/{$dish->ulid}/stock"));
        $this->assertErpAsked(1);
    }

    public function test_each_size_is_shown_under_this_menus_name_for_it_and_in_this_menus_order(): void
    {
        $ids = $this->linkToAccounts($this->shop);
        $dish = $this->dish('Biryani');
        $half = $this->sizeOf($dish, 'Half', 0);
        $full = $this->sizeOf($dish, 'Full', 1);
        $family = $this->sizeOf($dish, 'Family', 2);

        // ERP answers in its own order, keyed by the size's ULID, and still
        // lists a size this menu has since dropped.
        $this->erpAnswers($ids['outlet'], ["items/{$dish->ulid}/stock" => [
            'item_id' => $dish->ulid,
            'has_recipe' => true,
            'sizes' => [
                $this->entry(Ulid::generate(), ['portions_available' => '3.0000']),
                // No recipe of its own yet.
                $this->entry($family->ulid, ['portions_available' => null, 'cost_paise' => null, 'food_cost_percent' => null]),
                $this->entry($full->ulid, ['portions_available' => '0.5000', 'cost_paise' => 14050, 'food_cost_percent' => '70.25', 'available' => false]),
                $this->entry($half->ulid, ['portions_available' => '1.0000', 'cost_paise' => 7000, 'food_cost_percent' => '35.00']),
            ],
        ]]);

        $this->panel($dish)
            ->assertOk()
            ->assertSeeInOrder([
                'Half', '1 portion', '₹70.00', '35%', 'On sale',
                'Full', '0 portions', '₹140.50', '70.25%', 'Off sale',
                'Family', '—', '—', '—', 'On sale',
                'A size no longer on this menu', '3 portions',
            ]);
    }

    public function test_a_dish_with_no_recipe_says_so(): void
    {
        $ids = $this->linkToAccounts($this->shop);
        $dish = $this->dish('Mineral Water');

        $this->erpAnswers($ids['outlet'], ["items/{$dish->ulid}/stock" => [
            'item_id' => $dish->ulid,
            'has_recipe' => false,
            'sizes' => [$this->entry(null, ['portions_available' => null, 'cost_paise' => null, 'food_cost_percent' => null])],
        ]]);

        $this->panel($dish)
            ->assertOk()
            ->assertSee('No recipe yet in ERP')
            ->assertDontSee('Stock covers');
    }

    public function test_a_dish_erp_has_not_heard_of_says_so(): void
    {
        $ids = $this->linkToAccounts($this->shop);
        $dish = $this->dish('New Special');

        $this->erpAnswers($ids['outlet'], [
            "items/{$dish->ulid}/stock" => Http::response(['message' => 'ERP has not heard of this item.'], 404),
        ]);

        $this->panel($dish)
            ->assertOk()
            ->assertSee('ERP has not heard of this dish yet')
            ->assertDontSee('not reachable');
    }

    public function test_with_erp_down_the_panel_says_so_and_the_page_still_opens(): void
    {
        $this->linkToAccounts($this->shop);
        $this->erpIsDown();

        $dish = $this->dish('Dal Makhani');
        $staff = $this->staff();

        $this->actingAs($staff)->get(route('admin.products.show', $dish))
            ->assertOk()
            ->assertSee('Dal Makhani');

        $this->panel($dish, $staff)
            ->assertOk()
            ->assertSee('ERP is not reachable right now')
            ->assertDontSee('Stock covers');
    }

    public function test_the_panel_is_for_whoever_may_see_the_item(): void
    {
        $this->linkToAccounts($this->shop);
        $this->erpWouldAnswerAnything();

        $this->panel($this->dish('Kulfi'), $this->staff(['dashboard.overview.view']))->assertForbidden();

        Http::assertNothingSent();
    }

    /* --------------------------------------------------------- CRM alone */

    public function test_an_outlet_with_crm_alone_is_told_what_erp_adds_and_erp_is_asked_nothing(): void
    {
        // The seeded shop holds CRM and nothing else.
        $this->erpWouldAnswerAnything();

        $dish = $this->dish('Masala Dosa');
        $staff = $this->staff();

        $this->actingAs($staff)->get(route('admin.products.show', $dish))
            ->assertOk()
            ->assertSee('CRM does not keep stock')
            ->assertSee('With Khana ERP, this page shows how many portions the stock on hand')
            ->assertDontSee('data-erp-panel', false);

        // The panel is not there to be asked for either.
        $this->panel($dish, $staff)->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_an_outlet_that_holds_erp_which_this_install_cannot_reach_is_shown_neither(): void
    {
        $this->linkToAccounts($this->shop);
        $this->erpWouldAnswerAnything();
        config(['khana.urls.erp' => null]);

        $dish = $this->dish('Lassi');
        $staff = $this->staff();

        $this->actingAs($staff)->get(route('admin.products.show', $dish))
            ->assertOk()
            ->assertDontSee('data-erp-panel', false)
            ->assertDontSee('CRM does not keep stock');

        $this->panel($dish, $staff)->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_the_all_shops_view_has_no_one_outlet_to_ask_about(): void
    {
        $this->linkToAccounts($this->shop);
        $this->erpWouldAnswerAnything();

        $second = Shop::create([
            'tenant_id' => $this->shop->tenant_id,
            'name' => 'Second Outlet', 'code' => 'SEC', 'slug' => 'second-outlet',
            'invoice_prefix' => 'INV', 'pos_prefix' => 'POS',
            'currency' => 'INR', 'timezone' => 'Asia/Kolkata', 'is_active' => true,
        ]);

        $staff = $this->staff();
        $staff->shops()->attach($second->id);
        $staff->forceFill(['current_shop_id' => null, 'all_shops_view' => true])->save();

        $dish = $this->dish('Thali');

        $this->actingAs($staff)->get(route('admin.products.show', $dish))
            ->assertOk()
            ->assertDontSee('data-erp-panel', false)
            ->assertDontSee('CRM does not keep stock');

        $this->panel($dish, $staff)->assertNotFound();

        Http::assertNothingSent();
    }

    /* --------------------------------------------------- the cost field */

    public function test_the_form_says_the_cost_is_erps_and_does_not_offer_to_take_one(): void
    {
        $this->linkToAccounts($this->shop);
        $this->erpWouldAnswerAnything();

        $dish = $this->dish('Butter Chicken');

        $html = $this->actingAs($this->staff(['inventory.products.edit']))
            ->get(route('admin.products.edit', $dish))
            ->assertOk()
            ->assertSee('Cost to make')
            ->assertSee('From ERP, which works it out from the recipe')
            ->assertDontSee('What one costs you to make or buy')
            ->getContent();

        // Read-only, not disabled: the figure still travels with the form,
        // so saving the dish does not write its cost back as zero.
        $this->assertMatchesRegularExpression('/<input id="prod-purchase"[^>]*name="purchase_price"[^>]*value="81.25"[^>]*readonly/s', $html);
        $this->assertMatchesRegularExpression('/<input id="ov-purchase"[^>]*readonly/s', $html);
        $this->assertDoesNotMatchRegularExpression('/<input id="prod-purchase"[^>]*disabled/s', $html);

        Http::assertNothingSent();
    }

    public function test_an_outlet_with_crm_alone_still_types_its_own_cost(): void
    {
        $dish = $this->dish('Butter Chicken');

        $html = $this->actingAs($this->staff(['inventory.products.edit']))
            ->get(route('admin.products.edit', $dish))
            ->assertOk()
            ->assertSee('Purchase price')
            ->assertSee('What one costs you to make or buy')
            ->assertDontSee('From ERP')
            ->getContent();

        $this->assertDoesNotMatchRegularExpression('/<input id="prod-purchase"[^>]*readonly/s', $html);
        $this->assertDoesNotMatchRegularExpression('/<input id="ov-purchase"[^>]*readonly/s', $html);
    }
}
