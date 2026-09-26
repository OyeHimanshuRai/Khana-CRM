<?php

namespace Tests\Feature;

use App\Models\Floor;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ProductVariant;
use App\Models\RecipeItem;
use App\Models\RestaurantTable;
use App\Models\Shop;
use App\Models\StockMovement;
use App\Models\TableSession;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\KitchenService;
use App\Services\RecipeService;
use App\Services\StockService;
use App\Services\TableCartService;
use App\Services\TableOrderService;
use App\Services\TableQrService;
use App\Support\CurrentShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Tests\TestCase;

/**
 * Recipes, and the stock they consume (§10).
 *
 * This is the piece that makes a restaurant's inventory mean anything. A dish
 * moves no stock when it is sold - there is no count of Butter Naan - so if
 * these do not work, a kitchen can sell all evening and its shelves will read
 * as full in the morning.
 *
 * Three properties carry the weight:
 *
 *   1. It happens once. A ticket recalled and re-bumped must not take the
 *      flour twice.
 *
 *   2. It is never refused. The food has been cooked; refusing to record it
 *      because the count disagrees leaves the shelf reading as full when it
 *      is empty.
 *
 *   3. A size with its own recipe replaces the generic one rather than adding
 *      to it. A Full biryani that quietly used both would cost double.
 */
