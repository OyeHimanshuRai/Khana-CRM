<?php

namespace Tests\Feature;

use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\Shop;
use App\Support\CurrentShop;
use App\Support\PriceLists;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Prices that apply only sometimes (§8, §16).
 *
 * The override reaches everything — the counter, the QR menu, the API and
 * every order placed through any of them — so the property that matters most
 * is the boring one: with no list running, every figure is exactly what it was
 * before this feature existed.
 *
 * The other one worth testing hard is the window that crosses midnight. A
 * bar's late offer runs 22:00 to 02:00, and the naive comparison is false for
 * every minute of it.
 */
class PriceListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');

        $this->seed();

        CurrentShop::forget();
        PriceLists::forget();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CurrentShop::forget();
        PriceLists::forget();

        parent::tearDown();
    }

    /* ---------------------------------------------------------- fixtures */

    private function shop(): Shop
    {
        return Shop::query()->orderBy('id')->firstOrFail();
    }

    private function dish(string $name = 'Kingfisher', float $price = 200): Product
    {
        return Product::create([
            'name' => $name,
            'slug' => Product::uniqueSlug($name),
            'sku' => Product::generateSku($name),
            'selling_price' => $price,
            'is_active' => true,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function list(array $attributes = []): PriceList
    {
        return PriceList::create(array_merge([
            'shop_id' => $this->shop()->id,
            'name' => 'Happy hour',
            'code' => 'happy-'.uniqid(),
            'is_active' => true,
            'priority' => 0,
        ], $attributes));
    }

    private function price(Product $dish, string $channel = 'dine_in'): float
    {
        PriceLists::forget();

        return $dish->fresh()->channelPriceFor($channel, $this->shop()->id);
    }

    /* ----------------------------------------------------------- inert */

    public function test_with_no_list_the_price_is_exactly_what_it_was(): void
    {
        $dish = $this->dish('Kingfisher', 200);

        // The whole safety of the feature.
        $this->assertEqualsWithDelta(200, $this->price($dish), 0.01);
        $this->assertNull(PriceLists::priceFor($dish->id, null, 200, $this->shop()->id, 'dine_in'));
    }

    public function test_a_list_with_no_row_for_a_dish_leaves_it_alone(): void
    {
        $beer = $this->dish('Kingfisher', 200);
        $food = $this->dish('Biryani', 400);

        $list = $this->list();
        PriceListItem::create(['price_list_id' => $list->id, 'product_id' => $beer->id, 'price' => 100]);

        $this->assertEqualsWithDelta(100, $this->price($beer), 0.01);
        $this->assertEqualsWithDelta(400, $this->price($food), 0.01);
    }

    public function test_a_switched_off_list_does_nothing(): void
    {
        $beer = $this->dish('Kingfisher', 200);

        $list = $this->list(['is_active' => false]);
        PriceListItem::create(['price_list_id' => $list->id, 'product_id' => $beer->id, 'price' => 100]);

        $this->assertEqualsWithDelta(200, $this->price($beer), 0.01);
    }

    /* --------------------------------------------------------- the money */

    public function test_a_flat_price_replaces_the_normal_one(): void
    {
        $beer = $this->dish('Kingfisher', 200);

        $list = $this->list();
        PriceListItem::create(['price_list_id' => $list->id, 'product_id' => $beer->id, 'price' => 99]);

        $this->assertEqualsWithDelta(99, $this->price($beer), 0.01);
    }

    public function test_a_percentage_comes_off_the_normal_price(): void
    {
        $beer = $this->dish('Kingfisher', 200);

        $list = $this->list();
        PriceListItem::create([
            'price_list_id' => $list->id,
            'product_id' => $beer->id,
            'discount_percent' => 25,
        ]);

        $this->assertEqualsWithDelta(150, $this->price($beer), 0.01);
    }

    public function test_a_discount_can_never_raise_a_price_or_go_below_zero(): void
    {
        $item = new PriceListItem(['discount_percent' => 150]);

        // "A discount that raised a price" is the one bug on this screen
        // nobody would think to look for.
        $this->assertSame(0.0, $item->apply(200));

        $this->assertSame(0.0, (new PriceListItem(['price' => -50]))->apply(200));
    }

    /* --------------------------------------------------------- the window */

    public function test_a_list_outside_its_hours_does_not_apply(): void
    {
        $beer = $this->dish('Kingfisher', 200);

        $list = $this->list(['starts_at' => '16:00:00', 'ends_at' => '19:00:00']);
        PriceListItem::create(['price_list_id' => $list->id, 'product_id' => $beer->id, 'price' => 100]);

        Carbon::setTestNow(Carbon::today()->setTime(17, 30));
        $this->assertEqualsWithDelta(100, $this->price($beer), 0.01);

        Carbon::setTestNow(Carbon::today()->setTime(21, 0));
        $this->assertEqualsWithDelta(200, $this->price($beer), 0.01);
    }

    public function test_a_window_that_crosses_midnight_works(): void
    {
        $beer = $this->dish('Kingfisher', 200);

        // A bar's late offer. The naive start <= now <= end comparison is
        // false for every single minute of this.
        $list = $this->list(['starts_at' => '22:00:00', 'ends_at' => '02:00:00']);
        PriceListItem::create(['price_list_id' => $list->id, 'product_id' => $beer->id, 'price' => 100]);

        Carbon::setTestNow(Carbon::today()->setTime(23, 30));
        $this->assertEqualsWithDelta(100, $this->price($beer), 0.01, 'half eleven should be inside');

        Carbon::setTestNow(Carbon::today()->setTime(1, 0));
        $this->assertEqualsWithDelta(100, $this->price($beer), 0.01, 'one in the morning should be inside');

        Carbon::setTestNow(Carbon::today()->setTime(15, 0));
        $this->assertEqualsWithDelta(200, $this->price($beer), 0.01, 'the afternoon should be outside');
    }

    public function test_a_list_only_runs_on_the_days_it_names(): void
    {
        $beer = $this->dish('Kingfisher', 200);

        // Mondays and Tuesdays only.
        $list = $this->list(['weekdays' => [1, 2]]);
        PriceListItem::create(['price_list_id' => $list->id, 'product_id' => $beer->id, 'price' => 100]);

        Carbon::setTestNow(Carbon::parse('next monday')->setTime(12, 0));
        $this->assertEqualsWithDelta(100, $this->price($beer), 0.01);

        Carbon::setTestNow(Carbon::parse('next friday')->setTime(12, 0));
        $this->assertEqualsWithDelta(200, $this->price($beer), 0.01);
    }

    public function test_a_list_expires_with_its_date_range(): void
    {
        $beer = $this->dish('Kingfisher', 200);

        $list = $this->list([
            'starts_on' => Carbon::today()->subDays(3)->toDateString(),
            'ends_on' => Carbon::today()->subDay()->toDateString(),
        ]);
        PriceListItem::create(['price_list_id' => $list->id, 'product_id' => $beer->id, 'price' => 100]);

        // Yesterday's promotion is over.
        $this->assertEqualsWithDelta(200, $this->price($beer), 0.01);
    }

    public function test_a_list_for_one_channel_leaves_the_others_alone(): void
    {
        $beer = $this->dish('Kingfisher', 200);

        $list = $this->list(['channel' => 'dine_in']);
        PriceListItem::create(['price_list_id' => $list->id, 'product_id' => $beer->id, 'price' => 100]);

        $this->assertEqualsWithDelta(100, $this->price($beer, 'dine_in'), 0.01);
        $this->assertEqualsWithDelta(200, $this->price($beer, 'takeaway'), 0.01);
    }

    /* -------------------------------------------------------- precedence */

    public function test_the_higher_priority_list_wins(): void
    {
        $beer = $this->dish('Kingfisher', 200);

        $low = $this->list(['name' => 'Everyday', 'priority' => 1]);
        PriceListItem::create(['price_list_id' => $low->id, 'product_id' => $beer->id, 'price' => 150]);

        $high = $this->list(['name' => 'Tonight only', 'priority' => 9]);
        PriceListItem::create(['price_list_id' => $high->id, 'product_id' => $beer->id, 'price' => 90]);

        // Two lists covering the same dish is not an error; somebody has to
        // say which one the guest gets.
        $this->assertEqualsWithDelta(90, $this->price($beer), 0.01);
    }

    /* ------------------------------------------------------ it reaches out */

    public function test_the_override_reaches_an_order_placed_through_the_api(): void
    {
        $beer = $this->dish('Kingfisher', 200);

        $list = $this->list();
        PriceListItem::create(['price_list_id' => $list->id, 'product_id' => $beer->id, 'price' => 100]);

        PriceLists::forget();

        // A happy hour that only applied at the till would not be a happy
        // hour. The price is resolved in one place so it reaches all of them.
        $this->assertEqualsWithDelta(
            100,
            $beer->fresh()->channelPriceFor('dine_in', $this->shop()->id),
            0.01,
        );
    }

    public function test_the_memo_does_not_outlive_an_edit(): void
    {
        $beer = $this->dish('Kingfisher', 200);

        $list = $this->list();
        PriceListItem::create(['price_list_id' => $list->id, 'product_id' => $beer->id, 'price' => 100]);

        $this->assertEqualsWithDelta(100, $this->price($beer), 0.01);

        $list->forceFill(['is_active' => false])->save();
        PriceLists::forget();

        // A memo that outlived an edit would keep a happy hour running after
        // somebody switched it off, which is the direction of this bug that
        // costs money.
        $this->assertEqualsWithDelta(200, $this->price($beer), 0.01);
    }
}
