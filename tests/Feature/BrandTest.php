<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Support\CompanySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The sidebar and login brand, driven by Settings > General.
 */
class BrandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // APP_URL carries the /er sub-path, which would prefix every test
        // request and miss the routes entirely.
        URL::forceRootUrl('http://localhost');

        $this->seed();
    }

    private function admin(): User
    {
        return User::where('is_admin', true)->firstOrFail();
    }

    /**
     * Undo an earlier actingAs().
     *
     * The settings endpoints need an admin, but /admin/login is behind
     * `guest` - so a test that does both has to drop the session between.
     */
    private function asGuestAgain(): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();
    }

    /** Upload a Site Logo the way the settings screen does. */
    private function uploadLogo(): string
    {
        $this->actingAs($this->admin())->post('/admin/settings/company/media', [
            '_method' => 'PUT',
            'site_logo' => UploadedFile::fake()->image('logo.png', 200, 80),
        ], ['Accept' => 'application/json'])->assertOk();

        return Setting::get('site_logo');
    }

    /* -------------------------------------------------------------- logo */

    public function test_the_uploaded_logo_appears_in_the_sidebar(): void
    {
        Storage::fake('public');
        $this->uploadLogo();

        $html = $this->actingAs($this->admin())
            ->get('/admin/dashboard')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('brand-mark has-logo', $html);
        $this->assertStringContainsString(CompanySettings::fileUrl('site_logo'), $html);
    }

    public function test_the_uploaded_logo_appears_on_the_login_screen(): void
    {
        Storage::fake('public');
        $this->uploadLogo();

        // Uploading signed us in; /admin/login is behind `guest`.
        $this->asGuestAgain();

        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('brand-mark has-logo', false);
    }

    public function test_without_a_logo_it_falls_back_to_a_lettermark(): void
    {
        Storage::fake('public');
        Setting::put(['site_logo' => null, 'company_name' => 'Tiara Softwares']);

        $html = $this->get('/admin/login')->assertOk()->getContent();

        $this->assertStringNotContainsString('has-logo', $html);
        // First three letters of the company name.
        $this->assertStringContainsString('TIA', $html);
    }

    public function test_a_logo_whose_file_has_gone_missing_falls_back_too(): void
    {
        Storage::fake('public');

        // A restored database, or a swept disk: the row points at nothing.
        Setting::put(['site_logo' => 'settings/deleted.png']);

        $this->assertNull(CompanySettings::fileUrl('site_logo'));

        // The chrome must degrade, not render a broken image.
        $this->get('/admin/login')
            ->assertOk()
            ->assertDontSee('has-logo', false);
    }

    public function test_removing_the_logo_restores_the_lettermark(): void
    {
        Storage::fake('public');
        $this->uploadLogo();

        $this->actingAs($this->admin())
            ->deleteJson('/admin/settings/company/media/file/site_logo')
            ->assertOk();

        $this->asGuestAgain();

        $this->get('/admin/login')
            ->assertOk()
            ->assertDontSee('has-logo', false);
    }

    /* ----------------------------------------------------------- favicon */

    public function test_the_uploaded_favicon_becomes_the_tab_icon(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin())->post('/admin/settings/company/media', [
            '_method' => 'PUT',
            'favicon' => UploadedFile::fake()->image('icon.png', 32, 32),
        ], ['Accept' => 'application/json'])->assertOk();

        $url = CompanySettings::fileUrl('favicon');

        $html = $this->actingAs($this->admin())
            ->get('/admin/dashboard')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('<link rel="icon" type="image/png" href="'.$url.'">', $html);
        // The bundled default must be gone, not merely joined.
        $this->assertStringNotContainsString('favicon.ico', $html);
    }

    public function test_the_favicon_type_follows_the_uploaded_file(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin())->post('/admin/settings/company/media', [
            '_method' => 'PUT',
            'favicon' => UploadedFile::fake()->image('icon.webp', 32, 32),
        ], ['Accept' => 'application/json'])->assertOk();

        $this->actingAs($this->admin())
            ->get('/admin/dashboard')
            ->assertOk()
            ->assertSee('type="image/webp"', false);
    }

    public function test_without_an_upload_the_bundled_favicon_is_used(): void
    {
        Storage::fake('public');
        Setting::put(['favicon' => null]);

        $this->actingAs($this->admin())
            ->get('/admin/dashboard')
            ->assertOk()
            ->assertSee('favicon.ico', false);
    }

    public function test_a_favicon_whose_file_has_gone_missing_falls_back(): void
    {
        Storage::fake('public');

        // A restored database, or a swept disk: the row points at nothing.
        Setting::put(['favicon' => 'settings/deleted.png']);

        $this->actingAs($this->admin())
            ->get('/admin/dashboard')
            ->assertOk()
            ->assertSee('favicon.ico', false);
    }

    public function test_the_page_title_carries_the_company_name(): void
    {
        Setting::put(['company_name' => 'Tiara Softwares']);

        $this->actingAs($this->admin())
            ->get('/admin/dashboard')
            ->assertOk()
            ->assertSee('<title>Dashboard &middot; Tiara Softwares</title>', false);
    }

    /* -------------------------------------------------------------- name */

    public function test_the_brand_name_comes_from_the_company_settings(): void
    {
        Setting::put(['company_name' => 'Tiara Softwares']);

        $this->get('/admin/login')->assertOk()->assertSee('Tiara Softwares');
    }

    public function test_a_blank_company_name_falls_back_to_the_app_name(): void
    {
        Setting::put(['company_name' => '']);

        // Setting::get treats a stored empty string as unset.
        $this->get('/admin/login')->assertOk()->assertSee(config('app.name'));
    }

    public function test_renaming_the_company_updates_the_chrome_immediately(): void
    {
        $this->actingAs($this->admin())
            ->putJson('/admin/settings/company/company', [
                'company_name' => 'Renamed Holdings',
                'currency' => 'INR',
            ])
            ->assertOk();

        // The settings cache is busted on write, so no reload lag.
        $this->actingAs($this->admin())
            ->get('/admin/dashboard')
            ->assertOk()
            ->assertSee('Renamed Holdings');
    }
}
