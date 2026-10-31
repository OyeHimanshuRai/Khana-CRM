<?php

namespace Tests\Feature;

use App\Models\Floor;
use App\Models\Product;
use App\Models\RestaurantTable;
use App\Models\Shop;
use App\Services\TableQrService;
use App\Support\CurrentShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The guest journey is limited per table, not per internet connection.
 *
 * Every guest in a restaurant on its Wi-Fi shares one public address. The
 * limits on scanning, the cart and sending an order were per address, so on
 * a busy evening the eleventh table to order inside a minute was shown a bare
 * "429 Too Many Requests" and its order never reached the kitchen. A limit
 * that is there to stop a stuck finger now counts that one table's taps; a
 * far higher per-address ceiling still stops a script.
 */
class GuestRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private Floor $floor;

    private Product $dish;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');
        $this->seed();
        CurrentShop::forget();

        $shop = Shop::query()->withoutGlobalScopes()->orderBy('id')->firstOrFail();
        $this->floor = Floor::create(['shop_id' => $shop->id, 'name' => 'Ground', 'code' => 'GF', 'is_active' => true]);
        $this->dish = Product::create([
            'name' => 'Dal Makhani', 'slug' => Product::uniqueSlug('Dal Makhani'), 'sku' => Product::generateSku('Dal Makhani'),
            'selling_price' => 280, 'is_active' => true,
        ]);
    }

    private function table(int $n): RestaurantTable
    {
        $table = RestaurantTable::create([
            'shop_id' => $this->floor->shop_id, 'floor_id' => $this->floor->id, 'name' => (string) $n, 'code' => 'GF-'.$n,
            'capacity' => 4, 'status' => RestaurantTable::AVAILABLE, 'is_active' => true,
        ]);
        app(TableQrService::class)->issue($table);

        return $table->fresh(['activeQr']);
    }

    /** A new phone at a table: its own cookies, the restaurant's one address. */
    private function guestOrdersAt(RestaurantTable $table)
    {
        $this->flushSession();
        $this->app['session']->flush();

        $this->get('/t/'.$table->activeQr->token)->assertRedirect(route('table.show'));
        $this->post('/t/cart', ['product_id' => $this->dish->id])->assertRedirect();

        return $this->post('/t/order');
    }

    public function test_a_busy_evening_on_one_wifi_is_not_throttled(): void
    {
        for ($n = 1; $n <= 14; $n++) {
            $this->guestOrdersAt($this->table($n))->assertRedirect(route('table.orders'));
        }

        $this->assertSame(14, \App\Models\Order::query()->withoutGlobalScopes()->count());
    }

    public function test_one_table_hammering_send_is_still_stopped(): void
    {
        $table = $this->table(1);
        $this->get('/t/'.$table->activeQr->token);

        $statuses = [];
        for ($i = 0; $i < 12; $i++) {
            $this->post('/t/cart', ['product_id' => $this->dish->id]);
            $statuses[] = $this->post('/t/order')->status();
        }

        $this->assertContains(429, $statuses, 'a stuck finger is still limited');
    }

    public function test_being_limited_shows_the_guest_a_page_in_words(): void
    {
        $table = $this->table(1);
        $this->get('/t/'.$table->activeQr->token);

        for ($i = 0; $i < 11; $i++) {
            $this->post('/t/cart', ['product_id' => $this->dish->id]);
            $last = $this->post('/t/order');
        }

        $last->assertStatus(429)->assertSee('Too many taps')->assertSee('Your orders');
    }
}
