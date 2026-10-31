<?php

namespace Tests\Feature;

use App\Models\Modifier;
use App\Models\ModifierOption;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\User;
use App\Support\CurrentShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The menu (§8): food marks, sizes, add-ons and when a dish is served.
 *
 * The rules worth testing hardest are the ones three screens will disagree
 * about if they are not held in one place - whether a dish is orderable right
 * now, and whether a set of chosen add-ons is a valid answer. A customer menu
 * that disagreed with the POS about whether breakfast is on would be worse
 * than either being wrong alone.
 */
class MenuTest extends TestCase
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

    private function shop(): Shop
    {
        return Shop::query()->orderBy('id')->firstOrFail();
    }

    private function dish(array $overrides = []): Product
    {
        $product = new Product(array_merge([
            'name' => 'Dal Makhani',
            'slug' => Product::uniqueSlug('Dal Makhani'),
            'sku' => Product::generateSku('Dal Makhani'),
            'selling_price' => 280,
            'is_active' => true,
        ], $overrides));

        $product->save();

        return $product;
    }

    /* ------------------------------------------------------------- marks */

    /**
     * Null is a real answer, not a missing one: a bottle of water is not
     * vegetarian food, it is not food, and it must not get a green dot.
     */
    public function test_a_row_that_is_not_food_gets_no_mark(): void
    {
        $water = $this->dish(['name' => 'Bottled Water', 'food_type' => null]);

        $this->assertNull($water->foodTypeLabel());
        $this->assertNull($water->foodTypeDot());
    }

    public function test_a_veg_dish_gets_the_green_mark(): void
    {
        $dish = $this->dish(['food_type' => 'veg']);

        $this->assertSame('Vegetarian', $dish->foodTypeLabel());
        $this->assertSame('#16a34a', $dish->foodTypeDot());
    }

    /** Zero means "not spicy", which is nothing to print rather than "0". */
    public function test_spice_says_nothing_at_level_zero(): void
    {
        $this->assertNull($this->dish(['spice_level' => 0])->spiceLabel());
        $this->assertSame('Hot', $this->dish(['spice_level' => 3])->spiceLabel());
    }

    /* ----------------------------------------------------------- pricing */

    /**
     * A blank channel price means "same as everywhere else", never free.
     */
    public function test_an_unset_channel_price_falls_back_to_the_shelf(): void
    {
        $dish = $this->dish(['selling_price' => 280, 'delivery_price' => null]);

        $this->assertSame(280.0, $dish->channelPriceFor('delivery'));
        $this->assertSame(280.0, $dish->channelPriceFor('dine_in'));
    }

    public function test_a_channel_price_wins_where_it_is_set(): void
    {
        $dish = $this->dish(['selling_price' => 280, 'delivery_price' => 320]);

        $this->assertSame(320.0, $dish->channelPriceFor('delivery'));
        $this->assertSame(280.0, $dish->channelPriceFor('takeaway'));
    }

    /** A typed zero is a decision to give it away and is kept. */
    public function test_a_zero_channel_price_is_honoured(): void
    {
        $dish = $this->dish(['selling_price' => 280, 'takeaway_price' => 0]);

        $this->assertSame(0.0, $dish->channelPriceFor('takeaway'));
    }

    /* ------------------------------------------------------ availability */

    public function test_a_dish_with_no_window_is_served_all_day(): void
    {
        $dish = $this->dish();

        $this->assertTrue($dish->isOrderable(Carbon::parse('2026-09-15 03:00')));
        $this->assertTrue($dish->isOrderable(Carbon::parse('2026-09-15 23:30')));
    }

    public function test_breakfast_stops_at_its_closing_time(): void
    {
        $dish = $this->dish([
            'name' => 'Poha',
            'available_from' => '07:00',
            'available_to' => '11:30',
        ]);

        $this->assertTrue($dish->isOrderable(Carbon::parse('2026-09-15 08:00')));
        $this->assertFalse($dish->isOrderable(Carbon::parse('2026-09-15 12:00')));
        $this->assertFalse($dish->isOrderable(Carbon::parse('2026-09-15 06:30')));
    }

    /**
     * A late-night menu running 23:00 to 02:00 is a real thing. A plain
     * from <= now <= to would serve it for one hour a night.
     */
    public function test_a_window_that_crosses_midnight_is_honoured(): void
    {
        $dish = $this->dish([
            'name' => 'Late Night Maggi',
            'available_from' => '23:00',
            'available_to' => '02:00',
        ]);

        $this->assertTrue($dish->isOrderable(Carbon::parse('2026-09-15 23:30')));
        $this->assertTrue($dish->isOrderable(Carbon::parse('2026-09-15 01:30')));
        $this->assertFalse($dish->isOrderable(Carbon::parse('2026-09-15 15:00')));
    }

    public function test_a_dish_is_refused_on_a_day_it_is_not_served(): void
    {
        // Sundays only. 2026-09-15 is a Tuesday.
        $dish = $this->dish(['name' => 'Sunday Roast', 'available_days' => [7]]);

        $this->assertFalse($dish->isOrderable(Carbon::parse('2026-09-15 13:00')));
        $this->assertTrue($dish->isOrderable(Carbon::parse('2026-09-20 13:00')));
    }

    public function test_sold_out_takes_a_dish_off_the_menu(): void
    {
        $dish = $this->dish(['is_sold_out' => true]);

        $this->assertFalse($dish->isOrderable());
        $this->assertSame('Sold out', $dish->unavailableReason());
    }

    /**
     * A kitchen that runs out of prawns at nine wants them back tomorrow,
     * not a note on the fridge.
     */
    public function test_a_sold_out_dish_comes_back_on_its_own(): void
    {
        $dish = $this->dish([
            'is_sold_out' => true,
            'sold_out_until' => Carbon::parse('2026-09-16 06:00'),
        ]);

        $this->assertTrue($dish->isSoldOut(Carbon::parse('2026-09-15 22:00')));
        $this->assertFalse($dish->isSoldOut(Carbon::parse('2026-09-16 09:00')));
    }

    /** Reading is not the place to write: a guest's menu page fires no UPDATE. */
    public function test_an_expired_sold_out_flag_is_not_written_back(): void
    {
        $dish = $this->dish([
            'is_sold_out' => true,
            'sold_out_until' => Carbon::parse('2026-09-14 06:00'),
        ]);

        $dish->isSoldOut(Carbon::parse('2026-09-16 09:00'));

        $this->assertTrue($dish->fresh()->is_sold_out);
    }

    public function test_an_inactive_dish_is_never_orderable(): void
    {
        $dish = $this->dish(['is_active' => false]);

        $this->assertFalse($dish->isOrderable());
        $this->assertSame('Not on the menu', $dish->unavailableReason());
    }

    /* ------------------------------------------------------------- sizes */

    public function test_a_dish_may_have_sizes_with_their_own_prices(): void
    {
        $dish = $this->dish(['name' => 'Chicken Biryani']);

        ProductVariant::query()->create([
            'product_id' => $dish->id, 'name' => 'Half', 'price' => 260, 'sort_order' => 0,
        ]);
        ProductVariant::query()->create([
            'product_id' => $dish->id, 'name' => 'Full', 'price' => 440, 'is_default' => true, 'sort_order' => 1,
        ]);

        $this->assertTrue($dish->hasVariants());
        $this->assertSame(2, $dish->variants()->count());
        $this->assertSame('Full', $dish->variants()->where('is_default', true)->value('name'));
    }

    public function test_making_a_size_default_demotes_its_siblings(): void
    {
        $dish = $this->dish();

        $half = ProductVariant::query()->create([
            'product_id' => $dish->id, 'name' => 'Half', 'price' => 190, 'is_default' => true,
        ]);
        $full = ProductVariant::query()->create([
            'product_id' => $dish->id, 'name' => 'Full', 'price' => 320,
        ]);

        $full->makeDefault();

        $this->assertFalse($half->fresh()->is_default);
        $this->assertTrue($full->fresh()->is_default);
    }

    /** Two sizes of one dish cannot share a name. */
    public function test_a_duplicate_size_name_is_refused_by_the_database(): void
    {
        $dish = $this->dish();

        ProductVariant::query()->create(['product_id' => $dish->id, 'name' => 'Full', 'price' => 320]);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        ProductVariant::query()->create(['product_id' => $dish->id, 'name' => 'Full', 'price' => 400]);
    }

    /* ----------------------------------------------------------- add-ons */

    private function question(array $overrides = []): Modifier
    {
        $modifier = new Modifier(array_merge([
            'shop_id' => $this->shop()->id,
            'name' => 'Choose your crust',
            'min_select' => 1,
            'max_select' => 1,
            'is_active' => true,
        ], $overrides));

        $modifier->save();

        return $modifier;
    }

    /**
     * min/max covers every shape a menu question takes, which is why there
     * is no `type` column to switch on.
     */
    public function test_the_rule_reads_the_way_a_menu_is_written(): void
    {
        $this->assertSame('Choose 1', $this->question(['min_select' => 1, 'max_select' => 1])->ruleLabel());

        $this->assertSame(
            'Optional — add any',
            $this->question(['name' => 'Toppings', 'min_select' => 0, 'max_select' => null])->ruleLabel(),
        );

        $this->assertSame(
            'Optional — up to 2',
            $this->question(['name' => 'Sides', 'min_select' => 0, 'max_select' => 2])->ruleLabel(),
        );

        $this->assertSame(
            'Choose 1 to 3',
            $this->question(['name' => 'Dips', 'min_select' => 1, 'max_select' => 3])->ruleLabel(),
        );
    }

    /**
     * The one place the rule is decided, so the cart, the POS and the API
     * cannot disagree about whether a pizza has a crust.
     */
    public function test_a_required_question_refuses_no_answer(): void
    {
        $crust = $this->question(['min_select' => 1, 'max_select' => 1]);

        $this->assertFalse($crust->accepts(0));
        $this->assertTrue($crust->accepts(1));
        $this->assertFalse($crust->accepts(2));
    }

    public function test_an_unbounded_question_accepts_any_number(): void
    {
        $toppings = $this->question(['name' => 'Toppings', 'min_select' => 0, 'max_select' => null]);

        $this->assertTrue($toppings->accepts(0));
        $this->assertTrue($toppings->accepts(9));
    }

    public function test_a_capped_question_refuses_one_too_many(): void
    {
        $sides = $this->question(['name' => 'Sides', 'min_select' => 0, 'max_select' => 2]);

        $this->assertTrue($sides->accepts(2));
        $this->assertFalse($sides->accepts(3));
    }

    /**
     * One question, many dishes - that is the whole reason this is not a
     * JSON blob on the product.
     */
    public function test_one_question_is_shared_by_many_dishes(): void
    {
        $crust = $this->question();

        $margherita = $this->dish(['name' => 'Margherita', 'slug' => 'margherita']);
        $farmhouse = $this->dish(['name' => 'Farmhouse', 'slug' => 'farmhouse']);

        $crust->products()->sync([
            $margherita->id => ['sort_order' => 0],
            $farmhouse->id => ['sort_order' => 0],
        ]);

        $this->assertSame(2, $crust->products()->count());
        $this->assertSame('Choose your crust', $margherita->modifiers()->first()->name);
    }

    /** A free option says nothing rather than "₹0.00". */
    public function test_a_free_option_prints_no_price(): void
    {
        $crust = $this->question();

        $thin = ModifierOption::query()->create([
            'modifier_id' => $crust->id, 'name' => 'Thin crust', 'price' => 0,
        ]);
        $burst = ModifierOption::query()->create([
            'modifier_id' => $crust->id, 'name' => 'Cheese burst', 'price' => 70,
        ]);

        $this->assertSame('', $thin->priceLabel());
        $this->assertSame('+ ₹70.00', $burst->priceLabel());
    }

    /** "No cheese, −₹20" is the whole reason somebody ticks it. */
    public function test_a_negative_option_keeps_its_sign(): void
    {
        $crust = $this->question();

        $none = ModifierOption::query()->create([
            'modifier_id' => $crust->id, 'name' => 'No cheese', 'price' => -20,
        ]);

        $this->assertSame('− ₹20.00', $none->priceLabel());
    }

    /**
     * Questions are one branch's, with one branch's prices.
     *
     * Signed in on purpose: the shop scope steps aside with no authenticated
     * user so that console and queue context can read every branch. A test
     * that did not act as somebody would be exercising the escape hatch
     * rather than the rule.
     */
    public function test_another_branches_questions_are_not_listed(): void
    {
        $this->question(['name' => 'Ours']);

        $other = Shop::query()->create([
            'tenant_id' => $this->shop()->tenant_id,
            'name' => 'Second Branch',
            'code' => 'TWO',
            'slug' => Shop::uniqueSlug('Second Branch'),
            'is_active' => true,
        ]);

        $theirs = new Modifier([
            'shop_id' => $other->id, 'name' => 'Theirs', 'min_select' => 0, 'is_active' => true,
        ]);
        $theirs->save();

        $staff = User::where('is_admin', true)->firstOrFail();
        $staff->shops()->syncWithoutDetaching([$this->shop()->id => ['is_default' => true]]);
        $staff->forceFill([
            'current_shop_id' => $this->shop()->id,
            'all_shops_view' => false,
        ])->save();

        $this->actingAs($staff);
        CurrentShop::forget();

        $this->assertSame(['Ours'], Modifier::query()->pluck('name')->all());
    }

    /* -------------------------------------------------------- the seeder */

    /**
     * The demo menu is what every other screen is judged against, so it has
     * to actually carry the things it claims to.
     */
    public function test_the_demo_menu_covers_the_cases_it_is_there_for(): void
    {
        $this->seed(\Database\Seeders\DemoDataSeeder::class);

        CurrentShop::set($this->shop()->id);

        // Both marks, and a row with none.
        $this->assertTrue(Product::query()->where('food_type', 'veg')->exists());
        $this->assertTrue(Product::query()->where('food_type', 'non_veg')->exists());
        $this->assertTrue(Product::query()->whereNull('food_type')->exists());

        // A breakfast window, a sold-out dish, sizes and add-ons.
        $this->assertTrue(Product::query()->whereNotNull('available_from')->exists());
        $this->assertTrue(Product::query()->where('is_sold_out', true)->exists());
        $this->assertGreaterThan(0, ProductVariant::query()->count());
        $this->assertGreaterThan(0, Modifier::query()->count());

        // Sub-categories, which is what makes the card two deep.
        $this->assertTrue(\App\Models\Category::query()->whereNotNull('parent_id')->exists());
    }
}
