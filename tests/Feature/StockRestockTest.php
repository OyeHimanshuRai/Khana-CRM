<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Shop;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\CurrentShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * "Restock", from the item itself.
 *
 * The case that prompted it: a Cheese Cake Slice is out of stock, the row has
 * Edit and no way to put more on the shelf. These pin what the new action must
 * and must not do - add stock through the ledger with a reason, keep the cost
 * honest, refuse a dish (it has no shelf), and stay behind its own right.
 */
class StockRestockTest extends TestCase
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

    private function item(array $attributes = []): Product
    {
        $name = $attributes['name'] ?? 'Cheese Cake Slice';

        $product = new Product([
            'name' => $name,
            'slug' => Product::uniqueSlug($name),
            'sku' => Product::generateSku('CAKE'),
            'unit_id' => Unit::query()->where('code', 'PCS')->value('id'),
            'purchase_price' => 55,
            'selling_price' => 140,
            'is_active' => true,
            'is_made_to_order' => false,
            ...$attributes,
        ]);

        $product->save();

        return $product;
    }

    private function onHand(Product $product): float
    {
        return (float) ProductStock::allShops()->where('product_id', $product->id)->sum('quantity');
    }

    private function staff(array $permissions, string $email = 'baker@example.test'): User
    {
        $user = User::query()->firstWhere('email', $email);

        if ($user === null) {
            $user = User::create([
                'tenant_id' => $this->shop()->tenant_id,
                'name' => 'Baker',
                'email' => $email,
                'password' => 'baker-password-1',
                'is_admin' => true,
            ]);

            $user->shops()->syncWithoutDetaching([$this->shop()->id => ['is_default' => true]]);
            $user->forceFill(['current_shop_id' => $this->shop()->id, 'all_shops_view' => false])->save();
        }

        $user->givePermissionTo(array_merge(['dashboard.overview.view'], $permissions));

        return $user->fresh();
    }

    /* ------------------------------------------------------------ the job */

    public function test_an_out_of_stock_item_can_be_restocked_and_the_ledger_says_why(): void
    {
        $cake = $this->item();
        $this->assertSame(0.0, $this->onHand($cake));

        $this->actingAs($this->staff(['inventory.stock.restock']))
            ->postJson(route('admin.stock.restock', $cake), [
                'warehouse_id' => $this->warehouse()->id,
                'quantity' => 12,
                'unit_cost' => 60,
                'note' => 'Baked this morning',
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame(12.0, $this->onHand($cake));

        $movement = StockMovement::allShops()->where('product_id', $cake->id)->firstOrFail();

        $this->assertSame(StockMovement::RESTOCK, $movement->type);
        $this->assertSame(12.0, (float) $movement->quantity);
        $this->assertSame(60.0, (float) $movement->unit_cost);
        $this->assertStringContainsString('Baked this morning', (string) $movement->reason);
    }

    public function test_a_blank_cost_is_the_items_own_cost_not_zero(): void
    {
        $cake = $this->item(['purchase_price' => 55]);

        $this->actingAs($this->staff(['inventory.stock.restock']))
            ->postJson(route('admin.stock.restock', $cake), [
                'warehouse_id' => $this->warehouse()->id,
                'quantity' => 4,
            ])
            ->assertOk();

        // Zero would drag the weighted average down and flatter every later margin.
        $movement = StockMovement::allShops()->where('product_id', $cake->id)->firstOrFail();
        $this->assertSame(55.0, (float) $movement->unit_cost);
    }

    public function test_a_dish_made_to_order_has_nothing_to_restock(): void
    {
        $dish = $this->item(['name' => 'Butter Naan', 'is_made_to_order' => true]);

        $this->actingAs($this->staff(['inventory.stock.restock']))
            ->postJson(route('admin.stock.restock', $dish), [
                'warehouse_id' => $this->warehouse()->id,
                'quantity' => 5,
            ])
            ->assertStatus(422)
            ->assertJson(['success' => false]);

        $this->assertSame(0, StockMovement::allShops()->where('product_id', $dish->id)->count());
    }

    public function test_the_dialog_for_a_dish_explains_instead_of_offering_a_form(): void
    {
        $dish = $this->item(['name' => 'Butter Naan', 'is_made_to_order' => true]);

        $this->actingAs($this->staff(['inventory.stock.restock']))
            ->get(route('admin.stock.restock.form', $dish))
            ->assertOk()
            ->assertSee('Made to order')
            ->assertDontSee('Add stock');
    }

    public function test_a_batch_tracked_item_needs_a_batch_number(): void
    {
        $cream = $this->item(['name' => 'Fresh Cream', 'track_batches' => true]);

        $this->actingAs($this->staff(['inventory.stock.restock']))
            ->postJson(route('admin.stock.restock', $cream), [
                'warehouse_id' => $this->warehouse()->id,
                'quantity' => 6,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('batch_no');

        $this->assertSame(0.0, $this->onHand($cream));
    }

    public function test_a_batch_tracked_item_is_restocked_into_its_batch(): void
    {
        $cream = $this->item(['name' => 'Fresh Cream', 'track_batches' => true]);

        $this->actingAs($this->staff(['inventory.stock.restock']))
            ->postJson(route('admin.stock.restock', $cream), [
                'warehouse_id' => $this->warehouse()->id,
                'quantity' => 6,
                'batch_no' => 'FC-0042',
                'expiry_date' => now()->addDays(5)->toDateString(),
            ])
            ->assertOk();

        $batch = Batch::allShops()->where('product_id', $cream->id)->where('batch_no', 'FC-0042')->firstOrFail();
        $this->assertSame(now()->addDays(5)->toDateString(), $batch->expiry_date->toDateString());
        $this->assertSame(6.0, $this->onHand($cream));
    }

    public function test_the_quantity_has_to_be_more_than_zero(): void
    {
        $cake = $this->item();

        $this->actingAs($this->staff(['inventory.stock.restock']))
            ->postJson(route('admin.stock.restock', $cake), [
                'warehouse_id' => $this->warehouse()->id,
                'quantity' => 0,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('quantity');

        $this->assertSame(0.0, $this->onHand($cake));
    }

    /**
     * The case that started it: a Starter outlet (till and retail, no Inventory
     * module) sells a slice of cheesecake that has a shelf count. The row said
     * "Out of stock" and the Restock it now offers answered 403 "Inventory is not
     * switched on" - an action that fixes the problem, behind a module the plan
     * does not include. It is claimed by the selling modules instead.
     */
    public function test_an_outlet_without_the_inventory_module_can_still_restock(): void
    {
        $this->shop()->forceFill(['modules' => ['retail', 'pos']])->save();

        $cake = $this->item();

        $user = $this->staff(['inventory.stock.restock']);

        $this->actingAs($user)
            ->get(route('admin.stock.restock.form', $cake))
            ->assertOk();

        $this->actingAs($user)
            ->postJson(route('admin.stock.restock', $cake), [
                'warehouse_id' => $this->warehouse()->id,
                'quantity' => 8,
            ])
            ->assertOk();

        $this->assertSame(8.0, $this->onHand($cake));

        // ...and gaining that did not hand over the rest of Inventory.
        $this->actingAs($user)->get(route('admin.stock.index'))->assertForbidden();
    }

    /* ------------------------------------------------------------- rights */

    public function test_being_able_to_view_stock_does_not_grant_restocking(): void
    {
        $cake = $this->item();

        $this->actingAs($this->staff(['inventory.stock.view'], 'looker@example.test'))
            ->postJson(route('admin.stock.restock', $cake), [
                'warehouse_id' => $this->warehouse()->id,
                'quantity' => 3,
            ])
            ->assertForbidden();

        $this->assertSame(0.0, $this->onHand($cake));
    }

    public function test_the_restock_right_is_declared_and_given_to_managers_but_not_cashiers(): void
    {
        $roles = (new \Database\Seeders\RolePermissionSeeder)->defaultRolesFor('inventory.stock.restock');

        $this->assertContains('Manager', $roles);
        $this->assertNotContains('Cashier', $roles);
        $this->assertNotContains('Kitchen Staff', $roles);
    }
}
