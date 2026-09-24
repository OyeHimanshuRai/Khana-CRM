<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Shop;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\User;
use App\Services\MenuImportService;
use App\Support\CurrentShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Tests\TestCase;

/**
 * Bulk menu import (§8).
 *
 * The properties that matter are both about not ruining somebody's afternoon:
 *
 *   1. One bad row imports nothing. A half-imported menu cannot be told from
 *      a whole one, re-running doubles what landed, and the only way back is
 *      to delete four hundred dishes by hand.
 *
 *   2. The export round-trips. Download the menu, change forty prices, upload
 *      it - forty dishes updated, not four hundred duplicated.
 */
class MenuImportTest extends TestCase
{
    use RefreshDatabase;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');

        $this->seed();

        CurrentShop::forget();

        $this->path = tempnam(sys_get_temp_dir(), 'menu').'.csv';
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            @unlink($this->path);
        }

        CurrentShop::forget();

        parent::tearDown();
    }

    /* ---------------------------------------------------------- fixtures */

    private function shop(): Shop
    {
        return Shop::query()->orderBy('id')->firstOrFail();
    }

    /** @param array<int, array<int, string>> $rows */
    private function csv(array $header, array $rows): string
    {
        $handle = fopen($this->path, 'wb');

        fputcsv($handle, $header);

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        fclose($handle);

        return $this->path;
    }

    private function tax(): string
    {
        return (string) TaxRate::query()->orderBy('id')->value('name');
    }

    /**
     * A dish already on the menu.
     *
     * Created here rather than taken from the seed: a fresh install comes up
     * empty on purpose - it belongs to the business that bought it - so a test
     * that reached for "the first product" would be testing the demo seeder.
     */
    private function dish(string $name = 'Dal Makhani', array $overrides = []): Product
    {
        $product = new Product(array_merge([
            'name' => $name,
            'slug' => Product::uniqueSlug($name),
            'sku' => Product::generateSku($name),
            'unit_id' => Unit::query()->where('code', 'PCS')->value('id'),
            'tax_rate_id' => TaxRate::query()->orderBy('id')->value('id'),
            'selling_price' => 280,
            'is_active' => true,
            'is_made_to_order' => true,
        ], $overrides));

        $product->save();

        return $product;
    }

    private function staff(array $permissions = []): User
    {
        $user = User::query()->firstWhere('email', 'chef@example.test');

        if ($user === null) {
            $user = User::create([
                'tenant_id' => $this->shop()->tenant_id,
                'name' => 'Head Chef',
                'email' => 'chef@example.test',
                'password' => 'chef-password-1',
                'is_admin' => true,
            ]);

            $user->shops()->syncWithoutDetaching([$this->shop()->id => ['is_default' => true]]);
            $user->forceFill([
                'current_shop_id' => $this->shop()->id,
                'all_shops_view' => false,
            ])->save();
        }

        $user->givePermissionTo(array_merge(['dashboard.overview.view'], $permissions));

        return $user->fresh();
    }

    /* --------------------------------------------------------- importing */

    public function test_a_file_of_new_dishes_is_imported(): void
    {
        $path = $this->csv(
            ['Name', 'Category', 'Sub-category', 'Unit', 'Tax', 'Food Type', 'Spice', 'Selling Price', 'Dine-in Price'],
            [
                ['Tandoori Roti', 'Main Course', 'Indian Breads', 'PCS', $this->tax(), 'veg', '0', '30', '35'],
                ['Kadhai Paneer', 'Main Course', 'Vegetarian', 'PCS', $this->tax(), 'veg', 'Medium', '290', '310'],
            ],
        );

        $result = app(MenuImportService::class)->import($path);

        $this->assertSame(2, $result['created']);
        $this->assertSame(0, $result['updated']);

        $roti = Product::query()->where('name', 'Tandoori Roti')->firstOrFail();

        $this->assertSame(35.0, (float) $roti->dine_in_price);
        $this->assertSame('veg', $roti->food_type);

        // The two levels come back as two levels, so a re-import rebuilds
        // exactly the card it came from.
        $this->assertSame('Indian Breads', $roti->category->name);
        $this->assertSame('Main Course', $roti->category->parent->name);

        // Spice by name, because "Medium" is what somebody types.
        $paneer = Product::query()->where('name', 'Kadhai Paneer')->firstOrFail();
        $this->assertSame(2, (int) $paneer->spice_level);
    }

    /** The whole point of the export/import pair. */
    public function test_re_importing_updates_rather_than_duplicates(): void
    {
        $existing = $this->dish();
        $before = Product::query()->count();

        $path = $this->csv(
            ['SKU', 'Name', 'Unit', 'Selling Price'],
            [[$existing->sku, $existing->name, 'PCS', '999']],
        );

        $result = app(MenuImportService::class)->import($path);

        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['updated']);
        $this->assertSame($before, Product::query()->count());
        $this->assertSame(999.0, (float) $existing->fresh()->selling_price);
    }

    /** Matched on the name when there is no SKU column at all. */
    public function test_a_file_with_no_sku_matches_on_the_name(): void
    {
        $existing = $this->dish();

        $path = $this->csv(
            ['Name', 'Unit', 'Selling Price'],
            [[$existing->name, 'PCS', '777']],
        );

        $result = app(MenuImportService::class)->import($path);

        $this->assertSame(1, $result['updated']);
        $this->assertSame(777.0, (float) $existing->fresh()->selling_price);
    }

    /**
     * A blank cell leaves the field alone.
     *
     * The column is often blank on a file somebody edited down to forty
     * prices, and clearing forty units would be a catastrophic reading of it.
     */
    public function test_a_blank_cell_does_not_clear_the_field(): void
    {
        $existing = $this->dish();
        $unitId = $existing->unit_id;

        $path = $this->csv(
            ['SKU', 'Name', 'Unit', 'Selling Price'],
            [[$existing->sku, $existing->name, '', '555']],
        );

        app(MenuImportService::class)->import($path);

        $this->assertSame($unitId, $existing->fresh()->unit_id);
        $this->assertSame(555.0, (float) $existing->fresh()->selling_price);
    }

    /* ------------------------------------------------------- the refusals */

    public function test_one_bad_row_imports_nothing(): void
    {
        $before = Product::query()->count();

        $path = $this->csv(
            ['Name', 'Unit', 'Tax', 'Food Type', 'Selling Price'],
            [
                ['Perfectly Fine Dish', 'PCS', $this->tax(), 'veg', '100'],
                ['Bad Food Type', 'PCS', $this->tax(), 'meaty', '100'],
            ],
        );

        try {
            app(MenuImportService::class)->import($path);
            $this->fail('A file with a bad row should not import.');
        } catch (RuntimeException $e) {
            // The spreadsheet's own row number, because that is what the
            // person reading this is looking at.
            $this->assertStringContainsString('Row 3', $e->getMessage());
            $this->assertStringContainsString('meaty', $e->getMessage());
        }

        $this->assertSame($before, Product::query()->count());
        $this->assertFalse(Product::query()->where('name', 'Perfectly Fine Dish')->exists());
    }

    public function test_a_new_dish_without_a_unit_is_refused(): void
    {
        $path = $this->csv(['Name', 'Selling Price'], [['Unitless', '100']]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('needs a unit');

        app(MenuImportService::class)->import($path);
    }

    public function test_a_file_with_no_name_column_is_refused(): void
    {
        $path = $this->csv(['Dish', 'Price'], [['Something', '100']]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no "Name" column');

        app(MenuImportService::class)->import($path);
    }

    public function test_an_unknown_unit_names_itself(): void
    {
        $path = $this->csv(['Name', 'Unit'], [['Odd Dish', 'SPOON']]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SPOON');

        app(MenuImportService::class)->import($path);
    }

    /**
     * A deleted dish still holds its SKU against the unique index.
     *
     * Without the check this would insert, hit the index, and hand somebody a
     * raw database error instead of a row number - while rolling the whole
     * file back.
     */
    public function test_a_sku_held_by_a_deleted_dish_is_reported_not_crashed(): void
    {
        $gone = $this->dish('Retired Dish');
        $sku = $gone->sku;
        $gone->delete();

        $path = $this->csv(
            ['SKU', 'Name', 'Unit'],
            [[$sku, 'Brand New Dish', 'PCS']],
        );

        try {
            app(MenuImportService::class)->import($path);
            $this->fail('A SKU held by a deleted dish should be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('deleted dish', $e->getMessage());
            $this->assertStringContainsString($sku, $e->getMessage());
        }

        $this->assertFalse(Product::query()->where('name', 'Brand New Dish')->exists());
    }

    /* ------------------------------------------------------- the previews */

    public function test_a_preview_changes_nothing(): void
    {
        $before = Product::query()->count();

        $path = $this->csv(
            ['Name', 'Unit', 'Selling Price'],
            [['Brand New Dish', 'PCS', '100']],
        );

        $result = app(MenuImportService::class)->import($path, dryRun: true);

        $this->assertSame(1, $result['created']);
        $this->assertSame($before, Product::query()->count());
        $this->assertFalse(Product::query()->where('name', 'Brand New Dish')->exists());
    }

    /* ------------------------------------------------------- the categories */

    /**
     * Created rather than refused: "set up your twelve categories first" is a
     * chore that makes a bulk import pointless. The names come back so a typo
     * is visible immediately rather than a month later.
     */
    public function test_a_category_nobody_set_up_is_created_and_reported(): void
    {
        $path = $this->csv(
            ['Name', 'Category', 'Unit'],
            [['Mystery Dish', 'Small Plates', 'PCS']],
        );

        $result = app(MenuImportService::class)->import($path);

        $this->assertContains('Small Plates', $result['categories']);
        $this->assertTrue(Category::query()->where('name', 'Small Plates')->exists());
    }

    /** A category named on forty rows is created once. */
    public function test_a_category_named_twice_is_created_once(): void
    {
        $path = $this->csv(
            ['Name', 'Category', 'Unit'],
            [
                ['Dish One', 'Small Plates', 'PCS'],
                ['Dish Two', 'Small Plates', 'PCS'],
            ],
        );

        app(MenuImportService::class)->import($path);

        $this->assertSame(1, Category::query()->where('name', 'Small Plates')->count());
    }

    /* ---------------------------------------------------------- the screen */

    public function test_the_export_and_the_import_agree_on_their_columns(): void
    {
        $response = $this->actingAs($this->staff(['inventory.products.view', 'inventory.products.export']))
            ->get('/admin/products/export');

        $response->assertOk();

        $csv = $response->streamedContent();
        $header = strtok($csv, "\n");

        foreach (MenuImportService::COLUMNS as $column) {
            $this->assertStringContainsString($column, $header);
        }
    }

    public function test_the_template_carries_a_real_row(): void
    {
        $this->dish();

        $response = $this->actingAs($this->staff(['inventory.products.import']))
            ->get('/admin/products/import/template');

        $response->assertOk();

        $lines = array_values(array_filter(explode("\n", $response->streamedContent())));

        // Header plus one example, so nobody has to guess what "Food Type"
        // wants.
        $this->assertCount(2, $lines);
    }

    public function test_importing_needs_its_own_right(): void
    {
        $this->actingAs($this->staff(['inventory.products.view']))
            ->get('/admin/products/import')
            ->assertForbidden();
    }
}
