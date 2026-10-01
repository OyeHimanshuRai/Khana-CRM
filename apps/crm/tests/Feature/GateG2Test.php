<?php

namespace Tests\Feature;

use App\Models\Floor;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\RestaurantTable;
use App\Models\Shop;
use App\Models\TableSession;
use App\Models\User;
use App\Services\TableQrService;
use App\Support\CurrentShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Tests\Concerns\TalksToErp;
use Tests\TestCase;

/**
 * Gate G2 (architecture doc): "QR to order to kitchen to bill to payment
 * works with no stock code present. Ported tests pass; every event matches
 * its schema."
 *
 * One evening at one table, the way the people in it do it - a phone scans
 * the sticker and orders, the cook bumps the ticket on the kitchen screen,
 * the cashier settles the table - all over HTTP, then the outbox is read:
 * every event the evening produced must honour its contract.
 *
 * "No stock code present" is held structurally below: the tables, classes
 * and screens that were stock, recipes, purchasing and Accounts' jobs are
 * gone, not just unused. "Ported tests pass" is the rest of the suite.
 */
class GateG2Test extends TestCase
{
    use RefreshDatabase, TalksToErp;

    private Shop $shop;

    /** @var array{tenant: string, outlet: string} */
    private array $ids;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');
        CurrentShop::forget();

