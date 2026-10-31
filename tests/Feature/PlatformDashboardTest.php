<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Shop;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentShop;
use App\Support\CurrentTenant;
use App\Support\Modules;
use App\Support\PlanAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The Project Owner's screens: the figures must add up across companies, and
 * nobody who belongs to a company may ever reach them.
 */
class PlatformDashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Shop $shopA;

    private Shop $shopB;

    private Tenant $tenantB;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');
        $this->seed();
        $this->resetState();

        $this->owner = User::query()->where('email', 'admin@erp.test')->firstOrFail();
        $this->shopA = Shop::query()->withoutGlobalScopes()->orderBy('id')->firstOrFail();

        $this->tenantB = Tenant::create(['name' => 'Rival Cafe', 'code' => 'RIVAL', 'slug' => 'rival-cafe']);
        $this->shopB = Shop::query()->withoutGlobalScopes()->create([
            'tenant_id' => $this->tenantB->id, 'name' => 'Rival Branch', 'code' => 'RV', 'slug' => 'rival-branch',
        ]);
    }

    protected function tearDown(): void
    {
        $this->resetState();

        parent::tearDown();
    }

    private function resetState(): void
    {
        CurrentShop::forget();
        CurrentTenant::forget();
        PlanAccess::forget();
        Modules::forget();
    }

    private function invoice(Shop $shop, float $total, array $overrides = []): Invoice
    {
        return Invoice::unguarded(fn () => Invoice::query()->withoutGlobalScopes()->create(array_merge([
            'shop_id' => $shop->id, 'number' => 'INV-'.uniqid(), 'channel' => 'pos', 'status' => 'paid',
            'invoiced_at' => now(), 'subtotal' => $total, 'cost_total' => 10, 'grand_total' => $total,
        ], $overrides)));
    }

    private function expense(Shop $shop, float $amount, string $status = Expense::APPROVED): void
    {
        Expense::unguarded(fn () => Expense::query()->withoutGlobalScopes()->create([
            'shop_id' => $shop->id, 'reference' => 'EXP-'.uniqid(), 'spent_on' => now(), 'amount' => $amount,
            'status' => $status, 'title' => 'Rent', 'method' => 'cash',
        ]));
    }

    private function subscription(Tenant $tenant, array $overrides = []): Subscription
    {
        return Subscription::create(array_merge([
            'tenant_id' => $tenant->id, 'plan_id' => Plan::query()->where('code', 'starter')->value('id'),
            'billing_period' => 'monthly', 'price' => 799, 'currency' => 'INR',
            'starts_at' => now()->subMonth(), 'ends_at' => now()->addMonth(), 'grace_days' => 0,
        ], $overrides));
    }

    private function staffOf(Shop $shop, string $role): User
    {
        $user = User::create([
            'tenant_id' => $shop->tenant_id, 'name' => $role, 'email' => uniqid('u').'@example.test',
            'password' => 'Secret-1234', 'is_admin' => true, 'is_active' => true,
            'current_shop_id' => $shop->id,
        ]);
        $user->shops()->attach($shop->id, ['is_default' => true]);
        $user->assignRole($role);

        return $user->fresh();
    }

    public function test_the_project_owner_lands_on_it_after_signing_in(): void
    {
        $this->post(route('admin.login.store'), ['email' => 'admin@erp.test', 'password' => 'password'])
            ->assertRedirect(route('admin.platform.dashboard'));
    }

    public function test_a_restaurant_owner_still_lands_on_their_own_dashboard(): void
    {
        $this->staffOf($this->shopA, 'Tenant Owner')->forceFill(['email' => 'mine@example.test'])->save();

        $this->post(route('login.store'), ['email' => 'mine@example.test', 'password' => 'Secret-1234'])
            ->assertRedirect(route('admin.dashboard'));
    }

    public function test_nobody_in_a_company_can_reach_any_of_it(): void
    {
        foreach (['Tenant Owner', 'Manager', 'Cashier'] as $role) {
            $user = $this->staffOf($this->shopA, $role);
            $user->givePermissionTo(['platform.overview.view', 'platform.finance.view']);

            foreach ([
                route('admin.platform.dashboard'),
                route('admin.platform.shops'),
                route('admin.platform.shops.export'),
                route('admin.platform.shops.show', $this->shopB->id),
                route('admin.platform.finance'),
                route('admin.platform.finance.export'),
            ] as $url) {
                $this->resetState();
                $this->actingAs($user)->get($url)->assertForbidden();
            }
        }
    }

    public function test_no_company_role_is_even_granted_them(): void
    {
        $held = \Spatie\Permission\Models\Role::query()
            ->whereIn('name', ['Tenant Owner', 'Shop Admin', 'Manager', 'Cashier', 'Accountant'])
            ->get()
            ->flatMap(fn ($role) => $role->permissions->pluck('name'))
            ->filter(fn (string $name) => str_starts_with($name, 'platform.'));

        $this->assertSame([], $held->all());
    }

    public function test_a_restaurant_owner_is_not_offered_the_screens(): void
    {
        $this->resetState();
        $this->actingAs($this->staffOf($this->shopA, 'Tenant Owner'))
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertDontSee('Owner Dashboard')
            ->assertDontSee('SaaS Finance');
    }

    public function test_the_project_owner_sees_every_menu_until_they_switch_them_off(): void
    {
        $this->resetState();
        $this->actingAs($this->owner)->get(route('admin.platform.dashboard'))
            ->assertOk()
            ->assertSee('Restaurant menus')
            ->assertSee('aria-checked="true"', false)
            ->assertSee('Floor Plan')
            ->assertSee('Online Store')
            ->assertSee('Menu Items');
    }

    public function test_the_switch_leaves_the_project_owner_their_own_menus(): void
    {
        $this->resetState();
        $this->actingAs($this->owner)->post(route('admin.platform.shop-menus'))->assertRedirect();

        $this->assertFalse($this->owner->fresh()->show_shop_menus);

        $this->actingAs($this->owner->fresh())->get(route('admin.platform.dashboard'))
            ->assertOk()
            ->assertSee('aria-checked="false"', false)
            ->assertSee('Owner Dashboard')
            ->assertSee('Subscriptions')
            ->assertSee('Landing Page')
            ->assertSee('Email Templates')
            ->assertDontSee('Floor Plan')
            ->assertDontSee('Online Store')
            ->assertDontSee('Kitchen Display')
            ->assertDontSee('POS Billing')
            ->assertDontSee('Menu Items')
            ->assertDontSee('Customer Master')
            ->assertDontSee('All reports');
    }

    public function test_switching_back_on_brings_every_menu_back(): void
    {
        $this->resetState();
        $this->owner->forceFill(['show_shop_menus' => false])->save();

        $this->actingAs($this->owner)->post(route('admin.platform.shop-menus'))->assertRedirect();

        $this->assertTrue($this->owner->fresh()->show_shop_menus);
        $this->actingAs($this->owner->fresh())->get(route('admin.platform.dashboard'))->assertSee('Floor Plan');
    }

    public function test_the_switch_hides_links_and_closes_no_screen(): void
    {
        $this->resetState();
        $this->owner->forceFill(['show_shop_menus' => false])->save();

        $this->actingAs($this->owner)->get(route('admin.dashboard'))->assertOk();
    }

    public function test_nobody_in_a_company_gets_the_switch(): void
    {
        $this->resetState();
        $owner = $this->staffOf($this->shopA, 'Tenant Owner');

        $this->actingAs($owner)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertDontSee('Restaurant menus')
            ->assertSee('Floor Plan');

        $this->actingAs($owner)->post(route('admin.platform.shop-menus'))->assertForbidden();
        $this->assertTrue((bool) ($owner->fresh()->show_shop_menus ?? true));
    }

    public function test_the_project_owner_finds_dashboard_under_platform(): void
    {
        $this->resetState();
        $link = 'href="'.route('admin.dashboard').'"';

        $this->actingAs($this->owner)->get(route('admin.platform.dashboard'))
            ->assertOk()
            ->assertSee($link, false)
            ->assertDontSee('<div class="nav-heading">Main</div>', false);

        $this->owner->forceFill(['show_shop_menus' => false])->save();

        $this->actingAs($this->owner)->get(route('admin.platform.dashboard'))
            ->assertOk()
            ->assertSee($link, false)
            ->assertDontSee('Floor Plan');
    }

    public function test_a_restaurant_owner_keeps_dashboard_under_main(): void
    {
        $this->resetState();
        $this->actingAs($this->staffOf($this->shopA, 'Tenant Owner'))
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('<div class="nav-heading">Main</div>', false)
            ->assertDontSee('<div class="nav-heading">Platform</div>', false);
    }

    public function test_the_figures_add_up_across_companies(): void
    {
        $this->invoice($this->shopA, 1000);
        $this->invoice($this->shopB, 500);
        $this->invoice($this->shopB, 9999, ['status' => Invoice::CANCELLED]);
        $this->expense($this->shopA, 100);
        $this->expense($this->shopB, 77, Expense::DRAFT);

        Order::create([
            'shop_id' => $this->shopB->id, 'order_type' => Order::DINE_IN, 'order_number' => 'T-1',
            'status' => Order::PENDING, 'payment_status' => Order::PAYMENT_PENDING,
            'placed_at' => now(), 'subtotal' => 0, 'grand_total' => 0,
        ]);

        $this->resetState();
        $response = $this->actingAs($this->owner)->get(route('admin.platform.dashboard'))->assertOk();

        $totals = $response->viewData('overview')['all_time'];

        $this->assertSame(3, $totals['orders'], 'two counter sales and one table order');
        $this->assertEqualsWithDelta(1500, $totals['sales'], 0.01, 'a cancelled bill is not a sale');
        $this->assertEqualsWithDelta(100, $totals['expenses'], 0.01, 'a draft expense is not spent');
        $this->assertEqualsWithDelta(1380, $totals['profit'], 0.01);
    }

    public function test_subscription_income_never_mixes_with_restaurant_sales(): void
    {
        $this->invoice($this->shopA, 1000);

        $sub = $this->subscription($this->tenantB);

        SubscriptionPayment::unguarded(fn () => $sub->payments()->create([
            'tenant_id' => $this->tenantB->id, 'reference' => 'SUB-1', 'amount' => 1180, 'tax_amount' => 180,
            'currency' => 'INR', 'method' => 'manual', 'paid_at' => now(), 'period_start' => now(), 'period_end' => now()->addMonth(),
        ]));
        SubscriptionPayment::unguarded(fn () => $sub->payments()->create([
            'tenant_id' => $this->tenantB->id, 'reference' => 'SUB-2', 'amount' => -100,
            'currency' => 'INR', 'method' => 'manual', 'paid_at' => now(), 'period_start' => now(), 'period_end' => now(),
        ]));

        $this->resetState();
        $saas = $this->actingAs($this->owner)->get(route('admin.platform.finance'))->assertOk()->viewData('saas');

        $this->assertEqualsWithDelta(900, $saas['income'], 0.01, 'net of GST, refunds taken off');
        $this->assertEqualsWithDelta(180, $saas['gst'], 0.01);
        $this->assertSame(1, $saas['completed']);
    }

    public function test_expiring_expired_and_outstanding_are_told_apart(): void
    {
        $this->subscription($this->tenantB, ['ends_at' => now()->addDays(3)]);

        $tenantC = Tenant::create(['name' => 'Gone', 'code' => 'GONE', 'slug' => 'gone']);
        $this->subscription($tenantC, ['ends_at' => now()->subDays(9)]);

        $this->resetState();
        $page = $this->actingAs($this->owner)->get(route('admin.platform.finance'))->assertOk();

        $this->assertSame(1, $page->viewData('saas')['expiring']->count());
        $this->assertSame(1, $page->viewData('saas')['expired']);
        $this->assertSame([$tenantC->id], $page->viewData('outstanding')->pluck('tenant_id')->all());
    }

    public function test_shops_filter_by_status_search_and_period(): void
    {
        $this->invoice($this->shopB, 500, ['invoiced_at' => now()->subYears(2)]);
        $this->tenantB->forceFill(['is_active' => false])->save();

        $this->resetState();
        $listed = $this->actingAs($this->owner)->get(route('admin.platform.shops', ['status' => 'suspended']))
            ->assertOk()->viewData('shops')->pluck('id')->all();
        $this->assertSame([$this->shopB->id], $listed);

        $this->resetState();
        $this->actingAs($this->owner)->get(route('admin.platform.shops', ['q' => 'Rival Cafe']))
            ->assertOk()->assertSee('Rival Branch');

        $this->resetState();
        $month = $this->actingAs($this->owner)->get(route('admin.platform.shops', ['period' => 'month']))->viewData('figures');
        $this->assertNull($month->get($this->shopB->id));

        $this->resetState();
        $all = $this->actingAs($this->owner)->get(route('admin.platform.shops', ['period' => 'all']))->viewData('figures');
        $this->assertEqualsWithDelta(500, $all->get($this->shopB->id)['sales'], 0.01);
    }

    public function test_a_removed_shop_is_left_out(): void
    {
        $this->shopB->delete();

        $this->resetState();
        $this->actingAs($this->owner)->get(route('admin.platform.shops'))->assertOk()->assertDontSee('Rival Branch');
        $this->resetState();
        $this->actingAs($this->owner)->get(route('admin.platform.shops.show', $this->shopB->id))->assertNotFound();
    }

    public function test_a_shops_page_shows_its_people_figures_and_history(): void
    {
        $this->invoice($this->shopB, 500);
        $staff = $this->staffOf($this->shopB, 'Cashier');

        $this->resetState();
        $this->actingAs($this->owner)->get(route('admin.platform.shops.show', $this->shopB->id))
            ->assertOk()
            ->assertSee('Rival Branch')
            ->assertSee($staff->email)
            ->assertSee('₹500');
    }

    public function test_a_spreadsheet_cannot_be_made_to_run_a_shop_name(): void
    {
        $this->shopB->forceFill(['name' => '=HYPERLINK("http://evil")'])->save();

        $this->resetState();
        $csv = $this->actingAs($this->owner)->get(route('admin.platform.shops.export', ['period' => 'all']))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString("'=HYPERLINK", str_replace('"', '', $csv));
        $this->assertStringContainsString('Rival Cafe', $csv);
    }

    public function test_the_finance_export_lists_net_income(): void
    {
        $sub = $this->subscription($this->tenantB);

        SubscriptionPayment::unguarded(fn () => $sub->payments()->create([
            'tenant_id' => $this->tenantB->id, 'reference' => 'SUB-9', 'amount' => 1180, 'tax_amount' => 180,
            'currency' => 'INR', 'method' => 'manual', 'paid_at' => now(), 'period_start' => now(), 'period_end' => now()->addMonth(),
        ]));

        $this->resetState();
        $csv = $this->actingAs($this->owner)->get(route('admin.platform.finance.export'))->assertOk()->streamedContent();

        $this->assertStringContainsString('SUB-9', $csv);
        $this->assertStringContainsString('1000.00', $csv);
    }

    public function test_an_empty_platform_still_opens(): void
    {
        $this->resetState();
        foreach (['admin.platform.dashboard', 'admin.platform.shops', 'admin.platform.finance'] as $name) {
            $this->actingAs($this->owner)->get(route($name))->assertOk();
        }
    }
}
