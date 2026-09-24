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

class CompanySettingsTest extends TestCase
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

    private function actor(): User
    {
        return User::where('is_admin', true)->firstOrFail();
    }

    /** An admin holding only the view permission, never edit. */
    private function viewer(): User
    {
        $user = User::create([
            'name' => 'Read Only',
            'email' => 'viewer@example.test',
            'password' => 'viewer-password-1',
            'is_admin' => true,
        ]);

        $user->givePermissionTo('settings.general.view');

        return $user;
    }

    public function test_page_renders_every_configured_section(): void
    {
        $response = $this->actingAs($this->actor())
            ->get('/admin/settings/company')
            ->assertOk();

        foreach (CompanySettings::sections() as $section) {
            $response->assertSee($section['label']);
        }
    }

    public function test_every_section_gets_a_tab_and_a_panel(): void
    {
        $html = $this->actingAs($this->actor())
            ->get('/admin/settings/company')
            ->assertOk()
            ->getContent();

        foreach (array_keys(CompanySettings::sections()) as $key) {
            $this->assertStringContainsString('data-tab="'.$key.'"', $html);
            $this->assertStringContainsString('data-tab-panel="'.$key.'"', $html);
            // Every panel keeps its form, so the markup still works with the
            // tab script disabled.
            $this->assertStringContainsString('data-settings-section="'.$key.'"', $html);
        }
    }

    public function test_seeded_values_are_shown(): void
    {
        $this->actingAs($this->actor())
            ->get('/admin/settings/company')
            ->assertSee('Tiara Softwares')
            ->assertSee('info@tiarasoftwares.com')
            ->assertSee('302004');
    }

    public function test_a_section_saves(): void
    {
        $this->actingAs($this->actor())
            ->putJson('/admin/settings/company/company', [
                'company_name' => 'Renamed Co',
                'company_title' => 'holdings ltd',
                'currency' => 'USD',
                'company_email' => 'hi@renamed.test',
                'phone' => '0100 000 000',
                'pan_no' => 'ABCDE1234F',
                'eic_no' => '',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('Renamed Co', Setting::get('company_name'));
        $this->assertSame('USD', Setting::get('currency'));
        $this->assertDatabaseHas('activity_logs', ['event' => 'settings.updated']);
    }

    public function test_one_section_save_leaves_other_sections_alone(): void
    {
        $before = Setting::get('meta_title_home');

        $this->actingAs($this->actor())
            ->putJson('/admin/settings/company/company', [
                'company_name' => 'Only This Changed',
                'currency' => 'INR',
                'amount_format' => 'indian',
            ])
            ->assertOk();

        $this->assertSame($before, Setting::get('meta_title_home'));
    }

    public function test_validation_errors_come_back_per_field(): void
    {
        $this->actingAs($this->actor())
            ->putJson('/admin/settings/company/company', [
                'company_name' => '',
                'currency' => 'XYZ',
                'company_email' => 'not-an-email',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['company_name', 'currency', 'company_email']]);
    }

    public function test_select_values_outside_the_configured_options_are_rejected(): void
    {
        $this->actingAs($this->actor())
            ->putJson('/admin/settings/company/general', ['date_format' => 'evil-format'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['date_format']]);
    }

    public function test_an_unknown_section_is_a_404(): void
    {
        $this->actingAs($this->actor())
            ->putJson('/admin/settings/company/not-a-section', [])
            ->assertNotFound();
    }

    /* ----------------------------------------------------------- secrets */

    public function test_secrets_are_never_sent_to_the_browser(): void
    {
        Setting::put(['mail_password' => 'super-secret-value']);

        $this->actingAs($this->actor())
            ->get('/admin/settings/company')
            ->assertOk()
            ->assertDontSee('super-secret-value');
    }

    public function test_a_blank_secret_keeps_the_stored_one(): void
    {
        Setting::put(['mail_password' => 'keep-me']);

        $this->actingAs($this->actor())
            ->putJson('/admin/settings/company/mail', [
                'mail_mailer' => 'smtp',
                'mail_host' => 'mail.example.test',
                'mail_port' => 587,
                'mail_username' => 'someone@example.test',
                'mail_password' => '',
                'mail_encryption' => 'tls',
                'mail_from_address' => 'someone@example.test',
                'mail_from_name' => 'Someone',
            ])
            ->assertOk();

        $this->assertSame('keep-me', Setting::get('mail_password'));
        $this->assertSame('mail.example.test', Setting::get('mail_host'));
    }

    public function test_a_filled_secret_replaces_the_stored_one(): void
    {
        Setting::put(['mail_password' => 'old-secret']);

        $this->actingAs($this->actor())
            ->putJson('/admin/settings/company/mail', [
                'mail_mailer' => 'smtp',
                'mail_host' => 'mail.example.test',
                'mail_port' => 587,
                'mail_password' => 'new-secret',
                'mail_encryption' => 'tls',
                'mail_from_address' => 'someone@example.test',
                'mail_from_name' => 'Someone',
            ])
            ->assertOk();

        $this->assertSame('new-secret', Setting::get('mail_password'));
    }

    /* ------------------------------------------------------------- files */

    public function test_a_logo_uploads_and_its_url_comes_back(): void
    {
        Storage::fake('public');

        $response = $this->actingAs($this->actor())
            ->post('/admin/settings/company/media', [
                '_method' => 'PUT',
                'site_logo' => UploadedFile::fake()->image('logo.png', 200, 80),
            ], ['Accept' => 'application/json'])
            ->assertOk();

        $path = Setting::get('site_logo');
        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);
        $this->assertNotNull($response->json('data.files.site_logo'));
    }

    public function test_replacing_a_logo_deletes_the_previous_file(): void
    {
        Storage::fake('public');

        $this->actingAs($this->actor())->post('/admin/settings/company/media', [
            '_method' => 'PUT',
            'site_logo' => UploadedFile::fake()->image('first.png', 200, 80),
        ], ['Accept' => 'application/json'])->assertOk();

        $first = Setting::get('site_logo');

        $this->actingAs($this->actor())->post('/admin/settings/company/media', [
            '_method' => 'PUT',
            'site_logo' => UploadedFile::fake()->image('second.png', 200, 80),
        ], ['Accept' => 'application/json'])->assertOk();

        $second = Setting::get('site_logo');

        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
    }

    public function test_an_empty_picker_keeps_the_stored_file(): void
    {
        Storage::fake('public');

        $this->actingAs($this->actor())->post('/admin/settings/company/media', [
            '_method' => 'PUT',
            'site_logo' => UploadedFile::fake()->image('logo.png', 200, 80),
        ], ['Accept' => 'application/json'])->assertOk();

        $path = Setting::get('site_logo');

        // Saving the section again with nothing chosen must not clear it.
        $this->actingAs($this->actor())
            ->putJson('/admin/settings/company/media', [])
            ->assertOk();

        $this->assertSame($path, Setting::get('site_logo'));
        Storage::disk('public')->assertExists($path);
    }

    public function test_a_logo_can_be_removed(): void
    {
        Storage::fake('public');

        $this->actingAs($this->actor())->post('/admin/settings/company/media', [
            '_method' => 'PUT',
            'site_logo' => UploadedFile::fake()->image('logo.png', 200, 80),
        ], ['Accept' => 'application/json'])->assertOk();

        $path = Setting::get('site_logo');

        $this->actingAs($this->actor())
            ->deleteJson('/admin/settings/company/media/file/site_logo')
            ->assertOk()
            ->assertJsonPath('data.files.site_logo', null);

        $this->assertNull(Setting::get('site_logo'));
        Storage::disk('public')->assertMissing($path);
    }

    public function test_non_images_are_rejected(): void
    {
        Storage::fake('public');

        $this->actingAs($this->actor())
            ->post('/admin/settings/company/media', [
                '_method' => 'PUT',
                'site_logo' => UploadedFile::fake()->create('payload.php', 10, 'text/php'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['site_logo']]);

        $this->assertNull(Setting::get('site_logo'));
    }

    /* -------------------------------------------------------- permissions */

    public function test_view_only_admins_cannot_save(): void
    {
        $viewer = $this->viewer();

        $this->actingAs($viewer)->get('/admin/settings/company')->assertOk();

        $this->actingAs($viewer)
            ->putJson('/admin/settings/company/company', ['company_name' => 'Nope', 'currency' => 'INR'])
            ->assertForbidden();

        $this->actingAs($viewer)
            ->deleteJson('/admin/settings/company/media/file/site_logo')
            ->assertForbidden();
    }

    public function test_guests_are_redirected(): void
    {
        $this->get('/admin/settings/company')->assertRedirect('http://localhost/admin/login');
    }

    /* -------------------------------------------------------------- store */

    public function test_the_cache_is_busted_on_write(): void
    {
        Setting::put(['company_name' => 'First']);
        $this->assertSame('First', Setting::get('company_name'));

        Setting::put(['company_name' => 'Second']);
        $this->assertSame('Second', Setting::get('company_name'));
    }
}
