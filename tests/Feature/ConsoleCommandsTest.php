<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Floor;
use App\Models\Invoice;
use App\Models\PaymentReminder;
use App\Models\Product;
use App\Models\RestaurantTable;
use App\Models\Shop;
use App\Models\StockMovement;
use App\Models\TableSession;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\InvoiceService;
use App\Services\StockService;
use App\Support\CurrentShop;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The scheduled and maintenance commands that had no test of their own.
 * (The others - alerts, subscriptions, backups, e-mail pruning, campaigns,
 * the security audit - are covered in their feature's own test.)
 */
class ConsoleCommandsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');
        $this->seed();
        CurrentShop::forget();
        CurrentTenant::forget();
    }

    protected function tearDown(): void
    {
        CurrentShop::forget();
        CurrentTenant::forget();

        parent::tearDown();
    }

    /* ------------------------------------------------------ permissions:sync */

    public function test_sync_recreates_a_declared_permission_that_went_missing(): void
    {
        Permission::query()->where('name', 'inventory.products.view')->delete();
        $this->assertFalse(Permission::query()->where('name', 'inventory.products.view')->exists());

        $this->artisan('permissions:sync')->assertExitCode(0);

        $this->assertTrue(Permission::query()->where('name', 'inventory.products.view')->exists());
    }

    public function test_a_new_permission_goes_to_the_roles_that_hold_it_by_default(): void
    {
        Permission::query()->where('name', 'messages.chat.view')->delete();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->artisan('permissions:sync')->expectsOutputToContain('Granted')->assertExitCode(0);

        $this->assertTrue(\Spatie\Permission\Models\Role::findByName('Tenant Owner')->hasPermissionTo('messages.chat.view'));
        $this->assertTrue(\Spatie\Permission\Models\Role::findByName('Manager')->hasPermissionTo('messages.chat.view'));
        // Roles that do not hold it by default still do not.
        $this->assertFalse(\Spatie\Permission\Models\Role::findByName('Cashier')->hasPermissionTo('messages.chat.view'));
    }

    public function test_syncing_never_undoes_a_change_somebody_made_in_the_role_editor(): void
    {
        $manager = \Spatie\Permission\Models\Role::findByName('Manager');
        $manager->revokePermissionTo('inventory.products.view');
        Permission::query()->where('name', 'messages.chat.create')->delete();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->artisan('permissions:sync')->assertExitCode(0);

        $this->assertFalse($manager->fresh()->hasPermissionTo('inventory.products.view'), 'the owner removed it on purpose');
        $this->assertTrue($manager->fresh()->hasPermissionTo('messages.chat.create'));
    }

    public function test_grant_hands_an_existing_permission_to_its_default_roles(): void
    {
        $owner = \Spatie\Permission\Models\Role::findByName('Tenant Owner');
        $owner->revokePermissionTo('messages.chat.view');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertFalse($owner->fresh()->hasPermissionTo('messages.chat.view'));

        $this->artisan('permissions:sync', ['--grant' => ['messages.*']])->assertExitCode(0);

        $this->assertTrue($owner->fresh()->hasPermissionTo('messages.chat.view'));
    }

    public function test_sync_reports_an_orphan_but_only_removes_it_when_told_to(): void
    {
        Permission::create(['name' => 'legacy.module.view', 'guard_name' => 'web']);

        $this->artisan('permissions:sync')->expectsOutputToContain('legacy.module.view')->assertExitCode(0);
        $this->assertTrue(Permission::query()->where('name', 'legacy.module.view')->exists(), 'reporting is not removing');

        $this->artisan('permissions:sync', ['--prune' => true])->assertExitCode(0);
        $this->assertFalse(Permission::query()->where('name', 'legacy.module.view')->exists());
    }

    /* ---------------------------------------------------------- push:keys */

    public function test_push_keys_prints_a_key_pair_and_writes_nothing(): void
    {
        // Web-push keys are elliptic-curve keys. On a Windows XAMPP command line OpenSSL
        // cannot make them unless OPENSSL_CONF points at its openssl.cnf; that is the
        // machine's setup, not the command's, so say so instead of failing.
        if (@openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]) === false) {
            $this->markTestSkipped('OpenSSL cannot create EC keys in this shell (set OPENSSL_CONF to openssl.cnf).');
        }

        $envBefore = is_file(base_path('.env')) ? md5_file(base_path('.env')) : null;

        $this->artisan('push:keys')
            ->expectsOutputToContain('PUSH_PUBLIC_KEY=')
            ->expectsOutputToContain('PUSH_PRIVATE_KEY=')
            ->assertExitCode(0);

        $this->assertSame($envBefore, is_file(base_path('.env')) ? md5_file(base_path('.env')) : null, '.env must not be touched');
    }

    public function test_push_keys_says_what_to_do_when_openssl_cannot_make_the_key(): void
    {
        if (@openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]) !== false || getenv('OPENSSL_CONF')) {
            $this->markTestSkipped('OpenSSL works in this shell; there is no failure to explain.');
        }

        $this->artisan('push:keys')->expectsOutputToContain('OPENSSL_CONF')->assertExitCode(1);
    }

    /* ---------------------------------------------------------- dine:tidy */

    private function shop(): Shop
    {
        return Shop::query()->orderBy('id')->firstOrFail();
    }

    private function table(): RestaurantTable
    {
        $shop = $this->shop();
        $floor = Floor::create(['shop_id' => $shop->id, 'name' => 'Main', 'code' => 'MN', 'is_active' => true]);

        return RestaurantTable::create([
            'shop_id' => $shop->id, 'floor_id' => $floor->id, 'name' => '1', 'code' => 'MN-1', 'capacity' => 4,
            'status' => RestaurantTable::AVAILABLE, 'is_active' => true,
        ]);
    }

    public function test_tidy_says_there_is_nothing_to_do_on_a_clean_install(): void
    {
        $this->artisan('dine:tidy')->expectsOutputToContain('Nothing to tidy')->assertExitCode(0);
    }

    public function test_tidy_reports_a_stuck_sitting_without_touching_it_and_closes_it_on_request(): void
    {
        $table = $this->table();
        $session = new TableSession([
            'shop_id' => $table->shop_id, 'restaurant_table_id' => $table->id, 'token' => Str::random(40),
            'status' => TableSession::OPEN, 'covers' => 2, 'opened_at' => now()->subHours(30), 'last_activity_at' => now()->subHours(30),
        ]);
        $session->save();
        $table->forceFill(['status' => RestaurantTable::OCCUPIED])->save();

        // Report only: nothing changes.
        $this->artisan('dine:tidy')->assertExitCode(0);
        $this->assertSame(TableSession::OPEN, $session->fresh()->status);

        // Acting on it closes the sitting.
        $this->artisan('dine:tidy', ['--sittings' => 24])->assertExitCode(0);
        $this->assertSame(TableSession::CLOSED, $session->fresh()->status);
    }

    public function test_tidy_refuses_an_unknown_shop(): void
    {
        $this->artisan('dine:tidy', ['--shop' => 99999])->expectsOutputToContain('No shop')->assertExitCode(1);
    }

    /* ------------------------------------------------------ reminders:* */

    private function overdueInvoice(): Invoice
    {
        $shop = $this->shop();
        $wh = Warehouse::defaultFor($shop->id);

        $product = Product::create([
            'name' => 'Reminder Item', 'slug' => Product::uniqueSlug('Reminder Item'), 'sku' => Product::generateSku('REM'),
            'unit_id' => Unit::where('code', 'PCS')->value('id'), 'tax_rate_id' => TaxRate::where('rate', 0)->value('id'),
            'purchase_price' => 50, 'mrp' => 100, 'selling_price' => 100, 'tax_inclusive' => true,
        ]);
        app(StockService::class)->receive($product, 20, $wh, null, 50, StockMovement::PURCHASE, null, null, $shop->id);

        $customer = new Customer([
            'name' => 'Late Payer', 'mobile' => '9822200001', 'email' => 'late@example.test', 'type' => 'retail',
            'allow_credit' => true, 'credit_limit' => 50000, 'credit_days' => 15, 'is_active' => true,
        ]);
        $customer->shop_id = $shop->id;
        $customer->code = Customer::nextCode($shop->id);
        $customer->save();

        return app(InvoiceService::class)->create([
            'shop' => $shop, 'warehouse' => $wh, 'customer' => $customer, 'channel' => 'pos', 'is_credit' => true,
            'items' => [['product_id' => $product->id, 'quantity' => 3]], 'payments' => [],
            'invoiced_at' => now()->subDays(30), 'due_date' => now()->subDays(10)->toDateString(),
        ]);
    }

    public function test_reminders_are_scheduled_once_however_often_the_command_runs(): void
    {
        $this->overdueInvoice();

        $this->artisan('reminders:schedule')->assertExitCode(0);
        $first = PaymentReminder::query()->withoutGlobalScopes()->count();
        $this->assertGreaterThan(0, $first, 'an overdue invoice earns a reminder');

        $this->artisan('reminders:schedule')->assertExitCode(0);
        $this->assertSame($first, PaymentReminder::query()->withoutGlobalScopes()->count(), 'running it again must not double up');
    }

    public function test_reminders_stay_quiet_when_switched_off(): void
    {
        $this->overdueInvoice();
        config(['reminders.enabled' => false]);

        $this->artisan('reminders:schedule')->expectsOutputToContain('switched off')->assertExitCode(0);
        $this->artisan('reminders:dispatch')->expectsOutputToContain('switched off')->assertExitCode(0);
        $this->assertSame(0, PaymentReminder::query()->withoutGlobalScopes()->count());
    }

    public function test_dispatch_skips_a_reminder_whose_invoice_has_since_been_paid(): void
    {
        $invoice = $this->overdueInvoice();
        $this->artisan('reminders:schedule')->assertExitCode(0);

        // The customer pays between scheduling and sending.
        $invoice->forceFill(['due_total' => 0, 'status' => Invoice::PAID])->save();
        $invoice->customer?->forceFill(['balance' => 0])->save();

        $this->artisan('reminders:dispatch')->assertExitCode(0);

        $this->assertSame(0, PaymentReminder::query()->withoutGlobalScopes()->where('status', 'sent')->count(), 'nobody who has paid is chased');
    }

    public function test_an_unknown_shop_is_refused_by_the_scheduler(): void
    {
        $this->artisan('reminders:schedule', ['--shop' => 99999])->expectsOutputToContain('No shop')->assertExitCode(1);
    }
}
