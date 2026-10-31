<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\ParkedSale;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\CurrentShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * A sale put to one side (§6 — hold/resume).
 *
 * The property worth defending hardest is that a held sale is NOT a document.
 * Nothing is numbered, no stock moves, no ledger line exists — because the
 * alternative puts a hole in the invoice series every time somebody changes
 * their mind at the counter.
 *
 * The second is that two cashiers cannot both pick up the same basket, which
 * would bill one customer's shopping twice.
 */
class HeldSaleTest extends TestCase
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

    /** @param array<int, string> $permissions */
    private function cashier(array $permissions = ['pos.terminal.view', 'pos.terminal.create']): User
    {
        $user = User::query()->firstWhere('email', 'till@example.test');

        if ($user === null) {
            $user = User::create([
                'tenant_id' => $this->shop()->tenant_id,
                'name' => 'Till',
                'email' => 'till@example.test',
                'password' => 'till-password-1',
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

    private function dish(): Product
    {
        return Product::create([
            'name' => 'Dal Makhani',
            'slug' => Product::uniqueSlug('Dal Makhani'),
            'sku' => Product::generateSku('Dal Makhani'),
            'selling_price' => 280,
            'is_active' => true,
            'is_made_to_order' => true,
        ]);
    }

    private function warehouse(): Warehouse
    {
        return Warehouse::query()->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function cart(?Product $dish = null): array
    {
        $dish ??= $this->dish();

        return [
            'channel' => Invoice::POS,
            'warehouse_id' => $this->warehouse()->id,
            'items' => [
                ['product_id' => $dish->id, 'quantity' => 2, 'unit_price' => 280],
            ],
            'grand_total' => 560,
        ];
    }

    /* ------------------------------------------------------------ holding */

    public function test_holding_a_sale_writes_no_document(): void
    {
        $this->actingAs($this->cashier());
        CurrentShop::forget();

        $this->postJson(route('admin.pos.park'), $this->cart() + ['label' => 'Blue jacket'])
            ->assertOk();

        $held = ParkedSale::query()->firstOrFail();

        $this->assertSame('Blue jacket', $held->label);
        $this->assertSame(1, $held->line_count);

        // The whole point: no invoice, no number burnt, no stock moved.
        $this->assertSame(0, Invoice::query()->count());
    }

    public function test_a_hold_is_filed_with_what_the_basket_came_to(): void
    {
        $this->actingAs($this->cashier());
        CurrentShop::forget();

        $this->postJson(route('admin.pos.park'), $this->cart())->assertOk();

        // The Held Sales list exists so a cashier can tell two parked baskets
        // apart. A column of 0.00 against every one of them - which is what
        // happened while the till's running total was a screen figure and not
        // a form field - is the same as no column at all.
        $this->assertSame(560.0, (float) ParkedSale::query()->firstOrFail()->total);
    }

    public function test_a_hold_that_sends_no_total_is_priced_from_its_own_lines(): void
    {
        $this->actingAs($this->cashier());
        CurrentShop::forget();

        $cart = $this->cart();
        unset($cart['grand_total']);

        $this->postJson(route('admin.pos.park'), $cart)->assertOk();

        $this->assertSame(560.0, (float) ParkedSale::query()->firstOrFail()->total);
    }

    public function test_a_total_the_browser_inflated_is_not_believed(): void
    {
        $this->actingAs($this->cashier());
        CurrentShop::forget();

        // A discount can take the figure down; nothing should take it up.
        $this->postJson(route('admin.pos.park'), $this->cart() + ['grand_total' => 99999])
            ->assertOk();

        $this->assertSame(560.0, (float) ParkedSale::query()->firstOrFail()->total);
    }

    public function test_the_held_sales_screen_loads_the_script_its_resume_button_needs(): void
    {
        $this->actingAs($this->cashier());
        CurrentShop::forget();

        $this->postJson(route('admin.pos.park'), $this->cart())->assertOk();

        /*
         | The button rendered, looked live, and did nothing at all when
         | pressed: the delegated click handler that claims a held sale lives
         | in pos.js, and pos.js was only ever loaded on the till. A parked
         | basket was a one-way trip.
         */
        $this->get(route('admin.pos.parked'))
            ->assertOk()
            ->assertSee('data-pos-resume', false)
            ->assertSee('assets/js/pos.js', false);
    }

    public function test_the_reference_is_short_enough_to_read_across_a_counter(): void
    {
        $this->actingAs($this->cashier());
        CurrentShop::forget();

        $this->postJson(route('admin.pos.park'), $this->cart())->assertOk();
        $this->postJson(route('admin.pos.park'), $this->cart())->assertOk();

        $references = ParkedSale::query()->orderBy('id')->pluck('reference')->all();

        // "Get hold two up for me" has to be sayable.
        $this->assertSame(['HOLD-001', 'HOLD-002'], $references);
    }

    public function test_an_empty_basket_cannot_be_held(): void
    {
        $this->actingAs($this->cashier());
        CurrentShop::forget();

        $this->postJson(route('admin.pos.park'), [
            'channel' => Invoice::POS,
            'warehouse_id' => $this->warehouse()->id,
            'items' => [],
        ])->assertStatus(422);
    }

    public function test_an_unfinished_sale_can_still_be_held(): void
    {
        $this->actingAs($this->cashier());
        CurrentShop::forget();

        // No customer, no payment, no warehouse even. A cart is held
        // precisely because it is not finished, so the checks a completed
        // sale must pass do not apply.
        $this->postJson(route('admin.pos.park'), [
            'channel' => Invoice::POS,
            'items' => [['product_id' => $this->dish()->id, 'quantity' => 1]],
        ])->assertOk();

        $this->assertSame(1, ParkedSale::query()->count());
    }

    /* ----------------------------------------------------------- resuming */

    public function test_resuming_gives_the_basket_back_and_removes_the_hold(): void
    {
        $this->actingAs($this->cashier());
        CurrentShop::forget();

        $dish = $this->dish();
        $this->postJson(route('admin.pos.park'), $this->cart($dish));

        $held = ParkedSale::query()->firstOrFail();

        $response = $this->postJson(route('admin.pos.resume', $held));

        $response->assertOk();

        $this->assertSame(2, (int) $response->json('data.payload.items.0.quantity'));

        // The product comes back whole, so the line editor can draw a row
        // from the same shape a scan produces.
        $this->assertSame('Dal Makhani', $response->json('data.products.'.$dish->id.'.name'));

        // And it is gone, so nobody else can pick it up.
        $this->assertSame(0, ParkedSale::query()->count());
    }

    public function test_two_cashiers_cannot_both_pick_up_the_same_basket(): void
    {
        $this->actingAs($this->cashier());
        CurrentShop::forget();

        $this->postJson(route('admin.pos.park'), $this->cart());
        $held = ParkedSale::query()->firstOrFail();

        $this->postJson(route('admin.pos.resume', $held))->assertOk();

        // Billing one customer's shopping twice is the failure this prevents.
        $second = $this->postJson(route('admin.pos.resume', $held));

        $second->assertStatus(404);
    }

    public function test_a_line_whose_dish_left_the_menu_is_reported_not_silently_dropped(): void
    {
        $this->actingAs($this->cashier());
        CurrentShop::forget();

        $dish = $this->dish();
        $this->postJson(route('admin.pos.park'), $this->cart($dish));

        $dish->delete();

        $response = $this->postJson(route('admin.pos.resume', ParkedSale::query()->firstOrFail()));

        $response->assertOk();

        // A row with a blank name that fails on submit would be worse than
        // being told plainly.
        $this->assertStringContainsString('no longer on the menu', $response->json('message'));
        $this->assertSame([], $response->json('data.products'));
    }

    /* --------------------------------------------------------- discarding */

    public function test_a_held_sale_can_be_thrown_away(): void
    {
        $this->actingAs($this->cashier());
        CurrentShop::forget();

        $this->postJson(route('admin.pos.park'), $this->cart());

        $this->deleteJson(route('admin.pos.discard', ParkedSale::query()->firstOrFail()))
            ->assertOk();

        $this->assertSame(0, ParkedSale::query()->count());
        // Nothing was billed, so nothing is reversed.
        $this->assertSame(0, Invoice::query()->count());
    }

    /* -------------------------------------------------------- the screens */

    public function test_the_held_sales_screen_lists_what_is_waiting(): void
    {
        $this->actingAs($this->cashier());
        CurrentShop::forget();

        $this->postJson(route('admin.pos.park'), $this->cart() + ['label' => 'Blue jacket']);

        $this->get(route('admin.pos.parked'))
            ->assertOk()
            ->assertSee('Blue jacket')
            ->assertSee('HOLD-001');
    }

    public function test_the_till_offers_the_hold_button(): void
    {
        $this->actingAs($this->cashier());
        CurrentShop::forget();

        $this->get(route('admin.pos.terminal'))
            ->assertOk()
            ->assertSee('data-pos-hold', false);
    }

    public function test_holding_needs_the_right_to_bill(): void
    {
        $this->actingAs($this->cashier(['pos.terminal.view']));
        CurrentShop::forget();

        $this->postJson(route('admin.pos.park'), $this->cart())->assertForbidden();
    }

    public function test_a_hold_belongs_to_its_own_branch(): void
    {
        $this->actingAs($this->cashier());
        CurrentShop::forget();

        $this->postJson(route('admin.pos.park'), $this->cart());

        $this->assertSame($this->shop()->id, ParkedSale::query()->firstOrFail()->shop_id);
    }
}
