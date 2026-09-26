<?php

namespace Tests\Feature;

use App\Models\GoodsReceipt;
use App\Models\Product;
use App\Models\PurchaseReturn;
use App\Models\Shop;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\PurchaseService;
use App\Services\StockService;
use App\Services\SupplierLedgerService;
use App\Support\CurrentShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The purchase-returns screens over HTTP: the create-form's line-quantity
 * guard, and that the store/approve/reject actions are wired to the
 * `purchasing.returns.*` permissions the SRS placeholders already declared.
 */
class PurchaseReturnControllerTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private Warehouse $warehouse;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');

        $this->seed();
        CurrentShop::forget();

        $this->shop = Shop::firstOrFail();
        $this->warehouse = Warehouse::allShops()->where('shop_id', $this->shop->id)->firstOrFail();

        $supplier = new Supplier([
            'shop_id' => $this->shop->id,
            'name' => 'Controller Test Supplier',
            'code' => 'S00098',
            'is_active' => true,
        ]);
        $supplier->saveQuietly();
        $this->supplier = $supplier;
    }

    protected function tearDown(): void
    {
        CurrentShop::forget();

        parent::tearDown();
    }

    private function staff(array $permissions): User
    {
        static $counter = 0;
        $counter++;

        $user = User::create([
            'name' => 'Return Staff '.$counter,
            'email' => "returnstaff{$counter}@example.test",
            'password' => 'staff-password-1',
            'is_admin' => true,
        ]);

        $user->givePermissionTo($permissions);
        $user->shops()->attach($this->shop->id, ['is_default' => true]);
        $user->forceFill(['current_shop_id' => $this->shop->id])->save();

        return $user;
    }

    private function receipt(float $quantity = 20, float $cost = 100): GoodsReceipt
    {
        $product = Product::create([
            'name' => 'Controller Return Product',
            'slug' => Product::uniqueSlug('Controller Return Product '.uniqid()),
            'sku' => Product::generateSku('CRP'),
            'unit_id' => Unit::where('code', 'PCS')->value('id'),
            'purchase_price' => 100,
            'mrp' => 200,
            'selling_price' => 180,
        ]);

        $receipt = new GoodsReceipt([
            'shop_id' => $this->shop->id,
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'reference' => GoodsReceipt::nextReference($this->shop),
            'received_on' => today(),
            'status' => GoodsReceipt::DRAFT,
            'bill_number' => 'BILL-'.uniqid(),
            'bill_date' => today(),
        ]);
        $receipt->saveQuietly();

        $receipt->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'sku' => $product->sku,
            'unit_code' => $product->unit?->code,
            'quantity' => $quantity,
            'unit_cost' => $cost,
        ]);

        $stock = new StockService();
        $purchases = new PurchaseService($stock, new SupplierLedgerService());

        return $purchases->post($receipt->fresh());
    }

    public function test_the_list_and_the_search_for_a_receipt_render(): void
    {
        $staff = $this->staff(['purchasing.returns.view', 'purchasing.returns.create']);

        $this->actingAs($staff)->get(route('admin.purchase-returns.index'))->assertOk();
        $this->actingAs($staff)->get(route('admin.purchase-returns.create'))->assertOk();
    }

    public function test_the_form_prefilled_from_a_receipt_renders_its_lines(): void
    {
        $receipt = $this->receipt();
        $staff = $this->staff(['purchasing.returns.create']);

        $response = $this->actingAs($staff)
            ->get(route('admin.purchase-returns.create', ['receipt' => $receipt->id]));

        $response->assertOk();
        $response->assertSee($receipt->reference);
        $response->assertSee('Controller Return Product');
    }

    public function test_a_staff_member_can_raise_and_immediately_accept_a_return(): void
    {
        $receipt = $this->receipt(20, 100);
        $staff = $this->staff(['purchasing.returns.create', 'purchasing.returns.approve']);
        $item = $receipt->items()->sole();

        $response = $this->actingAs($staff)->postJson(route('admin.purchase-returns.store'), [
            'goods_receipt_id' => $receipt->id,
            'warehouse_id' => $this->warehouse->id,
            'returned_on' => today()->toDateString(),
            'reason_code' => 'excess',
            'settlement' => PurchaseReturn::CREDIT,
            'intent' => 'approve',
            'items' => [
                ['goods_receipt_item_id' => $item->id, 'quantity' => 5],
            ],
        ]);

        $response->assertOk()->assertJsonPath('success', true);

        $return = PurchaseReturn::allShops()->sole();
        $this->assertSame(PurchaseReturn::APPROVED, $return->status);

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $item->product_id,
            'type' => StockMovement::PURCHASE_RETURN,
        ]);
    }

    public function test_returning_more_than_is_left_on_the_line_is_refused(): void
    {
        $receipt = $this->receipt(5, 100);
        $staff = $this->staff(['purchasing.returns.create']);
        $item = $receipt->items()->sole();

        $response = $this->actingAs($staff)->postJson(route('admin.purchase-returns.store'), [
            'goods_receipt_id' => $receipt->id,
            'warehouse_id' => $this->warehouse->id,
            'returned_on' => today()->toDateString(),
            'reason_code' => 'excess',
            'settlement' => PurchaseReturn::CREDIT,
            'items' => [
                ['goods_receipt_item_id' => $item->id, 'quantity' => 500],
            ],
        ]);

        $response->assertStatus(422)->assertJsonPath('success', false);
        $this->assertSame(0, PurchaseReturn::allShops()->count());
    }

    public function test_a_pending_return_can_be_rejected(): void
    {
        $receipt = $this->receipt();
        $staff = $this->staff(['purchasing.returns.create', 'purchasing.returns.reject']);
        $item = $receipt->items()->sole();

        $this->actingAs($staff)->postJson(route('admin.purchase-returns.store'), [
            'goods_receipt_id' => $receipt->id,
            'warehouse_id' => $this->warehouse->id,
            'returned_on' => today()->toDateString(),
            'reason_code' => 'excess',
            'settlement' => PurchaseReturn::CREDIT,
            'items' => [
                ['goods_receipt_item_id' => $item->id, 'quantity' => 2],
            ],
        ]);

        $return = PurchaseReturn::allShops()->sole();

        $response = $this->actingAs($staff)->putJson(route('admin.purchase-returns.reject', $return), [
            'reason' => 'Supplier declined it',
        ]);

        $response->assertOk()->assertJsonPath('success', true);
        $this->assertSame(PurchaseReturn::REJECTED, $return->fresh()->status);
    }

    public function test_the_show_and_reject_screens_render(): void
    {
        $receipt = $this->receipt();
        $staff = $this->staff(['purchasing.returns.create', 'purchasing.returns.reject', 'purchasing.returns.view']);
        $item = $receipt->items()->sole();

        $this->actingAs($staff)->postJson(route('admin.purchase-returns.store'), [
            'goods_receipt_id' => $receipt->id,
            'warehouse_id' => $this->warehouse->id,
            'returned_on' => today()->toDateString(),
            'reason_code' => 'excess',
            'settlement' => PurchaseReturn::CREDIT,
            'items' => [
                ['goods_receipt_item_id' => $item->id, 'quantity' => 3],
            ],
        ]);

        $return = PurchaseReturn::allShops()->sole();

        $this->actingAs($staff)->get(route('admin.purchase-returns.show', $return))
            ->assertOk()
            ->assertSee($return->reference);

        $this->actingAs($staff)->get(route('admin.purchase-returns.reject.form', $return))
            ->assertOk();
    }

    public function test_staff_without_permission_cannot_open_the_screen(): void
    {
        $staff = $this->staff([]);

        $this->actingAs($staff)->get(route('admin.purchase-returns.index'))->assertForbidden();
    }
}
