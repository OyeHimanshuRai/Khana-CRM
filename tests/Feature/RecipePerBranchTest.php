<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\RecipeItem;
use App\Models\Shop;
use App\Models\TaxRate;
use App\Models\Tenant;
use App\Models\Unit;
use App\Services\RecipeService;
use App\Support\CurrentShop;
use App\Support\CurrentTenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * A recipe belongs to the branch that cooks it. Two branches of one company
 * each keep their own version of the same dish, and inside one branch an
 * ingredient is still listed once per dish and size.
 */
class RecipePerBranchTest extends TestCase
{
    use RefreshDatabase;

    private Shop $first;

    private Shop $second;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');
        $this->seed();
        CurrentShop::forget();
        CurrentTenant::forget();

        $this->first = Shop::query()->orderBy('id')->firstOrFail();
        $this->second = Shop::create([
            'tenant_id' => $this->first->tenant_id, 'name' => 'Second Branch', 'code' => 'SB2', 'slug' => 'second-branch', 'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        CurrentShop::forget();
        CurrentTenant::forget();

        parent::tearDown();
    }

    private function product(string $name, bool $ingredient = false): Product
    {
        $p = new Product([
            'name' => $name, 'slug' => Product::uniqueSlug($name), 'sku' => Product::generateSku($name),
            'unit_id' => Unit::where('code', $ingredient ? 'KG' : 'PCS')->value('id'), 'tax_rate_id' => TaxRate::where('rate', 0)->value('id'),
            'purchase_price' => 10, 'mrp' => 100, 'selling_price' => 100, 'is_made_to_order' => ! $ingredient, 'is_ingredient' => $ingredient,
        ]);
        $p->tenant_id = $this->first->tenant_id;
        $p->save();

        return $p;
    }

    private function row(Shop $shop, Product $dish, Product $ingredient, ?int $variant = null): RecipeItem
    {
        $row = new RecipeItem([
            'product_id' => $dish->id, 'product_variant_id' => $variant, 'ingredient_id' => $ingredient->id, 'quantity' => 0.2, 'sort_order' => 0,
        ]);
        $row->shop_id = $shop->id;
        $row->save();

        return $row;
    }

    public function test_two_branches_can_each_record_the_same_dish(): void
    {
        $dish = $this->product('Dal Makhani');
        $dal = $this->product('Black Lentils', true);

        app(RecipeService::class)->save($dish, null, [['ingredient_id' => $dal->id, 'quantity' => 0.08]], $this->first->id);
        app(RecipeService::class)->save($dish, null, [['ingredient_id' => $dal->id, 'quantity' => 0.12]], $this->second->id);

        $this->assertSame(1, RecipeItem::allShops()->where('shop_id', $this->first->id)->count());
        $this->assertSame(1, RecipeItem::allShops()->where('shop_id', $this->second->id)->count());
        $this->assertEquals(0.08, (float) RecipeItem::allShops()->where('shop_id', $this->first->id)->value('quantity'));
        $this->assertEquals(0.12, (float) RecipeItem::allShops()->where('shop_id', $this->second->id)->value('quantity'));
    }

    public function test_editing_one_branchs_recipe_leaves_the_other_alone(): void
    {
        $dish = $this->product('Paneer Tikka');
        $paneer = $this->product('Paneer', true);
        $curd = $this->product('Curd', true);

        $service = app(RecipeService::class);
        $service->save($dish, null, [['ingredient_id' => $paneer->id, 'quantity' => 0.12]], $this->first->id);
        $service->save($dish, null, [['ingredient_id' => $paneer->id, 'quantity' => 0.12]], $this->second->id);

        CurrentShop::forget();
        $service->save($dish, null, [['ingredient_id' => $curd->id, 'quantity' => 0.05]], $this->first->id);

        $this->assertSame([$curd->id], RecipeItem::allShops()->where('shop_id', $this->first->id)->pluck('ingredient_id')->all());
        $this->assertSame([$paneer->id], RecipeItem::allShops()->where('shop_id', $this->second->id)->pluck('ingredient_id')->all());
    }

    public function test_one_branch_cannot_list_an_ingredient_twice_even_for_a_recipe_that_fits_every_size(): void
    {
        $dish = $this->product('Butter Naan');
        $flour = $this->product('Flour', true);

        $this->row($this->first, $dish, $flour);

        // A NULL size used to slip past the unique index.
        $this->expectException(QueryException::class);
        $this->row($this->first, $dish, $flour);
    }

    public function test_one_branch_cannot_list_an_ingredient_twice_for_the_same_size(): void
    {
        $dish = $this->product('Biryani');
        $rice = $this->product('Rice', true);
        $variant = \App\Models\ProductVariant::create(['product_id' => $dish->id, 'name' => 'Half', 'price' => 190, 'is_default' => true]);

        $this->row($this->first, $dish, $rice, $variant->id);

        $this->expectException(QueryException::class);
        $this->row($this->first, $dish, $rice, $variant->id);
    }

    public function test_the_same_ingredient_may_appear_at_different_sizes_and_in_other_branches(): void
    {
        $dish = $this->product('Biryani 2');
        $rice = $this->product('Rice 2', true);
        $half = \App\Models\ProductVariant::create(['product_id' => $dish->id, 'name' => 'Half', 'price' => 190, 'is_default' => true]);
        $full = \App\Models\ProductVariant::create(['product_id' => $dish->id, 'name' => 'Full', 'price' => 320]);

        $this->row($this->first, $dish, $rice, $half->id);
        $this->row($this->first, $dish, $rice, $full->id);
        $this->row($this->first, $dish, $rice, null);
        $this->row($this->second, $dish, $rice, $half->id);

        $this->assertSame(4, RecipeItem::allShops()->count());
    }

    /* ---------------------------------------------------------- recipes:copy */

    public function test_recipes_are_copied_to_a_new_branch_and_running_it_again_changes_nothing(): void
    {
        $dish = $this->product('Dal');
        $dal = $this->product('Lentils', true);
        $butter = $this->product('Butter', true);

        app(RecipeService::class)->save($dish, null, [
            ['ingredient_id' => $dal->id, 'quantity' => 0.08], ['ingredient_id' => $butter->id, 'quantity' => 0.02],
        ], $this->first->id);

        $this->artisan('recipes:copy', ['from' => $this->first->id, 'to' => $this->second->id])
            ->expectsOutputToContain('Copied 2 ingredient line(s) for 1 dish(es)')->assertExitCode(0);

        $this->assertSame(2, RecipeItem::allShops()->where('shop_id', $this->second->id)->count());

        $this->artisan('recipes:copy', ['from' => $this->first->id, 'to' => $this->second->id])
            ->expectsOutputToContain('Copied 0 ingredient line(s)')->assertExitCode(0);

        $this->assertSame(2, RecipeItem::allShops()->where('shop_id', $this->second->id)->count());
    }

    public function test_a_branchs_tuned_recipe_is_not_overwritten_unless_asked(): void
    {
        $dish = $this->product('Dal 2');
        $dal = $this->product('Lentils 2', true);

        app(RecipeService::class)->save($dish, null, [['ingredient_id' => $dal->id, 'quantity' => 0.08]], $this->first->id);
        app(RecipeService::class)->save($dish, null, [['ingredient_id' => $dal->id, 'quantity' => 0.50]], $this->second->id);

        $this->artisan('recipes:copy', ['from' => $this->first->id, 'to' => $this->second->id])->assertExitCode(0);
        $this->assertEquals(0.5, (float) RecipeItem::allShops()->where('shop_id', $this->second->id)->value('quantity'));

        $this->artisan('recipes:copy', ['from' => $this->first->id, 'to' => $this->second->id, '--overwrite' => true])->assertExitCode(0);
        $this->assertEquals(0.08, (float) RecipeItem::allShops()->where('shop_id', $this->second->id)->value('quantity'));
    }

    public function test_recipes_cannot_be_copied_between_companies_or_onto_themselves(): void
    {
        $other = Tenant::create(['name' => 'Other Co', 'code' => 'OTH', 'slug' => 'other-co', 'is_active' => true]);
        $foreign = Shop::create(['tenant_id' => $other->id, 'name' => 'Foreign', 'code' => 'FRN', 'slug' => 'foreign', 'is_active' => true]);

        $this->artisan('recipes:copy', ['from' => $this->first->id, 'to' => $foreign->id])
            ->expectsOutputToContain('same company')->assertExitCode(1);

        $this->artisan('recipes:copy', ['from' => $this->first->id, 'to' => $this->first->id])
            ->expectsOutputToContain('two different branches')->assertExitCode(1);

        $this->artisan('recipes:copy', ['from' => $this->first->id, 'to' => 99999])
            ->expectsOutputToContain('must exist')->assertExitCode(1);
    }
}
