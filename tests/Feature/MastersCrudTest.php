<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\User;
use App\Support\CurrentShop;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The create / change / switch off / remove cycle of every master screen that
 * had no HTTP test of its own, one module per case so a failure names it.
 *
 * Each module is driven through its real routes as the Super Admin. What is
 * checked is what a person at the screen would notice: an empty form is
 * refused by the server, a valid one is saved and says so, a change sticks,
 * the on/off switch flips, and removing it removes it.
 */
class MastersCrudTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');
        $this->seed();
        CurrentShop::forget();
        CurrentTenant::forget();

        $this->actingAs(User::query()->withoutGlobalScopes()->where('email', 'admin@erp.test')->firstOrFail());
    }

    protected function tearDown(): void
    {
        CurrentShop::forget();
        CurrentTenant::forget();

        parent::tearDown();
    }

    /**
     * module route prefix => [table, column that identifies the row, create, change, switch column|null]
     *
     * @return array<string, array<int, mixed>>
     */
    public static function masters(): array
    {
        $shop = fn () => Shop::query()->orderBy('id')->value('id');

        return [
            'brands' => ['brands', 'brands', 'name', fn () => ['name' => 'Demo Brand'], ['name' => 'Demo Brand Two'], 'is_active'],
            'warehouses' => ['warehouses', 'warehouses', 'name', fn () => ['shop_id' => $shop(), 'name' => 'Cold Store', 'code' => 'CS1'], ['name' => 'Cold Store Two', 'code' => 'CS1', 'shop_id' => null], 'is_active'],
            'price-lists' => ['price-lists', 'price_lists', 'name', fn () => ['name' => 'Wholesale', 'code' => 'WS'], ['name' => 'Wholesale Two', 'code' => 'WS'], null],
            'coupons' => ['coupons', 'coupons', 'code', fn () => ['code' => 'SAVE20', 'type' => 'percent', 'value' => 20], ['code' => 'SAVE25', 'type' => 'percent', 'value' => 25], 'is_active'],
            'expenses' => ['expenses', 'expenses', 'title', fn () => ['spent_on' => now()->toDateString(), 'title' => 'Gas cylinder', 'amount' => 1450, 'method' => 'cash'], ['spent_on' => now('UTC')->toDateString(), 'title' => 'Gas cylinders', 'amount' => 1500, 'method' => 'cash'], null],
            'units' => ['units', 'units', 'name', fn () => ['name' => 'Dozen', 'code' => 'DZN'], ['name' => 'Dozens', 'code' => 'DZN'], 'is_active'],
            'taxes' => ['taxes', 'tax_rates', 'name', fn () => ['name' => 'GST 7%', 'rate' => 7], ['name' => 'GST 7.5%', 'rate' => 7.5], 'is_active'],
            'floors' => ['floors', 'floors', 'name', fn () => ['shop_id' => $shop(), 'name' => 'Garden', 'code' => 'GD'], ['name' => 'Garden Two', 'code' => 'GD'], 'is_active'],
            'kitchen-stations' => ['kitchen-stations', 'kitchen_stations', 'name', fn () => ['shop_id' => $shop(), 'name' => 'Salad Bar', 'code' => 'SB'], ['name' => 'Salad Bar Two', 'code' => 'SB'], 'is_active'],
            'testimonials' => ['testimonials', 'testimonials', 'author_name', fn () => ['quote' => 'Billing is now a breeze.', 'author_name' => 'Asha Menon'], ['quote' => 'Billing is now a breeze.', 'author_name' => 'Asha Menon K'], 'is_active'],
            'integrations' => ['integrations', 'integrations', 'name', fn () => ['name' => 'Swiggy'], ['name' => 'Swiggy Partner'], 'is_active'],
            'showcases' => ['showcases', 'showcases', 'title', fn () => ['title' => 'Kitchen display'], ['title' => 'Kitchen display two'], 'is_active'],
            'landing-stats' => ['landing-stats', 'landing_stats', 'label', fn () => ['label' => 'Restaurants served', 'value' => '1,200+'], ['label' => 'Restaurants served today', 'value' => '1,300+'], 'is_active'],
            'outlet-types' => ['outlet-types', 'outlet_types', 'name', fn () => ['name' => 'Food truck', 'icon' => 'cart'], ['name' => 'Food trucks', 'icon' => 'cart'], 'is_active'],
            'printers' => ['printers', 'printers', 'name', fn () => ['shop_id' => $shop(), 'name' => 'Kitchen pass printer', 'code' => 'KP1', 'kind' => 'kot', 'driver' => 'browser', 'columns' => 42, 'copies' => 1], ['name' => 'Kitchen pass printer two', 'code' => 'KP1', 'kind' => 'kot', 'driver' => 'browser', 'columns' => 42, 'copies' => 1], null],
            'campaigns' => ['campaigns', 'campaigns', 'name', fn () => ['name' => 'Diwali offer', 'channel' => 'sms', 'body' => 'Flat 15% off this week.'], ['name' => 'Diwali offer two', 'channel' => 'sms', 'body' => 'Flat 15% off this week.'], null],
        ];
    }

    #[DataProvider('masters')]
    public function test_the_full_cycle_of_a_master_screen(string $prefix, string $table, string $column, \Closure $create, array $change, ?string $switch): void
    {
        $store = route("admin.{$prefix}.store");

        // 1. An empty form is refused by the server, and nothing is written.
        $before = DB::table($table)->count();
        $this->postJson($store, [])->assertStatus(422);
        $this->assertSame($before, DB::table($table)->count(), 'a refused form must not save a row');

        // 2. A valid one is saved and says so.
        $payload = $create();
        $this->postJson($store, $payload)->assertOk()->assertJsonPath('success', true);
        $this->assertSame($before + 1, DB::table($table)->count());

        $row = DB::table($table)->where($column, $payload[$column])->first();
        $this->assertNotNull($row, "the saved {$prefix} row can be found by {$column}");

        // 2b. Its edit form and its detail view open for that row.
        foreach (['edit', 'show'] as $screen) {
            if (Route::has("admin.{$prefix}.{$screen}")) {
                $this->get(route("admin.{$prefix}.{$screen}", $row->id))->assertOk();
            }
        }

        // 3. A change sticks.
        $change = array_filter($change, fn ($v) => $v !== null);
        $this->putJson(route("admin.{$prefix}.update", $row->id), $change)->assertOk()->assertJsonPath('success', true);
        $this->assertSame($change[$column], DB::table($table)->where('id', $row->id)->value($column));

        // 4. The on/off switch flips.
        if ($switch !== null) {
            $route = \Route::has("admin.{$prefix}.status") ? "admin.{$prefix}.status" : "admin.{$prefix}.toggle";
            $was = (bool) DB::table($table)->where('id', $row->id)->value($switch);

            $this->putJson(route($route, $row->id), [])->assertOk();
            $this->assertSame(! $was, (bool) DB::table($table)->where('id', $row->id)->value($switch));
        }

        // 5. Removing it removes it (or retires it, for tables that soft-delete).
        $this->deleteJson(route("admin.{$prefix}.destroy", $row->id))->assertOk();

        $gone = DB::table($table)->where('id', $row->id)->first();
        $this->assertTrue(
            $gone === null || (Schema::hasColumn($table, 'deleted_at') && $gone->deleted_at !== null),
            "{$prefix} row is still there after it was removed"
        );
    }
}
