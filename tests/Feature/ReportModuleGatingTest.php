<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\User;
use App\Support\CurrentShop;
use App\Support\CurrentTenant;
use App\Support\Modules;
use App\Support\PlanAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Reports answer to the branch's modules like every other screen.
 *
 * The report routes carry no `permission:` middleware - each report checks
 * its own right in the controller - so the module gate, which reads that
 * middleware, never saw them. A till-only outlet could open the purchase,
 * stock and kitchen reports, and one with Reports switched off could open
 * all of them.
 */
class ReportModuleGatingTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');
        $this->seed();

        $this->shop = Shop::query()->withoutGlobalScopes()->orderBy('id')->firstOrFail();
        $this->owner = User::create([
            'tenant_id' => $this->shop->tenant_id, 'name' => 'Owner', 'email' => 'owner@example.test',
            'password' => 'Owner-pass-123', 'is_admin' => true, 'is_active' => true, 'current_shop_id' => $this->shop->id,
        ]);
        $this->owner->forceFill(['all_shops_view' => false])->save();
        $this->owner->shops()->attach($this->shop->id, ['is_default' => true]);
        $this->owner->assignRole('Tenant Owner');
    }

    /** @param  array<int, string>|null  $modules */
    private function modules(?array $modules): static
    {
        $this->shop->forceFill(['modules' => $modules])->saveQuietly();
        CurrentShop::forget();
        CurrentTenant::forget();
        PlanAccess::forget();
        Modules::forget();

        return $this->actingAs($this->owner->fresh());
    }

    public function test_with_reports_switched_off_no_report_opens(): void
    {
        $this->modules(['pos', 'retail'])->get('/superadmin/reports')->assertForbidden();
        $this->modules(['pos', 'retail'])->get('/superadmin/reports/sales')->assertForbidden();
        $this->modules(['pos', 'retail'])->get('/superadmin/reports/sales/export')->assertForbidden();
    }

    public function test_a_report_needs_the_line_of_business_it_reports_on(): void
    {
        $till = ['pos', 'retail', 'reports'];

        $this->modules($till)->get('/superadmin/reports/sales')->assertOk();

        foreach (['purchases', 'payables', 'stock', 'low-stock', 'expiry', 'transfers', 'kitchen', 'tables'] as $report) {
            $this->modules($till)->get('/superadmin/reports/'.$report)->assertForbidden();
            $this->modules($till)->get('/superadmin/reports/'.$report.'/export')->assertForbidden();
        }
    }

    public function test_the_reports_menu_lists_only_what_the_branch_runs(): void
    {
        $this->modules(['pos', 'retail', 'reports'])->get('/superadmin/reports')
            ->assertOk()
            ->assertSee(route('admin.reports.show', 'sales'), false)
            ->assertDontSee(route('admin.reports.show', 'purchases'), false)
            ->assertDontSee(route('admin.reports.show', 'kitchen'), false);
    }

    public function test_a_branch_running_everything_opens_every_report(): void
    {
        foreach (['sales', 'purchases', 'stock', 'kitchen', 'tables', 'outstanding', 'day-closing'] as $report) {
            $this->modules(null)->get('/superadmin/reports/'.$report)->assertOk();
        }
    }
}
