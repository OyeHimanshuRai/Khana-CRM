<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Setting;
use App\Models\Shop;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Support\CurrentShop;
use App\Support\CurrentTenant;
use App\Support\Modules;
use App\Support\PlanAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * A barcode scanned on the Products page, from the scan to a sellable product.
 *
 * ProductControllerTest pins the endpoint; this walks the journey an operator
 * actually takes and the ways a real shop floor can knock it over: a code that
 * once belonged to a removed product, the same code in another company, the
 * scanner switched off, staff who may create but not edit.
 */
class ScanToProductFlowTest extends TestCase
{
    use RefreshDatabase;

    private const CODE = '8901234567890';

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');
        $this->seed();
        $this->resetState();

        $this->shop = Shop::query()->withoutGlobalScopes()->orderBy('id')->firstOrFail();
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

    /** @param array<int, string> $permissions */
    private function staff(array $permissions, ?Shop $shop = null): User
    {
        $shop ??= $this->shop;

        $user = User::create([
            'tenant_id' => $shop->tenant_id,
            'name' => 'Stock Clerk',
            'email' => 'clerk'.uniqid().'@example.test',
            'password' => 'clerk-password-1',
            'is_admin' => true,
            'is_active' => true,
        ]);

        $user->shops()->attach($shop->id, ['is_default' => true]);
        $user->forceFill(['current_shop_id' => $shop->id, 'all_shops_view' => false])->save();
        $user->givePermissionTo(array_merge(['dashboard.overview.view'], $permissions));

        return $user->fresh();
    }

    /** A clerk who can do the whole job. */
    private function clerk(): User
    {
        return $this->staff(['inventory.products.view', 'inventory.products.create', 'inventory.products.edit']);
    }

    private function scan(User $user, string $code = self::CODE)
    {
        $this->resetState();

        return $this->actingAs($user)->postJson(route('admin.products.quick-scan'), ['barcode' => $code]);
    }

    public function test_the_whole_journey_from_scan_to_something_the_counter_can_sell(): void
    {
        $clerk = $this->clerk();

        // Before it is scanned the counter has never heard of it.
        $this->actingAs($clerk)->getJson(route('admin.products.lookup', ['q' => self::CODE]))
            ->assertOk()->assertJsonPath('data.exact', null);

        $id = $this->scan($clerk)->assertOk()->assertJsonPath('data.created', true)->json('data.id');

        // In the list straight away, so the scan cannot be lost.
        $this->resetState();
        $this->actingAs($clerk)->get(route('admin.products.index'))
            ->assertOk()
            ->assertSee('New item '.self::CODE);

        // But a half-entered product must not reach a bill at price zero.
        $this->resetState();
        $this->actingAs($clerk)->getJson(route('admin.products.lookup', ['q' => self::CODE]))
            ->assertOk()->assertJsonPath('data.exact', null);

        // The operator completes the form the scan opened.
        $this->resetState();
        $this->actingAs($clerk)->get(route('admin.products.edit', $id))
            ->assertOk()
            ->assertSee('value="'.self::CODE.'"', false);

        $this->resetState();
        $this->actingAs($clerk)->putJson(route('admin.products.update', $id), [
            'name' => 'Laxmi Rice Flour 500g',
            'barcode' => self::CODE,
            'unit_id' => Unit::where('is_active', true)->firstOrFail()->id,
            'selling_price' => 36,
            'mrp' => 36,
            'is_active' => 1,
        ])->assertOk();

        // And now the same code resolves, with the code still intact.
        $this->resetState();
        $this->actingAs($clerk)->getJson(route('admin.products.lookup', ['q' => self::CODE]))
            ->assertOk()
            ->assertJsonPath('data.exact.name', 'Laxmi Rice Flour 500g');
    }

    public function test_the_completion_form_comes_back_active_so_saving_it_puts_the_product_on_sale(): void
    {
        $clerk = $this->clerk();
        $id = $this->scan($clerk)->json('data.id');

        $this->resetState();
        $html = $this->actingAs($clerk)->get(route('admin.products.edit', ['product' => $id, 'complete' => 1]))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/type="checkbox" name="is_active" value="1"\s+checked/', $html);
    }

