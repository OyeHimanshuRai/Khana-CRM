<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\RestaurantTable;
use App\Models\Shop;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\User;
use App\Services\LedgerService;
use App\Services\TenantProvisioningService;
use App\Models\Plan;
use App\Support\CurrentShop;
use App\Support\CurrentTenant;
use Database\Seeders\DemoTenantsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The demo seeder fills three plans' worth of companies through the real
 * services, so the figures it leaves behind have to satisfy the same
 * invariants the application promises everywhere else.
 */
class DemoTenantsSeederTest extends TestCase
{
    use RefreshDatabase;

    private array $owners = [];

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');
        $this->seed();
        CurrentShop::forget();
        CurrentTenant::forget();

        foreach (['starter', 'restaurant', 'chain'] as $slug) {
            $email = "owner-{$slug}@example.test";
            app(TenantProvisioningService::class)->register([
                'business_name' => "Test {$slug}", 'name' => "Owner {$slug}", 'email' => $email,
                'mobile' => '9000000000', 'password' => 'Secret123x',
            ], Plan::query()->where('slug', $slug)->firstOrFail(), Plan::MONTHLY);
            $this->owners[$slug] = $email;
        }

        CurrentShop::forget();
        CurrentTenant::forget();
    }

    protected function tearDown(): void
    {
        CurrentShop::forget();
        CurrentTenant::forget();

        parent::tearDown();
    }

    private function seedDemo(): DemoTenantsSeeder
    {
        $seeder = (new DemoTenantsSeeder)->useOwners($this->owners);
        $seeder->run();

        CurrentShop::forget();
        CurrentTenant::forget();

        return $seeder;
    }

    public function test_each_plan_gets_a_working_company(): void
    {
        $seeder = $this->seedDemo();

        foreach (['starter' => 1, 'restaurant' => 1, 'chain' => 3] as $slug => $shopCount) {
            $owner = User::query()->withoutGlobalScopes()->where('email', $this->owners[$slug])->first();
            $shops = Shop::query()->withoutGlobalScopes()->where('tenant_id', $owner->tenant_id)->get();

            $this->assertCount($shopCount, $shops, $slug);
            $this->assertGreaterThan(15, Product::query()->withoutGlobalScopes()->where('tenant_id', $owner->tenant_id)->count(), $slug);

            foreach ($shops as $shop) {
                $this->assertGreaterThan(10, Invoice::query()->withoutGlobalScopes()->where("shop_id", $shop->id)->count(), "{$slug} {$shop->name} invoices; skipped: ".json_encode($seeder->skipped));
                $this->assertGreaterThan(5, Customer::query()->withoutGlobalScopes()->where('shop_id', $shop->id)->count());
                $this->assertGreaterThan(0, ProductStock::query()->withoutGlobalScopes()->where('shop_id', $shop->id)->count());
            }
        }

        $this->assertNotEmpty($seeder->accounts);
    }

    public function test_dining_plans_have_tables_with_qr_codes_and_starter_has_none(): void
    {
        $this->seedDemo();

        $tables = fn (string $slug) => RestaurantTable::query()->withoutGlobalScopes()
            ->whereIn('shop_id', Shop::query()->withoutGlobalScopes()->where('tenant_id',
                User::query()->withoutGlobalScopes()->where('email', $this->owners[$slug])->value('tenant_id'))->pluck('id'));

        $this->assertSame(0, $tables('starter')->count());
        $this->assertSame(14, $tables('restaurant')->count());
        $this->assertSame(6 + 5 + 5, $tables('chain')->count());
        $this->assertSame(0, $tables('restaurant')->whereDoesntHave('activeQr')->count());
        // Somebody is seated, and every position is placed on the plan.
        $this->assertGreaterThan(0, $tables('restaurant')->where('status', 'occupied')->count());
        $this->assertSame(0, $tables('restaurant')->where('pos_x', 0)->where('pos_y', 0)->count());
    }

    public function test_stock_balances_equal_the_movement_ledger(): void
    {
        $this->seedDemo();

        $stocks = ProductStock::query()->withoutGlobalScopes()->get();
        $this->assertNotEmpty($stocks);

        foreach ($stocks as $stock) {
            $sum = (float) StockMovement::query()->withoutGlobalScopes()
                ->where('shop_id', $stock->shop_id)->where('warehouse_id', $stock->warehouse_id)
                ->where('product_id', $stock->product_id)->where('batch_id', $stock->batch_id)->sum('quantity');

            $this->assertEqualsWithDelta($sum, (float) $stock->quantity, 0.0001, "stock #{$stock->id}");
            $this->assertGreaterThanOrEqual(0, (float) $stock->quantity);
        }
    }

    public function test_customer_balances_match_their_ledgers(): void
    {
        $this->seedDemo();

        $ledger = app(LedgerService::class);
        $withDues = 0;

        foreach (Customer::query()->withoutGlobalScopes()->get() as $customer) {
            $report = $ledger->reconcile($customer);
            $this->assertEqualsWithDelta((float) $report['stored'], (float) $report['actual'], 0.01, $customer->name);
            $withDues += (float) $customer->balance > 0 ? 1 : 0;
        }

        $this->assertGreaterThan(0, $withDues, 'some customers owe money, so the dues screens have something to show');
    }

    public function test_every_invoice_balances(): void
    {
        $this->seedDemo();

        foreach (Invoice::query()->withoutGlobalScopes()->where('status', '!=', 'cancelled')->get() as $invoice) {
            $paid = (float) DB::table('payments')->where('reference_type', Invoice::class)->where('reference_id', $invoice->id)
                ->where('status', 'cleared')->where('direction', 'in')->sum('amount');

            $this->assertEqualsWithDelta((float) $invoice->grand_total, $paid + (float) $invoice->due_total, 1.01, "invoice {$invoice->number}");
        }
    }

    public function test_running_it_again_changes_nothing(): void
    {
        $this->seedDemo();
        $products = Product::query()->withoutGlobalScopes()->count();
        $invoices = Invoice::query()->withoutGlobalScopes()->count();

        $this->seedDemo();

        $this->assertSame($products, Product::query()->withoutGlobalScopes()->count());
        $this->assertSame($invoices, Invoice::query()->withoutGlobalScopes()->count());
    }

    public function test_it_refuses_an_owner_who_is_on_a_different_plan(): void
    {
        $this->expectException(\RuntimeException::class);

        (new DemoTenantsSeeder)->useOwners(['chain' => $this->owners['starter']])->run();
    }

    public function test_every_branch_of_a_chain_has_its_own_recipes(): void
    {
        $this->seedDemo();

        $owner = User::query()->withoutGlobalScopes()->where('email', $this->owners['chain'])->first();
        $shops = Shop::query()->withoutGlobalScopes()->where('tenant_id', $owner->tenant_id)->pluck('id');

        $this->assertCount(3, $shops);
        $counts = $shops->map(fn ($id) => DB::table('recipe_items')->where('shop_id', $id)->count());
        $this->assertGreaterThan(0, $counts->min(), 'no branch is left without recipes');
        $this->assertCount(1, $counts->unique(), 'all branches cook the same menu, so they carry the same lines');
    }

    public function test_a_second_starter_company_gets_its_own_business(): void
    {
        app(TenantProvisioningService::class)->register([
            'business_name' => 'Test starter two', 'name' => 'Owner two', 'email' => 'owner-starter2@example.test',
            'mobile' => '9000000001', 'password' => 'Secret123x',
        ], Plan::query()->where('slug', 'starter')->firstOrFail(), Plan::MONTHLY);
        CurrentShop::forget();
        CurrentTenant::forget();

        $this->seedDemo();
        (new DemoTenantsSeeder)->useOwners(['starter#2' => 'owner-starter2@example.test'])->run();

        $owner = User::query()->withoutGlobalScopes()->where('email', 'owner-starter2@example.test')->first();
        $tenant = Tenant::query()->findOrFail($owner->tenant_id);

        $this->assertSame('Bean & Bite Cafe', $tenant->name);
        $this->assertGreaterThan(15, Product::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
        // Two Starter companies can sit side by side without clashing on codes or slugs.
        $this->assertSame(2, Tenant::query()->whereIn('name', ['Chai Point Corner', 'Bean & Bite Cafe'])->count());
    }
}