        $this->shop = Shop::query()->orderBy('id')->firstOrFail();
        $this->ids = $this->linkToAccounts($this->shop);
    }

    protected function tearDown(): void
    {
        CurrentShop::forget();

        parent::tearDown();
    }

    /* ---------------------------------------------------------- the people */

    /** @param  list<string>  $permissions */
    private function staff(string $name, array $permissions): User
    {
        $user = User::create([
            'tenant_id' => $this->shop->tenant_id,
            'name' => $name,
            'email' => strtolower($name).'@g2.example.test',
            'password' => 'g2-password-1',
            'is_admin' => true,
        ]);

        $user->givePermissionTo(['dashboard.overview.view', ...$permissions]);
        $user->shops()->syncWithoutDetaching([$this->shop->id => ['is_default' => true]]);
        $user->forceFill(['current_shop_id' => $this->shop->id, 'all_shops_view' => false])->save();

        return $user->fresh();
    }

    private function table(): RestaurantTable
    {
        $floor = Floor::query()->firstOrCreate(
            ['shop_id' => $this->shop->id, 'code' => 'GF'],
            ['name' => 'Ground Floor', 'is_active' => true],
        );

        $table = new RestaurantTable([
            'shop_id' => $this->shop->id,
            'floor_id' => $floor->id,
            'name' => '9',
            'code' => 'GF-09',
            'capacity' => 4,
            'status' => RestaurantTable::AVAILABLE,
            'is_active' => true,
        ]);
        $table->save();

        app(TableQrService::class)->issue($table);

        return $table->fresh(['activeQr']);
    }

    private function dish(string $name, float $price): Product
    {
        $product = new Product([
            'name' => $name,
            'slug' => Product::uniqueSlug($name),
            'sku' => Product::generateSku($name),
            'selling_price' => $price,
            'tax_inclusive' => true,
            'is_active' => true,
        ]);
        $product->save();

        return $product;
    }

    /* ------------------------------------------------------- the evening */

    public function test_qr_to_order_to_kitchen_to_bill_to_payment(): void
    {
        $table = $this->table();
        $dal = $this->dish('Dal Makhani', 280);
        $naan = $this->dish('Butter Naan', 60);

        $mark = $this->outboxMark();

        // The phone: scan the sticker, read the menu, order.
        $this->get('/t/'.$table->activeQr->token)->assertRedirect(route('table.show'));
        $this->get('/t')->assertOk()->assertSee('Dal Makhani')->assertSee('Butter Naan');

        $this->post('/t/cart', ['product_id' => $dal->id, 'quantity' => 1])->assertSessionHasNoErrors();
        $this->post('/t/cart', ['product_id' => $naan->id, 'quantity' => 3])->assertSessionHasNoErrors();
        $this->post('/t/order', ['guest_name' => 'Asha'])->assertRedirect(route('table.orders'));

        $session = TableSession::allShops()->live()->firstOrFail();
        $order = Order::allShops()->where('table_session_id', $session->id)->firstOrFail();

        $this->assertSame(Order::DINE_IN, $order->order_type);
        $this->assertSame(460.0, (float) $order->grand_total);

        // The kitchen screen: the ticket is there, and the cook bumps it.
        $cook = $this->staff('Cook', ['kitchen.tickets.view', 'kitchen.tickets.advance']);

        $this->actingAs($cook)->get('/admin/kitchen')->assertOk();

        $this->actingAs($cook)
            ->putJson('/admin/kitchen/tickets/'.$order->id.'/bump', ['to' => Order::READY])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(Order::READY, $order->fresh()->status);

        // The till: the cashier sees the table's bill and takes the money.
        $cashier = $this->staff('Cashier', ['pos.tables.view', 'pos.tables.settle', 'pos.tables.print']);

        $this->actingAs($cashier)
            ->get('/admin/table-bills/'.$session->id)
            ->assertOk()
            ->assertSee('Dal Makhani')
            ->assertSee('460.00');

        $this->actingAs($cashier)
            ->postJson('/admin/table-bills/'.$session->id.'/settle', [
                'payments' => [['method' => 'upi', 'amount' => 460, 'transaction_ref' => 'UPI-G2-1']],
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.remaining', 0);

        // Paid in full, the sitting closed, the table off to be cleared.
        $invoice = Invoice::allShops()->where('table_session_id', $session->id)->sole();

        $this->assertSame(460.0, (float) $invoice->grand_total);
        $this->assertSame(460.0, (float) $invoice->paid_total);
        $this->assertSame(
            1,
            Payment::query()->withoutGlobalScopes()
                ->where('reference_type', $invoice->getMorphClass())
                ->where('reference_id', $invoice->id)
                ->where('method', Payment::UPI)
                ->where('transaction_ref', 'UPI-G2-1')
                ->count(),
        );
        $this->assertSame(TableSession::CLOSED, $session->fresh()->status);
        $this->assertSame(RestaurantTable::CLEANING, $table->fresh()->status);

        // ERP heard about all of it, and every word honoured its contract.
        $events = $this->outboxSince($mark);
        $types = $events->pluck('type')->unique()->values()->all();

        $this->assertContains('crm.kitchen.line_advanced', $types);
        $this->assertContains('crm.sale.recorded', $types);

        foreach ($events as $event) {
            $this->assertStringStartsWith('crm.', $event->type);
            $this->assertSame($this->ids['tenant'], $event->tenant_id);
            $this->assertSame($this->ids['outlet'], $event->outlet_id);
            $this->assertHonoursContract($event);
        }

        $sale = $events->firstWhere('type', 'crm.sale.recorded')->data;

        $this->assertSame($invoice->fresh()->ulid, $sale['sale_id']);
        $this->assertSame('dine_in', $sale['channel']);
        $this->assertSame(46000, $sale['totals']['grand_paise']);
        $this->assertCount(2, $sale['lines']);

        foreach ($sale['lines'] as $line) {
            $this->assertCount(1, $line['order_line_ids'], 'ERP must know the kitchen already cooked this line');
        }
    }

    /* ------------------------------------------- with no stock code present */

    /** The rows are ERP's (stock, recipes, purchasing) or Accounts' (plans). */
    public function test_none_of_the_tables_crm_handed_over_are_left(): void
    {
        $handedOver = [
            // To ERP.
            'product_stocks', 'stock_movements', 'stock_adjustments', 'stock_adjustment_items',
            'stock_transfers', 'stock_transfer_items', 'stock_wastages', 'batches', 'warehouses',
            'recipes', 'recipe_items',
            'purchase_orders', 'purchase_order_items', 'purchase_invoices', 'purchase_invoice_items',
            'purchase_returns', 'purchase_return_items', 'goods_receipts', 'suppliers', 'expenses',
            // To Accounts.
            'plans', 'subscriptions', 'subscription_payments',
        ];

        foreach ($handedOver as $table) {
            $this->assertFalse(Schema::hasTable($table), "CRM still has the {$table} table");
        }

        foreach (['min_stock', 'reorder_level', 'track_batches', 'is_ingredient'] as $column) {
            $this->assertFalse(Schema::hasColumn('products', $column), "products.{$column} is stock's, not the menu's");
        }

        foreach (['invoices', 'sales_returns', 'orders'] as $sales) {
            $this->assertFalse(Schema::hasColumn($sales, 'warehouse_id'), "a sale in {$sales} still names a warehouse");
        }
    }

    public function test_none_of_the_code_is_left(): void
    {
        $gone = [
            'App\Services\StockService', 'App\Services\RecipeService', 'App\Services\WastageService',
            'App\Services\StockAdjustmentService', 'App\Services\StockTransferService',
            'App\Services\SubscriptionService', 'App\Services\SignupService',
            'App\Models\ProductStock', 'App\Models\StockMovement', 'App\Models\Recipe',
            'App\Models\Warehouse', 'App\Models\Batch', 'App\Models\Supplier',
            'App\Models\PurchaseOrder', 'App\Models\Expense',
            'App\Models\Plan', 'App\Models\Subscription',
            'App\Http\Controllers\Admin\StockController', 'App\Http\Controllers\Admin\RecipeController',
            'App\Http\Controllers\Admin\WarehouseController', 'App\Http\Controllers\Admin\SupplierController',
            'App\Http\Controllers\Admin\PurchaseOrderController', 'App\Http\Controllers\Admin\UserController',
            'App\Http\Controllers\Admin\TenantController', 'App\Http\Controllers\SignupController',
        ];

        // By file rather than class_exists(): a stale optimised classmap
        // would make that include a file that is not there.
        foreach ($gone as $class) {
            $file = base_path(str_replace(['App\\', '\\'], ['app/', '/'], $class).'.php');

            $this->assertFileDoesNotExist($file, "{$class} is still in CRM");
        }

        $prefixes = [
            'admin.stock', 'admin.recipes', 'admin.wastage', 'admin.batches', 'admin.warehouses',
            'admin.units', 'admin.suppliers', 'admin.purchase', 'admin.goods-receipts', 'admin.expenses',
            'admin.plans', 'admin.subscriptions', 'admin.billing', 'admin.tenants', 'admin.users',
        ];

        // Which business platform staff are looking at here is CRM's own
        // question; running the businesses is Accounts'.
        $stays = ['admin.tenants.switch'];

        foreach (Route::getRoutes()->getRoutesByName() as $name => $route) {
            if (in_array($name, $stays, true)) {
                continue;
            }

            foreach ($prefixes as $prefix) {
                $this->assertFalse(
                    str_starts_with($name, $prefix.'.') || $name === $prefix,
                    "route {$name} ({$route->uri()}) belongs to another service",
                );
            }
        }
    }

    /** And the screens that are left are reached as hubs and tabs. */
    public function test_the_sidebar_offers_no_screen_that_was_handed_over(): void
    {
        $html = $this->actingAs(User::where('is_admin', true)->firstOrFail())
            ->get('/admin/dashboard')
            ->assertOk()
            ->getContent();

        foreach (['Stock', 'Recipes', 'Wastage', 'Warehouses', 'Suppliers', 'Purchase', 'Subscription', 'Plans'] as $label) {
            $this->assertStringNotContainsString('data-label="'.$label, $html);
        }
    }
}
