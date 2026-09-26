<?php

namespace Tests\Feature;

use App\Models\Modifier;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\Unit;
use App\Models\User;
use App\Support\CurrentShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Running the menu from the admin: sizes, add-ons and the sold-out tap (§8).
 *
 * The screens matter less here than the two rules behind them: a question and
 * its answers are written together or not at all, and a dish with sizes always
 * has exactly one that the menu opens on.
 */
class MenuAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');

        $this->seed();

        CurrentShop::forget();
    }

    protected function tearDown(): void
    {
        CurrentShop::forget();

        parent::tearDown();
    }

    /* ------------------------------------------------------------ actors */

    private function shop(): Shop
    {
        return Shop::query()->orderBy('id')->firstOrFail();
    }

    /** Somebody who may run the menu, pinned to one branch. */
    private function chef(): User
    {
        $user = User::query()->firstWhere('email', 'chef@example.test');

        if ($user !== null) {
            return $user;
        }

        $user = User::create([
            'tenant_id' => $this->shop()->tenant_id,
            'name' => 'Head Chef',
            'email' => 'chef@example.test',
            'password' => 'chef-password-1',
            'is_admin' => true,
        ]);

        $user->givePermissionTo([
            'dashboard.overview.view',
            'inventory.products.view', 'inventory.products.create',
            'inventory.products.edit', 'inventory.products.delete',
            'inventory.modifiers.view', 'inventory.modifiers.create',
            'inventory.modifiers.edit', 'inventory.modifiers.delete',
        ]);

        $user->shops()->syncWithoutDetaching([$this->shop()->id => ['is_default' => true]]);
        $user->forceFill([
            'current_shop_id' => $this->shop()->id,
            'all_shops_view' => false,
        ])->save();

        return $user;
    }

    private function dish(array $overrides = []): Product
    {
        $name = $overrides['name'] ?? 'Dal Makhani';

        $product = new Product(array_merge([
            'name' => $name,
            'slug' => Product::uniqueSlug($name),
            'sku' => Product::generateSku($name),
            'selling_price' => 280,
            'is_active' => true,
        ], $overrides));

        $product->save();

        return $product;
    }

    /**
     * The shape the product form posts. Spelled out rather than built from a
     * factory, because what this is testing is the contract with that form.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function dishPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Chicken Biryani',
            'selling_price' => 440,
            'mrp' => 440,
            'purchase_price' => 158,
            // Required: the counter cannot bill a line with no unit.
            'unit_id' => Unit::query()->where('code', 'PCS')->value('id'),
            'min_stock' => 0,
            'reorder_level' => 0,
            'is_active' => 1,
        ], $overrides);
    }

    /* ----------------------------------------------------------- add-ons */

    public function test_a_question_is_created_with_its_answers(): void
    {
        $pizza = $this->dish(['name' => 'Margherita']);

        $this->actingAs($this->chef())
            ->postJson('/admin/modifiers', [
                'shop_id' => $this->shop()->id,
                'name' => 'Choose your crust',
                'instruction' => 'Pick one',
                'min_select' => 1,
                'max_select' => 1,
                'is_active' => 1,
                'options' => [
                    ['name' => 'Thin crust', 'price' => 0, 'is_available' => 1],
                    ['name' => 'Cheese burst', 'price' => 70, 'is_available' => 1],
                ],
                'products' => [$pizza->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.options', 2)
            ->assertJsonPath('data.dishes', 1);

        $this->assertSame('Choose your crust', $pizza->fresh()->modifiers()->first()->name);
    }

    /**
     * A question with no answers is not a half-finished thing to save - it is
     * a question that would break every dish that asks it.
     */
    public function test_a_question_with_no_answers_is_refused(): void
    {
        $this->actingAs($this->chef())
            ->postJson('/admin/modifiers', [
                'shop_id' => $this->shop()->id,
                'name' => 'Empty question',
                'min_select' => 0,
                'options' => [],
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['options']]);

        $this->assertSame(0, Modifier::allShops()->count());
    }

    public function test_a_maximum_below_the_minimum_is_refused(): void
    {
        $this->actingAs($this->chef())
            ->postJson('/admin/modifiers', [
                'shop_id' => $this->shop()->id,
                'name' => 'Impossible',
                'min_select' => 3,
                'max_select' => 1,
                'options' => [['name' => 'One', 'price' => 0]],
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['max_select']]);
    }

    /** Editing rewrites the answer set: removed rows go, kept rows stay. */
    public function test_editing_a_question_replaces_its_answers(): void
    {
        $modifier = Modifier::query()->create([
            'shop_id' => $this->shop()->id,
            'name' => 'Sweetness',
            'min_select' => 1,
            'max_select' => 1,
            'is_active' => true,
        ]);

        $modifier->options()->createMany([
            ['name' => 'Normal sugar', 'price' => 0, 'sort_order' => 0],
            ['name' => 'Less sugar', 'price' => 0, 'sort_order' => 1],
            ['name' => 'Typo optoin', 'price' => 0, 'sort_order' => 2],
        ]);

        $this->actingAs($this->chef())
            ->putJson("/admin/modifiers/{$modifier->id}", [
                'name' => 'Sweetness',
                'min_select' => 1,
                'max_select' => 1,
                'is_active' => 1,
                'options' => [
                    ['name' => 'Normal sugar', 'price' => 0, 'is_available' => 1],
                    ['name' => 'Less sugar', 'price' => 0, 'is_available' => 1],
                    ['name' => 'No sugar', 'price' => 0, 'is_available' => 1],
                ],
            ])
            ->assertOk();

        $names = $modifier->fresh()->options->pluck('name')->all();

        $this->assertSame(['Normal sugar', 'Less sugar', 'No sugar'], $names);
    }

    /**
     * Cascading would silently take a required choice off six pizzas, and the
     * next order for one would be accepted with no crust.
     */
    public function test_a_question_still_asked_of_a_dish_cannot_be_deleted(): void
    {
        $pizza = $this->dish(['name' => 'Farmhouse']);

        $modifier = Modifier::query()->create([
            'shop_id' => $this->shop()->id,
            'name' => 'Crust',
            'min_select' => 1,
            'max_select' => 1,
            'is_active' => true,
        ]);
        $modifier->options()->create(['name' => 'Thin', 'price' => 0]);
        $modifier->products()->sync([$pizza->id => ['sort_order' => 0]]);

        $this->actingAs($this->chef())
            ->deleteJson("/admin/modifiers/{$modifier->id}")
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('modifiers', ['id' => $modifier->id]);
    }

    public function test_the_add_on_screens_render(): void
    {
        $modifier = Modifier::query()->create([
            'shop_id' => $this->shop()->id,
            'name' => 'Choose your crust',
            'min_select' => 1,
            'max_select' => 1,
            'is_active' => true,
        ]);
        $modifier->options()->create(['name' => 'Cheese burst', 'price' => 70]);

        $chef = $this->chef();

        $this->actingAs($chef)->get('/admin/modifiers')->assertOk()->assertSee('Choose your crust');
        $this->actingAs($chef)->get('/admin/modifiers/create')->assertOk()->assertSee('data-option-template', false);
        $this->actingAs($chef)->get("/admin/modifiers/{$modifier->id}")->assertOk()->assertSee('Cheese burst');
        $this->actingAs($chef)->get("/admin/modifiers/{$modifier->id}/edit")->assertOk()->assertSee('Cheese burst');
    }

    /* ------------------------------------------------------------- sizes */

    public function test_sizes_are_saved_with_the_dish(): void
    {
        $this->actingAs($this->chef())
            ->postJson('/admin/products', $this->dishPayload([
                'options' => [
                    ['name' => 'Half', 'price' => 260, 'is_available' => 1],
                    ['name' => 'Full', 'price' => 440, 'is_default' => 1, 'is_available' => 1],
                ],
            ]))
            ->assertOk();

        $dish = Product::query()->where('name', 'Chicken Biryani')->firstOrFail();

        $this->assertSame(2, $dish->variants()->count());
        $this->assertSame('Full', $dish->variants()->where('is_default', true)->value('name'));
    }

    /**
     * The form always renders one empty row to type into, so a dish that
     * comes one way must stay saveable.
     */
    public function test_an_empty_size_row_is_ignored_rather_than_refused(): void
    {
        $this->actingAs($this->chef())
            ->postJson('/admin/products', $this->dishPayload([
                'name' => 'Jeera Rice',
                'options' => [['name' => '', 'price' => 0]],
            ]))
            ->assertOk();

        $dish = Product::query()->where('name', 'Jeera Rice')->firstOrFail();

        $this->assertSame(0, $dish->variants()->count());
    }

    /**
     * A form with three boxes ticked is a user who did not realise it was a
     * radio. The last one wins and exactly one ends up flagged.
     */
    public function test_only_one_size_ends_up_opening_the_menu(): void
    {
        $this->actingAs($this->chef())
            ->postJson('/admin/products', $this->dishPayload([
                'options' => [
                    ['name' => 'Small', 'price' => 100, 'is_default' => 1],
                    ['name' => 'Medium', 'price' => 200, 'is_default' => 1],
                    ['name' => 'Large', 'price' => 300, 'is_default' => 1],
                ],
            ]))
            ->assertOk();

        $dish = Product::query()->where('name', 'Chicken Biryani')->firstOrFail();

        $this->assertSame(1, $dish->variants()->where('is_default', true)->count());
        $this->assertSame('Large', $dish->variants()->where('is_default', true)->value('name'));
    }

    /**
     * Nothing ticked but sizes present: the first opens the menu. Leaving
     * none flagged would show a dish with no size selected and no price.
     */
    public function test_with_nothing_ticked_the_first_size_opens_the_menu(): void
    {
        $this->actingAs($this->chef())
            ->postJson('/admin/products', $this->dishPayload([
                'options' => [
                    ['name' => 'Half', 'price' => 260],
                    ['name' => 'Full', 'price' => 440],
                ],
            ]))
            ->assertOk();

        $dish = Product::query()->where('name', 'Chicken Biryani')->firstOrFail();

        $this->assertSame('Half', $dish->variants()->where('is_default', true)->value('name'));
    }

    /** A size removed from the form goes; the rest keep their identity. */
    public function test_editing_removes_the_sizes_no_longer_on_the_form(): void
    {
        $dish = $this->dish(['name' => 'Veg Biryani']);

        ProductVariant::query()->create([
            'product_id' => $dish->id, 'name' => 'Half', 'price' => 190, 'sort_order' => 0,
        ]);
        $full = ProductVariant::query()->create([
            'product_id' => $dish->id, 'name' => 'Full', 'price' => 320, 'is_default' => true, 'sort_order' => 1,
        ]);

        $this->actingAs($this->chef())
            ->putJson("/admin/products/{$dish->id}", $this->dishPayload([
                'name' => 'Veg Biryani',
                'selling_price' => 320,
                'options' => [
                    ['name' => 'Full', 'price' => 340, 'is_default' => 1, 'is_available' => 1],
                ],
            ]))
            ->assertOk();

        $this->assertSame(1, $dish->variants()->count());
        // Matched on name, so the row - and anything that has counted sales
        // of a "Full" - survives a price change.
        $this->assertSame($full->id, $dish->variants()->first()->id);
        $this->assertSame(340.0, (float) $dish->variants()->first()->price);
    }

    /* ---------------------------------------------------------- sold out */

    public function test_a_dish_is_taken_off_the_menu_in_one_tap(): void
    {
        $dish = $this->dish();

        $this->actingAs($this->chef())
            ->putJson("/admin/products/{$dish->id}/sold-out")
            ->assertOk()
            ->assertJsonPath('data.is_sold_out', true);

        $this->assertTrue($dish->fresh()->is_sold_out);
        $this->assertFalse($dish->fresh()->isOrderable());
    }

    public function test_the_tap_puts_it_back_on(): void
    {
        $dish = $this->dish(['is_sold_out' => true]);

        $this->actingAs($this->chef())
            ->putJson("/admin/products/{$dish->id}/sold-out")
            ->assertOk()
            ->assertJsonPath('data.is_sold_out', false);

        $this->assertTrue($dish->fresh()->isOrderable());
    }

    /**
     * A "back on at" time is how the flag un-sets itself. Inheriting
     * yesterday's would put a dish back on the moment it was taken off.
     */
    public function test_throwing_the_flag_clears_any_old_back_on_time(): void
    {
        $dish = $this->dish([
            'is_sold_out' => false,
            'sold_out_until' => now()->subDay(),
        ]);

        $this->actingAs($this->chef())
            ->putJson("/admin/products/{$dish->id}/sold-out")
            ->assertOk();

        $fresh = $dish->fresh();

        $this->assertTrue($fresh->is_sold_out);
        $this->assertNull($fresh->sold_out_until);
        $this->assertTrue($fresh->isSoldOut());
    }

    public function test_the_menu_list_shows_the_mark_and_the_sold_out_badge(): void
    {
        $this->dish(['name' => 'Butter Chicken', 'food_type' => 'non_veg', 'is_sold_out' => true]);

        $this->actingAs($this->chef())
            ->get('/admin/products')
            ->assertOk()
            ->assertSee('Non-vegetarian')
            ->assertSee('Sold out');
    }

    /* ------------------------------------------------------- permissions */

    public function test_a_viewer_cannot_write_an_add_on(): void
    {
        $viewer = User::create([
            'tenant_id' => $this->shop()->tenant_id,
            'name' => 'Menu Viewer',
            'email' => 'menuviewer@example.test',
            'password' => 'viewer-password-1',
            'is_admin' => true,
        ]);

        $viewer->givePermissionTo(['inventory.modifiers.view']);
        $viewer->shops()->syncWithoutDetaching([$this->shop()->id => ['is_default' => true]]);
        $viewer->forceFill(['current_shop_id' => $this->shop()->id])->save();

        $this->actingAs($viewer)
            ->postJson('/admin/modifiers', [
                'shop_id' => $this->shop()->id,
                'name' => 'Nope',
                'min_select' => 0,
                'options' => [['name' => 'One', 'price' => 0]],
            ])
            ->assertForbidden();
    }

    public function test_guests_are_redirected(): void
    {
        $this->get('/admin/modifiers')->assertRedirect('http://localhost/admin/login');
    }
}
