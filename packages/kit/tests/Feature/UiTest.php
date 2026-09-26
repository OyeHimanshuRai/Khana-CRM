<?php

namespace Khana\Kit\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Khana\Kit\Tenancy\Context;
use Khana\Kit\Tests\Concerns\SeedsReference;
use Khana\Kit\Tests\Fixtures\Staff;
use Khana\Kit\Tests\TestCase;
use Khana\Kit\Ui\EntitledProductSwitcher;
use Khana\Kit\Ui\OutletController;
use Khana\Kit\Ui\Ui;

/**
 * The one look: shared files served, the shared layout drawn, and the
 * switchers showing only what the outlet owns and the person may reach.
 */
class UiTest extends TestCase
{
    use RefreshDatabase;
    use SeedsReference;

    private const TENANT = '01M3D669MZPZP9EP7ND2XFY1W2';

    private const OTHER_TENANT = '01M3D669VCFXVZXBX0T9JTXSCC';

    private const SUITE = '01M3D669NC4JCJSRMWG4WWDRRT';

    private const CRM_ONLY = '01M3D669NWA5VJ3CD5YEJ3NZZC';

    private const STRANGER = '01M3D669SYM43EBW94WTHNG61K';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'khana.urls' => ['accounts' => 'https://accounts.test', 'crm' => 'https://crm.test', 'erp' => 'https://erp.test'],
            'menu' => [[
                'heading' => null,
                'items' => [
                    ['label' => 'Home', 'route' => 'home', 'icon' => 'home'],
                    ['label' => 'Secret', 'route' => 'home', 'icon' => 'lock', 'can' => 'see-secrets'],
                ],
            ]],
        ]);

        Route::middleware('web')->group(function () {
            Route::get('/', fn () => view('khana::layouts.app'))->name('home');
            Route::post('/logout', fn () => 'bye')->name('logout');
        });

        $this->seedOutlet(self::TENANT, self::SUITE, ['crm' => 'active', 'erp' => 'active'], 'Suite Kitchen');
        $this->seedOutlet(self::TENANT, self::CRM_ONLY, ['crm' => 'active'], 'Counter Only');
        $this->seedOutlet(self::OTHER_TENANT, self::STRANGER, ['crm' => 'active', 'erp' => 'active'], 'Someone Else');
    }

    public function test_the_shared_files_are_served_from_the_kit_until_published(): void
    {
        $this->get('/vendor/khana/css/theme.css')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/css; charset=UTF-8');

        $this->get('/vendor/khana/js/app.js')->assertOk();

        $this->assertStringContainsString('/vendor/khana/css/theme.css?v=', Ui::asset('css/theme.css'));
    }

    public function test_nothing_outside_the_ui_folder_can_be_fetched(): void
    {
        $this->get('/vendor/khana/../config/khana.php')->assertNotFound();
        $this->get('/vendor/khana/..%2Fsrc%2FUi%2FUi.php')->assertNotFound();
        $this->get('/vendor/khana/css/missing.css')->assertNotFound();
        $this->get('/vendor/khana/js')->assertNotFound();
    }

    public function test_the_layout_draws_the_menu_the_person_may_use_and_the_outlets_they_may_reach(): void
    {
        $this->actingAs(Staff::at(self::TENANT, [self::SUITE, self::CRM_ONLY], self::SUITE));

        $page = $this->get('/')->assertOk();

        $page->assertSee('Home')
            ->assertDontSee('Secret')
            ->assertSee('Suite Kitchen')
            ->assertSee('Counter Only')
            ->assertDontSee('Someone Else')
            ->assertSee('/vendor/khana/js/app.js', false);
    }

    public function test_the_switcher_offers_only_the_products_this_outlet_owns(): void
    {
        $this->actingAs(Staff::at(self::TENANT, [self::SUITE, self::CRM_ONLY], self::CRM_ONLY));

        $links = collect(app(EntitledProductSwitcher::class)->links())->keyBy('key');

        $this->assertSame('https://accounts.test/', $links['accounts']['url']);
        $this->assertTrue($links['crm']['current']);
        $this->assertNull($links['erp']['url'], 'A CRM-only outlet must not be offered ERP.');

        app(Context::class)->setOutlet(self::SUITE);
        $links = collect(app(EntitledProductSwitcher::class)->links())->keyBy('key');

        $this->assertSame('https://erp.test/khana/open?outlet='.self::SUITE, $links['erp']['url']);
    }

    public function test_opening_a_product_selects_the_outlet_the_switcher_passed(): void
    {
        $staff = Staff::at(self::TENANT, [self::SUITE, self::CRM_ONLY], self::CRM_ONLY);
        $this->actingAs($staff);

        $this->get('/khana/open?outlet='.self::SUITE)->assertRedirect(route('home'));
        $this->assertSame(self::SUITE, $staff->chosenOutlet);

        // Another business's outlet is ignored, not selected.
        $this->get('/khana/open?outlet='.self::STRANGER)->assertRedirect(route('home'));
        $this->assertSame(self::SUITE, $staff->chosenOutlet);
    }

    public function test_opening_a_product_while_signed_out_remembers_the_outlet_for_after_sign_in(): void
    {
        $this->get('/khana/open?outlet='.self::SUITE)->assertRedirect();

        $this->assertSame(self::SUITE, session(OutletController::PENDING));
    }

    public function test_switching_outlet_refuses_one_the_person_cannot_reach(): void
    {
        $staff = Staff::at(self::TENANT, [self::SUITE, self::CRM_ONLY], self::SUITE);
        $this->actingAs($staff);

        $this->postJson('/khana/outlet', ['outlet' => self::STRANGER])->assertForbidden();
        $this->assertSame(self::SUITE, $staff->chosenOutlet);

        $this->postJson('/khana/outlet', ['outlet' => self::CRM_ONLY])->assertOk()->assertJson(['success' => true]);
        $this->assertSame(self::CRM_ONLY, $staff->chosenOutlet);
    }

    public function test_switching_outlet_needs_a_signed_in_person(): void
    {
        $this->postJson('/khana/outlet', ['outlet' => self::SUITE])->assertUnauthorized();
    }
}
