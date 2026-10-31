<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\DemoRequest;
use App\Models\Page;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The public site's header and footer come from Settings, the same on every
 * public page; and the landing page's demo form posts without a reload.
 */
class PublicBrandingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');

        $this->seed();
    }

    private function brand(array $overrides = []): void
    {
        Setting::put(array_merge([
            'company_name' => 'Tiara Softwares',
            'company_title' => 'Restaurant software that runs the room',
            'phone' => '+91 98765 43210',
            'company_email' => 'hello@tiara.test',
            'whatsapp_no' => '+91 99887 76655',
            'address_line_1' => '12 MG Road',
            // The seeder fills a second line; this test's address has none.
            'address_line_2' => '',
            'city' => 'Jaipur',
            'state' => 'Rajasthan',
            'zip_code' => '302001',
            'country' => 'India',
            'operation_days' => 'Mon - Sat',
            'operation_hours' => '10 am - 7 pm',
        ], $overrides));
    }

    private function policyPage(): Page
    {
        return Page::create([
            'title' => 'Privacy Policy', 'slug' => 'privacy-policy',
            'body' => '<p>We keep your data safe.</p>', 'is_active' => true, 'show_in_footer' => true,
        ]);
    }

    /** @return array<string, string> url => what the page is */
    private function publicPages(): array
    {
        $this->policyPage();

        return [
            '/' => 'landing',
            route('page.show', 'privacy-policy') => 'policy page',
            '/register' => 'sign-up',
        ];
    }

    /* ---------------------------------------------------- header & footer */

    public function test_every_public_footer_shows_the_contact_details_from_settings(): void
    {
        $this->brand();

        foreach ($this->publicPages() as $url => $what) {
            $this->get($url)->assertOk()
                ->assertSee('Restaurant software that runs the room')
                ->assertSee('+91 98765 43210')
                ->assertSee('href="tel:+919876543210"', false)
                ->assertSee('mailto:hello@tiara.test', false)
                ->assertSee('https://wa.me/919988776655', false)
                ->assertSee('12 MG Road, Jaipur, Rajasthan, 302001, India')
                ->assertSee('Mon - Sat · 10 am - 7 pm')
                ->assertSee('Privacy Policy')
                ->assertSee('&copy; '.date('Y').' Tiara Softwares', false);
        }
    }

    public function test_a_detail_left_empty_in_settings_is_not_printed(): void
    {
        $this->brand(['whatsapp_no' => '', 'operation_days' => '', 'operation_hours' => '', 'company_email' => '']);

        $this->get('/')->assertOk()
            ->assertDontSee('wa.me', false)
            ->assertDontSee('mailto:', false)
            ->assertSee('+91 98765 43210');
    }

    public function test_the_logos_and_favicon_come_from_the_uploads(): void
    {
        Storage::fake('public');

        $logo = UploadedFile::fake()->image('logo.png', 200, 80)->store('settings', 'public');
        $footer = UploadedFile::fake()->image('footer.png', 200, 80)->store('settings', 'public');
        $favicon = UploadedFile::fake()->image('icon.png', 32, 32)->store('settings', 'public');

        $this->brand(['site_logo' => $logo, 'footer_logo' => $footer, 'favicon' => $favicon]);

        foreach ($this->publicPages() as $url => $what) {
            $page = $this->get($url)->assertOk();

            $page->assertSee(Storage::disk('public')->url($logo), false)
                ->assertSee(Storage::disk('public')->url($footer), false)
                ->assertSee('<link rel="icon" type="image/png" href="'.Storage::disk('public')->url($favicon).'">', false);
        }
    }

    public function test_with_nothing_uploaded_the_name_and_shipped_icon_stand_in(): void
    {
        $this->brand();

        $this->get('/')->assertOk()
            ->assertSee('<span class="lp-brand-text">Tiara Softwares</span>', false)
            ->assertSee('<link rel="icon" href="'.asset('favicon.ico').'" sizes="any">', false);
    }

    public function test_the_footer_logo_falls_back_to_the_header_logo(): void
    {
        Storage::fake('public');
        $logo = UploadedFile::fake()->image('logo.png', 200, 80)->store('settings', 'public');
        $this->brand(['site_logo' => $logo]);

        $html = $this->get('/')->assertOk()->getContent();

        // Header, footer, and the og:image share tag.
        $this->assertSame(3, substr_count($html, Storage::disk('public')->url($logo)));
    }

    public function test_the_admin_brand_uses_the_icon_and_the_name_from_settings(): void
    {
        Storage::fake('public');
        $logo = UploadedFile::fake()->image('logo.png', 480, 128)->store('settings', 'public');
        $icon = UploadedFile::fake()->image('icon.png', 256, 256)->store('settings', 'public');
        $this->brand(['company_name' => 'Restro One', 'site_logo' => $logo, 'company_icon' => $icon]);

        // The restaurants' sign-in names the company too.
        $this->get(route('login'))->assertOk()->assertSee('New to Restro One?');

        $html = $this->get(route('admin.login'))->assertOk()->getContent();

        // The square slot beside the name gets the icon, not the wordmark -
        // a logo that spells the name would say it twice.
        preg_match('/<span class="brand-mark[^"]*"[^>]*>(.*?)<\/span>/s', $html, $mark);
        $this->assertStringContainsString(Storage::disk('public')->url($icon), $mark[1] ?? '');
        $this->assertStringNotContainsString(Storage::disk('public')->url($logo), $mark[1] ?? '');
    }

    public function test_a_restaurant_account_is_told_a_platform_page_is_not_theirs(): void
    {
        $owner = User::create([
            'tenant_id' => \App\Models\Tenant::query()->value('id'),
            'name' => 'Kumar', 'email' => 'owner@kumar.test', 'password' => 'owner-password-1', 'is_admin' => true,
        ]);
        $owner->givePermissionTo(['dashboard.overview.view', 'settings.general.view']);

        $this->actingAs($owner->fresh())->get(route('admin.settings.company'))
            ->assertForbidden()
            ->assertSee('This page belongs to the platform, not to a restaurant.')
            ->assertSee('owner@kumar.test');
    }

    public function test_the_super_admin_signs_in_with_a_plus_address(): void
    {
        User::query()->withoutGlobalScopes()->where('email', 'admin@erp.test')
            ->update(['email' => 'shiva420466+admin@gmail.com']);

        $this->postJson(route('admin.login.store'), [
            'email' => 'Shiva420466+Admin@Gmail.com',
            'password' => 'password',
        ])->assertOk()->assertJsonPath('success', true);

        $this->get(route('admin.settings.company'))->assertOk()->assertSee('Mail Configuration');
    }

    /* ----------------------------------------------------------- demo form */

    private function demo(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Ravi Kumar', 'phone' => '+91 98111 22233', 'email' => 'Ravi@Spice.TEST',
            'business_name' => 'Spice Garden', 'city' => 'Jaipur', 'outlets' => 2,
        ], $overrides);
    }

    public function test_the_landing_page_wires_the_demo_form_for_ajax(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('data-demo-form', false)
            ->assertSee('data-ajax', false)
            ->assertSee('assets/js/demo-form.js', false)
            ->assertSee('assets/js/app.js', false)
            ->assertSee('name="csrf-token"', false);
    }

    public function test_a_demo_request_over_ajax_is_saved_and_thanked(): void
    {
        $this->postJson(route('demo-request.store'), $this->demo())
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Thank you — we will call you back shortly.');

        $lead = DemoRequest::query()->firstOrFail();
        $this->assertSame('ravi@spice.test', $lead->email);
        $this->assertSame(DemoRequest::NEW, $lead->status);
    }

    public function test_a_demo_request_rings_the_platforms_bell_only(): void
    {
        $this->postJson(route('demo-request.store'), $this->demo())->assertOk();

        $alert = Alert::allShops()->where('type', Alert::DEMO_REQUEST)->firstOrFail();
        $this->assertStringContainsString('Spice Garden', $alert->title);

        $admin = User::query()->withoutGlobalScopes()->where('email', 'admin@erp.test')->firstOrFail();
        $this->assertTrue($alert->isVisibleTo($admin));

        $owner = User::create([
            'tenant_id' => \App\Models\Tenant::query()->value('id'),
            'name' => 'Owner', 'email' => 'owner@x.test', 'password' => 'owner-password-1', 'is_admin' => true,
        ]);
        $owner->givePermissionTo('content.demo_requests.view');

        $this->assertFalse($alert->isVisibleTo($owner->fresh()), 'leads are the platform\'s, not a restaurant\'s');
    }

    public function test_the_demo_form_needs_a_phone_or_an_email_and_a_real_phone(): void
    {
        $this->postJson(route('demo-request.store'), $this->demo(['phone' => '', 'email' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['phone', 'email']);

        $this->postJson(route('demo-request.store'), $this->demo(['phone' => 'call me', 'email' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['phone' => 'Enter a valid phone number.']);

        // Either one alone is enough.
        $this->postJson(route('demo-request.store'), $this->demo(['email' => '']))->assertOk();
        $this->postJson(route('demo-request.store'), $this->demo(['phone' => '']))->assertOk();

        $this->assertSame(2, DemoRequest::query()->count());
    }

    public function test_a_bot_is_thanked_and_nothing_is_stored(): void
    {
        $this->postJson(route('demo-request.store'), $this->demo(['website' => 'http://spam.test']))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(0, DemoRequest::query()->count());
    }

    public function test_without_javascript_it_still_works(): void
    {
        $this->post(route('demo-request.store'), $this->demo())
            ->assertRedirect(route('demo'));

        $this->get(route('demo'))->assertSee('Thank you — we will call you back shortly.');
        $this->assertSame(1, DemoRequest::query()->count());
    }
}
