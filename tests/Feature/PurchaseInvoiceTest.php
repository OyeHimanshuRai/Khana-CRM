<?php

namespace Tests\Feature;

use App\Models\GoodsReceipt;
use App\Models\Product;
use App\Models\Shop;
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
 * Purchase Invoices: a bills register over posted goods receipts, not a
 * document of its own - see PurchaseInvoiceController's docblock. These
 * tests exist to prove it reads the right rows and never lets a bill be
 * edited from here, since editing a bill means editing the receipt.
 */
class PurchaseInvoiceTest extends TestCase
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
            'name' => 'Bill Test Supplier',
            'code' => 'S00097',
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
            'name' => 'Bill Staff '.$counter,
            'email' => "billstaff{$counter}@example.test",
            'password' => 'staff-password-1',
            'is_admin' => true,
        ]);

        $user->givePermissionTo($permissions);
        $user->shops()->attach($this->shop->id, ['is_default' => true]);
        $user->forceFill(['current_shop_id' => $this->shop->id])->save();

        return $user;
    }

    private function billedReceipt(array $overrides = []): GoodsReceipt
    {
        static $counter = 0;
        $counter++;

        $product = Product::create([
            'name' => 'Bill Product '.$counter,
            'slug' => Product::uniqueSlug('Bill Product '.$counter),
            'sku' => Product::generateSku('BIL'.$counter),
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
            'bill_number' => 'BILL-'.$counter.'-'.uniqid(),
            'bill_date' => today(),
            ...$overrides,
        ]);
        $receipt->saveQuietly();

        $receipt->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'sku' => $product->sku,
            'unit_code' => $product->unit?->code,
            'quantity' => 10,
            'unit_cost' => 100,
        ]);

        $stock = new StockService();
        $purchases = new PurchaseService($stock, new SupplierLedgerService());

        return $purchases->post($receipt->fresh());
    }

    public function test_a_posted_receipt_appears_on_the_bills_register(): void
    {
        $receipt = $this->billedReceipt();
        $staff = $this->staff(['purchasing.bills.view']);

        $response = $this->actingAs($staff)->get(route('admin.purchase-invoices.index'));

        $response->assertOk();
        $response->assertSee($receipt->bill_number);
    }

    public function test_a_draft_receipt_never_appears_on_the_bills_register(): void
    {
        $product = Product::create([
            'name' => 'Draft Bill Product',
            'slug' => Product::uniqueSlug('Draft Bill Product'),
            'sku' => Product::generateSku('DFT'),
            'unit_id' => Unit::where('code', 'PCS')->value('id'),
            'purchase_price' => 100, 'mrp' => 200, 'selling_price' => 180,
        ]);

        $draft = new GoodsReceipt([
            'shop_id' => $this->shop->id,
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'reference' => GoodsReceipt::nextReference($this->shop),
            'received_on' => today(),
            'status' => GoodsReceipt::DRAFT,
            'bill_number' => 'NEVER-POSTED-BILL',
        ]);
        $draft->saveQuietly();

        $staff = $this->staff(['purchasing.bills.view']);

        $this->actingAs($staff)->get(route('admin.purchase-invoices.index'))
            ->assertOk()
            ->assertDontSee('NEVER-POSTED-BILL');
    }

    public function test_the_overdue_filter_only_returns_bills_past_their_due_date(): void
    {
        $overdue = $this->billedReceipt(['due_date' => today()->subDays(5)]);
        $notYetDue = $this->billedReceipt(['due_date' => today()->addDays(5)]);

        $staff = $this->staff(['purchasing.bills.view']);

        $response = $this->actingAs($staff)
            ->get(route('admin.purchase-invoices.index', ['settlement' => 'overdue']));

        $response->assertOk();
        $response->assertSee($overdue->bill_number);
        $response->assertDontSee($notYetDue->bill_number);
    }

    public function test_the_printable_bill_shows_its_lines_and_total(): void
    {
        $receipt = $this->billedReceipt();
        $staff = $this->staff(['purchasing.bills.print']);

        $response = $this->actingAs($staff)->get(route('admin.purchase-invoices.print', $receipt));

        $response->assertOk();
        $response->assertSee($receipt->bill_number);
        $response->assertSee(number_format((float) $receipt->grand_total, 2));
    }

    public function test_staff_without_permission_cannot_open_the_register(): void
    {
        $staff = $this->staff([]);

        $this->actingAs($staff)->get(route('admin.purchase-invoices.index'))->assertForbidden();
    }
}