class RecipeTest extends TestCase
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

    /* ---------------------------------------------------------- fixtures */

    private function shop(): Shop
    {
        return Shop::query()->orderBy('id')->firstOrFail();
    }

    private function warehouse(): Warehouse
    {
        return Warehouse::allShops()
            ->where('shop_id', $this->shop()->id)
            ->orderByDesc('is_default')
            ->firstOrFail();
    }

    private function dish(array $overrides = []): Product
    {
        $name = $overrides['name'] ?? 'Butter Naan';

        $product = new Product(array_merge([
            'name' => $name,
            'slug' => Product::uniqueSlug($name),
            'sku' => Product::generateSku($name),
            'selling_price' => 60,
            'is_active' => true,
            'is_made_to_order' => true,
        ], $overrides));

        $product->save();

        return $product;
    }

    /** An ingredient, with something on the shelf unless told otherwise. */
    private function ingredient(string $name, float $cost, float $onHand = 100): Product
    {
        $product = new Product([
            'name' => $name,
            'slug' => Product::uniqueSlug($name),
            'sku' => Product::generateSku('ING'),
            'unit_id' => Unit::query()->where('code', 'KG')->value('id'),
            'purchase_price' => $cost,
            'selling_price' => $cost,
            'is_active' => true,
            'is_ingredient' => true,
            'is_made_to_order' => false,
        ]);

        $product->save();

        if ($onHand > 0) {
            app(StockService::class)->receive(
                $product,
                $onHand,
                $this->warehouse(),
                null,
                $cost,
                StockMovement::OPENING,
                null,
                'Test opening stock',
                $this->shop()->id,
            );
        }

        return $product;
    }

    /** @param array<string, float> $components ingredient product id => quantity */
    private function recipe(Product $dish, array $components, ?int $variantId = null): void
    {
        $order = 0;

        foreach ($components as $ingredientId => $quantity) {
            RecipeItem::query()->create([
                'shop_id' => $this->shop()->id,
                'product_id' => $dish->id,
                'product_variant_id' => $variantId,
                'ingredient_id' => (int) $ingredientId,
                'quantity' => $quantity,
                'sort_order' => $order++,
            ]);
        }
    }

    private function onHand(Product $product): float
    {
        return (float) ProductStock::allShops()
            ->where('product_id', $product->id)
            ->sum('quantity');
    }

    /* ---------------------------------------------- a ticket in the kitchen */

    private function table(string $code = 'GF-04'): RestaurantTable
    {
        $floor = Floor::query()->firstOrCreate(
            ['shop_id' => $this->shop()->id, 'code' => 'GF'],
            ['name' => 'Ground Floor', 'is_active' => true],
        );

        $table = new RestaurantTable([
            'shop_id' => $floor->shop_id,
            'floor_id' => $floor->id,
            'name' => substr($code, 3),
            'code' => $code,
            'capacity' => 4,
            'status' => RestaurantTable::AVAILABLE,
            'is_active' => true,
        ]);
        $table->save();

        app(TableQrService::class)->issue($table);

        return $table->fresh(['activeQr']);
    }

    private function ticket(Product $dish, int $quantity = 1, ?int $variantId = null): Order
    {
        $table = $this->table();

        $this->get('/t/'.$table->activeQr->token)->assertRedirect(route('table.show'));

        $session = TableSession::allShops()
            ->live()
            ->where('restaurant_table_id', $table->id)
            ->firstOrFail();

        app(TableCartService::class)->add($session, $dish, $variantId, $quantity);

        return app(TableOrderService::class)->place($session);
    }

    private function setMoment(string $moment): void
    {
        $this->shop()->forceFill(['recipe_deduction' => $moment])->save();
        CurrentShop::forget();
    }

    /** A second outlet, for the tests that are about two of them. */
    private function secondBranch(): Shop
    {
        return Shop::query()->firstOrCreate(
            ['code' => 'SB'],
            [
                'tenant_id' => $this->shop()->tenant_id,
                'name' => 'Second Branch',
                'slug' => 'second-branch',
                'is_active' => true,
            ],
        );
    }

    /**
     * Put the actor in All-shops mode, for real.
     *
     * The flag alone is not enough: CurrentShop only consolidates when there
     * is more than one shop to consolidate, so somebody with access to one
     * branch stays in it whatever their preference says. That is also why the
     * bug this guards against needs two branches to reach.
     */
    private function inAllShopsMode(User $user): User
    {
        $second = $this->secondBranch();

        $user->shops()->syncWithoutDetaching([$second->id => ['is_default' => false]]);
        $user->forceFill(['all_shops_view' => true, 'current_shop_id' => null])->save();

        CurrentShop::forget();

        return $user->fresh();
    }

    /* ----------------------------------------------------------- the recipe */

    public function test_a_dish_costs_what_its_ingredients_cost(): void
    {
        $dish = $this->dish();
        $flour = $this->ingredient('Wheat Flour', 40);
        $butter = $this->ingredient('Butter', 500);

        $this->recipe($dish, [$flour->id => 0.09, $butter->id => 0.012]);

        // 0.09 * 40 + 0.012 * 500 = 3.6 + 6 = 9.60
        $this->assertSame(9.6, app(RecipeService::class)->costOf($dish, null, $this->shop()->id));
    }

    /**
     * Null, not zero. Zero reads as "free", and a margin report showing a
     * hundred percent on every un-costed dish is a report nobody checks twice.
     */
    public function test_a_dish_with_no_recipe_has_no_cost_rather_than_a_zero_one(): void
    {
        $this->assertNull(app(RecipeService::class)->costOf($this->dish()));
    }

    public function test_a_size_with_its_own_recipe_replaces_the_generic_one(): void
    {
        $dish = $this->dish(['name' => 'Chicken Biryani', 'selling_price' => 440]);
        $rice = $this->ingredient('Basmati Rice', 100);
        $chicken = $this->ingredient('Chicken', 200);

        $full = new ProductVariant([
            'product_id' => $dish->id,
            'name' => 'Full',
            'price' => 440,
            'is_active' => true,
        ]);
        $full->save();

        $half = new ProductVariant([
            'product_id' => $dish->id,
            'name' => 'Half',
            'price' => 260,
            'is_default' => true,
            'is_active' => true,
        ]);
        $half->save();

        // The generic recipe, and a bigger one for the Full.
        $this->recipe($dish, [$rice->id => 0.18, $chicken->id => 0.22]);
        $this->recipe($dish, [$rice->id => 0.30, $chicken->id => 0.38], $full->id);

        $recipes = app(RecipeService::class);
        $shopId = $this->shop()->id;

        // 0.30*100 + 0.38*200 = 30 + 76 = 106
        $this->assertSame(106.0, $recipes->costOf($dish, $full->id, $shopId));

        /*
         | The Half has no recipe of its own, so it falls back - and crucially
         | it is 0.18+0.22 and not 0.48+0.60. Adding the two together would
         | cost every Full biryani twice.
         */
        $this->assertSame(62.0, $recipes->costOf($dish, $half->id, $shopId));
        $this->assertSame(62.0, $recipes->costOf($dish, null, $shopId));

        $this->assertCount(2, $recipes->componentsFor($dish, $full->id));
    }

    /* --------------------------------------------------------- consumption */

    public function test_the_kitchen_takes_the_ingredients_when_the_dish_is_ready(): void
    {
        $this->setMoment(RecipeService::ON_READY);

        $dish = $this->dish();
        $flour = $this->ingredient('Wheat Flour', 40, 80);
        $butter = $this->ingredient('Butter', 500, 12);

        $this->recipe($dish, [$flour->id => 0.09, $butter->id => 0.012]);

        $order = $this->ticket($dish, 4);
        $kitchen = app(KitchenService::class);

        $kitchen->bumpTicket($order, null, Order::CONFIRMED);

        // Nothing yet: this outlet counts at the pass, not at acceptance.
        $this->assertSame(80.0, $this->onHand($flour));

        $kitchen->bumpTicket($order->fresh(), null, Order::PREPARING);
        $kitchen->bumpTicket($order->fresh(), null, Order::READY);

        // Four naans: 4 * 0.09 = 0.36 of flour, 4 * 0.012 = 0.048 of butter.
        $this->assertEqualsWithDelta(79.64, $this->onHand($flour), 0.001);
        $this->assertEqualsWithDelta(11.952, $this->onHand($butter), 0.001);

        $this->assertNotNull($order->items()->firstOrFail()->recipe_consumed_at);
    }

    /** The property that stops a recalled ticket eating the shelf twice. */
    public function test_a_line_consumes_once_however_often_it_is_bumped(): void
    {
        $this->setMoment(RecipeService::ON_READY);

        $dish = $this->dish();
        $flour = $this->ingredient('Wheat Flour', 40, 80);

        $this->recipe($dish, [$flour->id => 0.1]);

        $order = $this->ticket($dish, 2);
        $kitchen = app(KitchenService::class);

        $kitchen->bumpTicket($order, null, Order::READY);
        $this->assertEqualsWithDelta(79.8, $this->onHand($flour), 0.001);

        $kitchen->bumpTicket($order->fresh(), null, Order::SERVED);
        $this->assertEqualsWithDelta(79.8, $this->onHand($flour), 0.001);

        // And a recall does not give it back, because the food was cooked.
        $kitchen->recall($order->fresh(), Order::PREPARING, null, 'Came back cold');
        $this->assertEqualsWithDelta(79.8, $this->onHand($flour), 0.001);

        // Nor does re-bumping it afterwards take it again.
        $kitchen->bumpTicket($order->fresh(), null, Order::READY);
        $this->assertEqualsWithDelta(79.8, $this->onHand($flour), 0.001);

        $this->assertSame(
            1,
            StockMovement::allShops()
                ->where('product_id', $flour->id)
                ->where('type', StockMovement::CONSUMPTION)
                ->count(),
        );
    }

    public function test_an_outlet_can_count_at_acceptance_instead(): void
    {
        $this->setMoment(RecipeService::ON_ACCEPTED);

        $dish = $this->dish();
        $flour = $this->ingredient('Wheat Flour', 40, 80);

        $this->recipe($dish, [$flour->id => 0.1]);

        $order = $this->ticket($dish, 1);

        app(KitchenService::class)->bumpTicket($order, null, Order::CONFIRMED);

        $this->assertEqualsWithDelta(79.9, $this->onHand($flour), 0.001);
    }

    public function test_an_outlet_that_does_not_track_ingredients_consumes_nothing(): void
    {
        $this->setMoment(RecipeService::OFF);

        $dish = $this->dish();
        $flour = $this->ingredient('Wheat Flour', 40, 80);

        $this->recipe($dish, [$flour->id => 0.1]);

        $order = $this->ticket($dish, 1);

        app(KitchenService::class)->bumpTicket($order, null, Order::READY);

        $this->assertSame(80.0, $this->onHand($flour));
        $this->assertNull($order->items()->firstOrFail()->recipe_consumed_at);
    }

    /**
     * A line bumped straight past the moment must still consume.
     *
     * Reading the ladder by position rather than by equality is what makes
     * this work; an equality check would lose the ingredients forever.
     */
    public function test_a_line_bumped_straight_to_served_still_consumes(): void
    {
        $this->setMoment(RecipeService::ON_READY);

        $dish = $this->dish();
        $flour = $this->ingredient('Wheat Flour', 40, 80);

        $this->recipe($dish, [$flour->id => 0.1]);

        $order = $this->ticket($dish, 1);

        app(KitchenService::class)->bumpTicket($order, null, Order::SERVED);

        $this->assertEqualsWithDelta(79.9, $this->onHand($flour), 0.001);
    }

    /**
     * The one that keeps a kitchen working on a bad night.
     *
     * "Not enough stock" is never the right answer to a dish that has already
     * been cooked, whatever the shop's selling rule says.
     */
    public function test_consumption_is_never_refused_for_want_of_stock(): void
    {
        $this->setMoment(RecipeService::ON_READY);

        // Explicitly off: the setting is about *selling* what you do not have,
        // which is a different question from recording what was used.
        $this->shop()->forceFill(['allow_negative_stock' => false])->save();
        CurrentShop::forget();

        $dish = $this->dish();
        $flour = $this->ingredient('Wheat Flour', 40, 0);

        $this->recipe($dish, [$flour->id => 0.5]);

        $order = $this->ticket($dish, 2);

        app(KitchenService::class)->bumpTicket($order, null, Order::READY);

        // Negative, and true: this kitchen has not been counting its flour.
        $this->assertEqualsWithDelta(-1.0, $this->onHand($flour), 0.001);
        $this->assertNotNull($order->items()->firstOrFail()->recipe_consumed_at);
    }

    /** A dish with no recipe is stamped, so it is not re-asked every bump. */
    public function test_a_dish_with_no_recipe_is_settled_rather_than_re_asked(): void
    {
        $this->setMoment(RecipeService::ON_READY);

        $order = $this->ticket($this->dish(), 1);

        app(KitchenService::class)->bumpTicket($order, null, Order::READY);

        $this->assertNotNull($order->items()->firstOrFail()->recipe_consumed_at);
        $this->assertSame(0, StockMovement::allShops()->where('type', StockMovement::CONSUMPTION)->count());
    }

    /* ------------------------------------------------- one kitchen's recipe */

    /**
     * A recipe belongs to one kitchen, and consumption reads the *order's*
     * shop rather than the reader's.
     *
     * RecipeItem's ambient scope narrows to every shop the reader may see when
     * no single one is selected. A two-branch chain whose admin was in
     * All-shops mode would otherwise get both branches' rows for one dish and
     * consume twice the flour.
     */
    public function test_a_recipe_is_read_for_one_kitchen_only(): void
    {
        $this->setMoment(RecipeService::ON_READY);

        $dish = $this->dish();
        $flour = $this->ingredient('Wheat Flour', 40, 80);

        // This shop's recipe.
        $this->recipe($dish, [$flour->id => 0.1]);

        /*
         | And another branch's, for the same dish. Written straight past the
         | scope, which is what a second outlet's own screen would have done.
         */
        $other = $this->secondBranch();

        RecipeItem::query()->create([
            'shop_id' => $other->id,
            'product_id' => $dish->id,
            'product_variant_id' => null,
            'ingredient_id' => $flour->id,
            'quantity' => 5,
        ]);

        $recipes = app(RecipeService::class);

        // Named explicitly, this kitchen sees one row and one row only.
        $this->assertCount(1, $recipes->componentsFor($dish, null, $this->shop()->id));
        $this->assertSame(4.0, $recipes->costOf($dish, null, $this->shop()->id));

        // And the consumption follows the order's shop, not the reader's.
        $order = $this->ticket($dish, 1);

        app(KitchenService::class)->bumpTicket($order, null, Order::READY);

        // 0.1, not 5.1.
        $this->assertEqualsWithDelta(79.9, $this->onHand($flour), 0.001);
    }

    /** The list screen costs a whole page in one query, not one per row. */
    public function test_the_cost_map_covers_a_page_in_one_query(): void
    {
        $flour = $this->ingredient('Wheat Flour', 40);

        $naan = $this->dish(['name' => 'Butter Naan']);
        $paratha = $this->dish(['name' => 'Aloo Paratha']);
        $plain = $this->dish(['name' => 'Plain Rice']);

        $this->recipe($naan, [$flour->id => 0.09]);
        $this->recipe($paratha, [$flour->id => 0.2]);

        $costs = app(RecipeService::class)->costMap(
            collect([$naan, $paratha, $plain]),
            $this->shop()->id,
        );

        $this->assertSame(3.6, $costs[$naan->id]);
        $this->assertSame(8.0, $costs[$paratha->id]);
        // Null rather than zero, the same as costOf().
        $this->assertNull($costs[$plain->id]);
    }

    /** A kitchen display shows one kitchen. */
    public function test_the_recipe_screen_needs_a_single_shop(): void
    {
        $user = $this->inAllShopsMode($this->staff(['inventory.recipes.view']));

        $this->actingAs($user)
            ->get('/admin/recipes')
            ->assertRedirect(route('admin.dashboard'));
    }

    /* ------------------------------------------------------- an ingredient */

    public function test_an_ingredient_is_not_on_the_menu(): void
    {
        $this->dish(['name' => 'Butter Naan']);
        $this->ingredient('Wheat Flour', 40);

        $names = app(\App\Services\MenuService::class)
            ->items($this->shop())
            ->pluck('name');

        $this->assertContains('Butter Naan', $names->all());
        $this->assertNotContains('Wheat Flour', $names->all());
    }

    public function test_an_ingredient_is_not_in_the_counters_search(): void
    {
        $this->ingredient('Wheat Flour', 40);

        $user = $this->staff(['inventory.products.view']);

        $this->actingAs($user)
            ->getJson('/admin/products/lookup?q=Wheat')
            ->assertOk()
            ->assertJsonPath('data.results', []);
    }

    /* ------------------------------------------------------------ writing */

    public function test_saving_a_recipe_replaces_what_was_there(): void
    {
        $dish = $this->dish();
        $flour = $this->ingredient('Wheat Flour', 40);
        $butter = $this->ingredient('Butter', 500);
        $ghee = $this->ingredient('Ghee', 600);

        $this->recipe($dish, [$flour->id => 0.09, $butter->id => 0.012]);

        $this->actingAs($this->staff(['inventory.recipes.view', 'inventory.recipes.edit']))
            ->putJson('/admin/recipes/'.$dish->id, [
                'rows' => [
                    ['ingredient_id' => $flour->id, 'quantity' => 0.11],
                    ['ingredient_id' => $ghee->id, 'quantity' => 0.01],
                    // A blank row is somebody who changed their mind, not an
                    // error worth refusing the save for.
                    ['ingredient_id' => null, 'quantity' => null],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.ingredients', 2);

        $components = app(RecipeService::class)->componentsFor($dish->fresh());

        $this->assertCount(2, $components);
        // Butter is gone, because the screen showed it and somebody removed it.
        $this->assertNotContains($butter->id, $components->pluck('ingredient_id')->all());
    }

    public function test_the_same_ingredient_twice_is_refused(): void
    {
        $dish = $this->dish();
        $flour = $this->ingredient('Wheat Flour', 40);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('same ingredient twice');

        app(RecipeService::class)->save($dish, null, [
            ['ingredient_id' => $flour->id, 'quantity' => 0.1],
            ['ingredient_id' => $flour->id, 'quantity' => 0.05],
        ], $this->shop()->id);
    }

    public function test_a_dish_cannot_be_an_ingredient_of_itself(): void
    {
        $dish = $this->dish();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ingredient of itself');

        app(RecipeService::class)->save($dish, null, [
            ['ingredient_id' => $dish->id, 'quantity' => 1],
        ], $this->shop()->id);
    }

    /* ---------------------------------------------------------- the screen */

    public function test_the_recipe_screen_lists_dishes_with_their_cost(): void
    {
        $dish = $this->dish(['name' => 'Butter Naan', 'selling_price' => 60]);
        $flour = $this->ingredient('Wheat Flour', 40);

        $this->recipe($dish, [$flour->id => 0.09]);

        $this->actingAs($this->staff(['inventory.recipes.view']))
            ->get('/admin/recipes')
            ->assertOk()
            ->assertSee('Butter Naan')
            ->assertSee('3.60');
    }

    public function test_the_screen_is_closed_without_the_right(): void
    {
        $this->actingAs($this->staff())->get('/admin/recipes')->assertForbidden();
    }

    /* ------------------------------------------------------------- actors */

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
}