    public function test_the_completion_form_refuses_to_put_a_free_product_on_sale(): void
    {
        $clerk = $this->clerk();
        $id = $this->scan($clerk)->json('data.id');
        $unit = Unit::where('is_active', true)->firstOrFail()->id;
        $form = ['name' => 'Dal', 'barcode' => self::CODE, 'unit_id' => $unit, 'selling_price' => 0, 'complete' => 1];

        $this->resetState();
        $this->actingAs($clerk)->get(route('admin.products.edit', ['product' => $id, 'complete' => 1]))
            ->assertSee('name="complete"', false);

        $this->resetState();
        $this->actingAs($clerk)->putJson(route('admin.products.update', $id), [...$form, 'is_active' => 1])
            ->assertStatus(422)
            ->assertJsonValidationErrors('selling_price');

        $this->assertFalse((bool) Product::findOrFail($id)->is_active);

        // Unticked it stays a draft, and that is allowed.
        $this->resetState();
        $this->actingAs($clerk)->putJson(route('admin.products.update', $id), [...$form, 'is_active' => 0])
            ->assertOk();

        // The ordinary edit form is not held to this: a free add-on is normal.
        $this->resetState();
        $this->actingAs($clerk)->putJson(route('admin.products.update', $id), [...$form, 'complete' => 0, 'is_active' => 1])
            ->assertOk();
    }

    public function test_a_code_left_on_a_removed_product_does_not_crash(): void
    {
        $clerk = $this->clerk();
        $id = $this->scan($clerk)->json('data.id');

        Product::query()->findOrFail($id)->delete();

        // The unique index still holds the removed row, so this used to be a 500.
        $this->scan($clerk)->assertStatus(422)->assertJsonPath('success', false);

        $this->assertSame(1, Product::withTrashed()->where('barcode', self::CODE)->count());
    }

    public function test_the_add_product_form_refuses_that_code_instead_of_crashing(): void
    {
        $clerk = $this->clerk();
        Product::query()->findOrFail($this->scan($clerk)->json('data.id'))->delete();

        $this->resetState();
        $this->actingAs($clerk)->postJson(route('admin.products.store'), [
            'name' => 'Second Try',
            'barcode' => self::CODE,
            'unit_id' => Unit::where('is_active', true)->firstOrFail()->id,
            'selling_price' => 10,
        ])->assertStatus(422)->assertJsonValidationErrors('barcode');
    }

    public function test_two_companies_can_stock_the_same_packet(): void
    {
        $this->scan($this->clerk())->assertOk()->assertJsonPath('data.created', true);

        $this->resetState();

        $tenant = Tenant::create(['name' => 'Rival Cafe', 'code' => 'RIVAL', 'slug' => 'rival-cafe']);
        $other = Shop::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'name' => 'Rival Cafe', 'code' => 'RV', 'slug' => 'rival-cafe',
        ]);

        $rival = $this->staff(['inventory.products.create'], $other);

        // A manufacturer's barcode is on every packet, so this is the normal case.
        $this->scan($rival)->assertOk()->assertJsonPath('data.created', true);

        $this->assertSame(2, Product::query()->withoutGlobalScopes()->where('barcode', self::CODE)->count());
    }

    public function test_the_master_switch_stops_scans_creating_products(): void
    {
        Setting::put(['scanner_enabled' => '0']);

        $this->scan($this->clerk())->assertStatus(422);

        $this->assertSame(0, Product::where('barcode', self::CODE)->count());
    }

    public function test_codes_a_barcode_cannot_be_are_refused(): void
    {
        $clerk = $this->clerk();

        foreach (['', 'has space', '<script>', 'a/b', str_repeat('9', 61)] as $code) {
            $this->scan($clerk, $code)->assertStatus(422);
        }

        $this->assertSame(0, Product::where('name', 'like', 'New item%')->count());
    }

    public function test_no_active_unit_says_what_to_do(): void
    {
        Unit::query()->update(['is_active' => false]);

        $this->scan($this->clerk())
            ->assertStatus(422)
            ->assertJsonPath('message', 'Add a unit first (Inventory > Units), then scan again.');
    }

    public function test_scanning_needs_the_right_to_create(): void
    {
        $this->scan($this->staff(['inventory.products.view']))->assertForbidden();

        $this->assertSame(0, Product::where('barcode', self::CODE)->count());
    }

    public function test_the_page_only_listens_for_scans_from_staff_who_can_finish_the_job(): void
    {
        $this->resetState();
        $this->actingAs($this->staff(['inventory.products.view', 'inventory.products.create']))
            ->get(route('admin.products.index'))
            ->assertOk()
            ->assertDontSee('data-product-scan', false);

        $this->resetState();
        $this->actingAs($this->clerk())
            ->get(route('admin.products.index'))
            ->assertOk()
            ->assertSee('data-product-scan', false);
    }
}
