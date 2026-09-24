<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Shop;
use App\Models\StockMovement;
use App\Models\StockWastage;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StockService;
use App\Services\WastageService;
use App\Support\CurrentShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Tests\TestCase;

/**
 * What the kitchen threw away (§10).
 *
 * Unrecorded waste is indistinguishable from theft, from over-portioning and
 * from a recipe that is simply wrong - so the thing worth testing hardest is
 * that recording it is easy and that the value it produces is right.
 */
class WastageTest extends TestCase
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

    private function ingredient(string $name, float $cost, float $onHand = 20): Product
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

    private function onHand(Product $product): float
    {
        return (float) ProductStock::allShops()->where('product_id', $product->id)->sum('quantity');
    }

    private function staff(array $permissions = []): User
    {
        $user = User::query()->firstWhere('email', 'cook@example.test');

        if ($user === null) {
            $user = User::create([
                'tenant_id' => $this->shop()->tenant_id,
                'name' => 'Line Cook',
                'email' => 'cook@example.test',
                'password' => 'cook-password-1',
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

    /* ---------------------------------------------------------- recording */

    public function test_writing_something_off_takes_it_off_the_shelf_and_values_it(): void
    {
        $this->actingAs($this->staff(['inventory.wastage.create']));

        $paneer = $this->ingredient('Paneer', 340, 20);

        $wastage = app(WastageService::class)->record([
            'product' => $paneer,
            'quantity' => 2,
            'reason_code' => 'spoiled',
            'note' => 'Fridge failed overnight',
        ]);

        $this->assertEqualsWithDelta(18.0, $this->onHand($paneer), 0.001);

        // Valued at what this shop paid for the stock that left.
        $this->assertSame(680.0, (float) $wastage->cost_value);
        $this->assertSame('Fridge failed overnight', $wastage->note);

        // And the movement points back at the note somebody typed, so the
        // stock ledger's "why is there 2 kg less" leads straight to it.
        $movement = StockMovement::allShops()
            ->where('product_id', $paneer->id)
            ->where('type', StockMovement::WASTAGE)
            ->firstOrFail();

        $this->assertStringContainsString('Fridge failed overnight', (string) $movement->reason);
    }

    /**
     * There is no count of Butter Naan to take away from.
     *
     * Refused rather than silently ignored: somebody writing off two portions
     * means the flour and butter that went into them, and recording nothing
     * would leave them believing their store had been corrected.
     */
    public function test_a_made_to_order_dish_cannot_be_written_off(): void
    {
        $this->actingAs($this->staff(['inventory.wastage.create']));

        $dish = new Product([
            'name' => 'Butter Naan',
            'slug' => Product::uniqueSlug('Butter Naan'),
            'sku' => Product::generateSku('DISH'),
            'selling_price' => 60,
            'is_active' => true,
            'is_made_to_order' => true,
        ]);
        $dish->save();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('made to order');

        app(WastageService::class)->record([
            'product' => $dish,
            'quantity' => 2,
            'reason_code' => 'burnt',
        ]);
    }

    public function test_a_reason_this_system_does_not_know_is_refused(): void
    {
        $this->actingAs($this->staff(['inventory.wastage.create']));

        $this->expectException(RuntimeException::class);

        app(WastageService::class)->record([
            'product' => $this->ingredient('Paneer', 340),
            'quantity' => 1,
            'reason_code' => 'because',
        ]);
    }

    /* ----------------------------------------------------------- the value */

    /**
     * A staff meal is stock that left unsold, not a kitchen's failure.
     *
     * A headline number that included it would have a manager chasing
     * something that is working as intended.
     */
    public function test_staff_meals_are_deducted_but_are_not_counted_as_loss(): void
    {
        $this->actingAs($this->staff(['inventory.wastage.create']));

        $paneer = $this->ingredient('Paneer', 340, 20);
        $butter = $this->ingredient('Butter', 520, 20);

        $wastage = app(WastageService::class);

        $wastage->record(['product' => $paneer, 'quantity' => 2, 'reason_code' => 'spoiled']);
        $wastage->record(['product' => $butter, 'quantity' => 0.5, 'reason_code' => 'staff']);

        $summary = $wastage->summary(now()->startOfMonth(), now()->endOfMonth());

        $this->assertSame(2, $summary['entries']);
        // 2 * 340 = 680, plus 0.5 * 520 = 260.
        $this->assertSame(940.0, $summary['value']);
        $this->assertSame(680.0, $summary['loss']);

        // Both still left the shelf, because the food is gone either way.
        $this->assertEqualsWithDelta(18.0, $this->onHand($paneer), 0.001);
        $this->assertEqualsWithDelta(19.5, $this->onHand($butter), 0.001);
    }

    public function test_the_summary_names_the_biggest_reason(): void
    {
        $this->actingAs($this->staff(['inventory.wastage.create']));

        $paneer = $this->ingredient('Paneer', 340, 20);
        $butter = $this->ingredient('Butter', 520, 20);

        $wastage = app(WastageService::class);

        $wastage->record(['product' => $paneer, 'quantity' => 3, 'reason_code' => 'spoiled']);
        $wastage->record(['product' => $butter, 'quantity' => 0.2, 'reason_code' => 'dropped']);

        $summary = $wastage->summary(now()->startOfMonth(), now()->endOfMonth());

        $this->assertSame('Spoiled', $summary['by_reason']->first()['label']);
    }

    /* -------------------------------------------------------- the reversal */

    /**
     * The stock returns through a fresh movement rather than by deleting the
     * old one: a ledger that can lose a row is a ledger nobody can reconcile.
     */
    public function test_reversing_puts_the_stock_back_and_leaves_the_ledger_intact(): void
    {
        $this->actingAs($this->staff(['inventory.wastage.create', 'inventory.wastage.delete']));

        $paneer = $this->ingredient('Paneer', 340, 20);

        $wastage = app(WastageService::class);
        $row = $wastage->record(['product' => $paneer, 'quantity' => 4, 'reason_code' => 'spoiled']);

        $this->assertEqualsWithDelta(16.0, $this->onHand($paneer), 0.001);

        $wastage->reverse($row, 'Counted wrong');

        $this->assertEqualsWithDelta(20.0, $this->onHand($paneer), 0.001);
        $this->assertSame(0, StockWastage::allShops()->count());

        // Two movements, not zero: the mistake and its correction.
        $this->assertSame(
            2,
            StockMovement::allShops()
                ->where('product_id', $paneer->id)
                ->where('type', StockMovement::WASTAGE)
                ->count(),
        );
    }

    /* ---------------------------------------------------------- the screen */

    public function test_a_cook_records_wastage_over_http(): void
    {
        $paneer = $this->ingredient('Paneer', 340, 20);

        $this->actingAs($this->staff(['inventory.wastage.view', 'inventory.wastage.create']))
            ->postJson('/admin/wastage', [
                'product_id' => $paneer->id,
                'quantity' => 1.5,
                'reason_code' => 'dropped',
                'note' => 'Slipped on the pass',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.value', 510);

        $this->assertEqualsWithDelta(18.5, $this->onHand($paneer), 0.001);
    }

    public function test_the_log_shows_what_was_thrown_away(): void
    {
        $this->actingAs($this->staff(['inventory.wastage.create']));

        $paneer = $this->ingredient('Paneer', 340, 20);

        app(WastageService::class)->record([
            'product' => $paneer,
            'quantity' => 2,
            'reason_code' => 'spoiled',
            'note' => 'Fridge failed overnight',
        ]);

        $this->actingAs($this->staff(['inventory.wastage.view']))
            ->get('/admin/wastage?preset=this_month')
            ->assertOk()
            ->assertSee('Paneer')
            ->assertSee('Fridge failed overnight')
            ->assertSee('680.00');
    }

    /** A cook records it; putting it back is a different right. */
    public function test_reversing_needs_its_own_right(): void
    {
        $this->actingAs($this->staff(['inventory.wastage.create']));

        $paneer = $this->ingredient('Paneer', 340, 20);
        $row = app(WastageService::class)->record([
            'product' => $paneer,
            'quantity' => 2,
            'reason_code' => 'spoiled',
        ]);

        $this->actingAs($this->staff(['inventory.wastage.view']))
            ->deleteJson('/admin/wastage/'.$row->id)
            ->assertForbidden();

        $this->assertEqualsWithDelta(18.0, $this->onHand($paneer), 0.001);
    }

    public function test_the_screen_is_closed_without_the_right(): void
    {
        $this->actingAs($this->staff())->get('/admin/wastage')->assertForbidden();
    }

    /** Made-to-order dishes are not even offered on the form. */
    public function test_the_form_offers_only_things_with_a_count(): void
    {
        $this->actingAs($this->staff(['inventory.wastage.create']));

        $this->ingredient('Paneer', 340);

        $dish = new Product([
            'name' => 'Butter Naan',
            'slug' => Product::uniqueSlug('Butter Naan'),
            'sku' => Product::generateSku('DISH'),
            'selling_price' => 60,
            'is_active' => true,
            'is_made_to_order' => true,
        ]);
        $dish->save();

        $names = app(WastageService::class)->writableOff()->pluck('name');

        $this->assertContains('Paneer', $names->all());
        $this->assertNotContains('Butter Naan', $names->all());
    }
}
