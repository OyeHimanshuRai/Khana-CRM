<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The admin's navigation: a short sidebar of hubs, and each hub's screens as
 * tabs across the top of its pages (config/menu.php, App\Support\Navigation).
 *
 * What is held here is the promise the layout makes - any job is the hub,
 * then the tab - and that it never shows somebody a door they cannot open.
 */
class NavigationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::where('is_admin', true)->firstOrFail();
    }

    /** Somebody at the till who may do nothing else. */
    private function cashier(): User
    {
        $shop = Shop::query()->firstOrFail();

        $user = User::create([
            'tenant_id' => $shop->tenant_id,
            'name' => 'Till',
            'email' => 'till.nav@example.test',
            'password' => 'till-password-1',
            'is_admin' => true,
        ]);

        $user->givePermissionTo(['dashboard.overview.view', 'pos.terminal.view', 'pos.terminal.create']);
        $user->shops()->syncWithoutDetaching([$shop->id => ['is_default' => true]]);
        $user->forceFill(['current_shop_id' => $shop->id, 'all_shops_view' => false])->save();

        return $user->fresh();
    }

    public function test_the_sidebar_is_a_short_list_of_hubs(): void
    {
        $html = $this->actingAs($this->admin())->get('/admin/dashboard')->assertOk()->getContent();

        $entries = substr_count($html, 'class="nav-item"');

        $this->assertGreaterThan(5, $entries);
        $this->assertLessThanOrEqual(16, $entries, 'The sidebar is meant to be read at a glance.');

        foreach (['Floor', 'Kitchen', 'Billing', 'Orders', 'Menu', 'Customers', 'Reports', 'Settings'] as $hub) {
            $this->assertStringContainsString('data-label="'.$hub.'"', $html);
        }
    }

    public function test_a_hub_page_shows_its_screens_as_tabs_with_the_open_one_lit(): void
    {
        $response = $this->actingAs($this->admin())->get('/admin/categories')->assertOk();

        $response->assertSee('class="hub-tabs"', false)
            ->assertSee('href="'.route('admin.products.index').'"', false)
            ->assertSee('href="'.route('admin.modifiers.index').'"', false);

        $this->assertMatchesRegularExpression(
            '/class="tab is-active"\s+href="'.preg_quote(route('admin.categories.index'), '/').'"/',
            $response->getContent(),
        );
    }

    /** Every report is one route; only the one open is lit. */
    public function test_only_the_open_report_is_lit(): void
    {
        $html = $this->actingAs($this->admin())->get('/admin/reports/tax')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'class="tab is-active"'));
        $this->assertMatchesRegularExpression(
            '/class="tab is-active"\s+href="'.preg_quote(route('admin.reports.show', 'tax'), '/').'"/',
            $html,
        );
    }

    public function test_the_hub_links_to_its_first_screen(): void
    {
        $html = $this->actingAs($this->admin())->get('/admin/dashboard')->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/href="'.preg_quote(route('admin.tables.plan'), '/').'"\s+data-label="Floor"/',
            $html,
        );
    }

    /**
     * Somebody who may open one screen of a hub is sent straight to it,
     * sees no strip of screens they cannot open, and no hub with nothing in
     * it for them.
     */
    public function test_a_narrow_role_sees_only_its_own_doors(): void
    {
        $cashier = $this->cashier();

        $html = $this->actingAs($cashier)->get('/admin/pos')->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/href="'.preg_quote(route('admin.pos.terminal'), '/').'"\s+data-label="Billing"/',
            $html,
        );
        $this->assertStringContainsString('href="'.route('admin.pos.parked').'"', $html);
        $this->assertStringNotContainsString('href="'.route('admin.registers.index').'"', $html);
        $this->assertStringNotContainsString('data-label="Settings"', $html);
        $this->assertStringNotContainsString('data-label="Customers"', $html);
    }

    /** A page outside every hub carries no strip. */
    public function test_a_single_screen_has_no_tab_strip(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/dashboard')
            ->assertOk()
            ->assertDontSee('class="hub-tabs"', false);
    }
}
