<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\Slider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The landing page's pictures.
 *
 * Every picture slot is a Settings > Landing Page upload with a shipped
 * illustration behind it, so the page is whole on a fresh install and a
 * removed upload never leaves a hole. The hero's background is the Sliders
 * module's Landing Hero Background layout. Only the platform - Super Admin -
 * can change any of it.
 */
class LandingImagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');
        Storage::fake('public');
    }

    private function superAdmin(): User
    {
        $this->seed();

        return User::query()->withoutGlobalScopes()->where('email', 'admin@erp.test')->firstOrFail();
    }

    /** @param array<string, mixed> $attributes */
    private function slide(array $attributes = [], bool $withImage = true): Slider
    {
        $slide = Slider::create([
            'title' => 'Dining room',
            'layout' => 'main_banner',
            'item_no' => Slider::nextItemNo('main_banner'),
            'is_active' => true,
            ...$attributes,
        ]);

        if ($withImage) {
            $slide->forceFill([
                'desktop_image_path' => UploadedFile::fake()->image('wide.jpg', 2400, 1200)->store('sliders', 'public'),
            ])->save();
        }

        return $slide;
    }

    /* ------------------------------------------------------------ defaults */

    public function test_a_fresh_install_draws_the_built_in_illustrations(): void
    {
        $response = $this->get('/')->assertOk();

        foreach (['hero', 'step-scan', 'step-order', 'step-kitchen', 'step-bill', 'shot-pos', 'shot-reports'] as $file) {
            $response->assertSee(asset("assets/img/landing/{$file}.svg"), false);
        }

        // The flow the page sells, in the order it happens.
        $response->assertSeeInOrder(['Guest scans the QR on the table', 'They order from their seat',
            'The kitchen gets it instantly', 'The counter settles the table']);
    }

    public function test_an_upload_replaces_its_illustration(): void
    {
        $hero = UploadedFile::fake()->image('hero.png', 1440, 1040)->store('settings', 'public');
        $machine = UploadedFile::fake()->image('printer.jpg', 1200, 840)->store('settings', 'public');

        Setting::put(['landing_hero_image' => $hero, 'landing_billing_image' => $machine]);

        $this->get('/')->assertOk()
            ->assertSee(Storage::disk('public')->url($hero), false)
            ->assertSee(Storage::disk('public')->url($machine), false)
            ->assertDontSee(asset('assets/img/landing/hero.svg'), false)
            // The other slots keep their illustrations.
            ->assertSee(asset('assets/img/landing/shot-pos.svg'), false);
    }

    public function test_an_upload_whose_file_is_gone_falls_back(): void
    {
        Setting::put(['landing_hero_image' => 'settings/swept-away.png']);

        $this->get('/')->assertOk()->assertSee(asset('assets/img/landing/hero.svg'), false);
    }

    /* ---------------------------------------------------- hero background */

    public function test_without_slides_the_hero_has_its_plain_background(): void
    {
        $this->get('/')->assertOk()
            ->assertDontSee('has-photo', false)
            ->assertDontSee('landing-hero.js', false);
    }

    public function test_active_slides_with_a_picture_go_behind_the_hero(): void
    {
        $first = $this->slide();
        $second = $this->slide(['title' => 'Kitchen pass']);
        $this->slide(['title' => 'Switched off', 'is_active' => false]);
        $this->slide(['title' => 'No picture'], withImage: false);
        $this->slide(['title' => 'Elsewhere', 'layout' => 'crafting']);

        $html = $this->get('/')->assertOk()
            ->assertSee('has-photo', false)
            ->assertSee(Storage::disk('public')->url($first->desktop_image_path), false)
            ->assertSee(Storage::disk('public')->url($second->desktop_image_path), false)
            // Two slides need the crossfade script.
            ->assertSee('landing-hero.js', false)
            ->getContent();

        $this->assertSame(2, substr_count($html, 'class="lp-hero-slide'));
    }

    public function test_one_slide_needs_no_script(): void
    {
        $this->slide();

        $this->get('/')->assertOk()
            ->assertSee('has-photo', false)
            ->assertDontSee('landing-hero.js', false);
    }

    /* ----------------------------------------------------- who manages it */

    public function test_super_admin_uploads_and_removes_a_landing_picture(): void
    {
        $this->actingAs($this->superAdmin());

        $this->get(route('admin.settings.company'))->assertOk()
            ->assertSee('Landing Page')
            ->assertSee('Billing Machine / Invoice');

        $this->put(route('admin.settings.company.update', 'landing'), [
            'landing_billing_image' => UploadedFile::fake()->image('printer.png', 1200, 840),
        ], ['Accept' => 'application/json'])->assertOk();

        $path = Setting::get('landing_billing_image');
        $this->assertNotEmpty($path);
        Storage::disk('public')->assertExists($path);
        $this->get('/')->assertSee(Storage::disk('public')->url($path), false);

        $this->deleteJson(route('admin.settings.company.file.destroy', ['landing', 'landing_billing_image']))
            ->assertOk();

        $this->get('/')->assertSee(asset('assets/img/landing/step-bill.svg'), false)
            ->assertDontSee(Storage::disk('public')->url($path), false);
    }

    public function test_a_landing_picture_must_be_an_image(): void
    {
        $this->actingAs($this->superAdmin());

        $this->put(route('admin.settings.company.update', 'landing'), [
            'landing_hero_image' => UploadedFile::fake()->create('menu.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('landing_hero_image');
    }

    public function test_a_restaurant_account_cannot_change_the_landing_page(): void
    {
        $this->seed();

        $owner = User::create([
            'tenant_id' => \App\Models\Tenant::query()->value('id'),
            'name' => 'Kumar', 'email' => 'owner@kumar.test', 'password' => 'owner-password-1', 'is_admin' => true,
        ]);
        $owner->givePermissionTo(['dashboard.overview.view', 'settings.general.view', 'settings.general.edit']);

        $this->actingAs($owner->fresh())->put(route('admin.settings.company.update', 'landing'), [
            'landing_hero_image' => UploadedFile::fake()->image('hero.png', 1440, 1040),
        ], ['Accept' => 'application/json'])->assertForbidden();

        $this->assertNull(Setting::get('landing_hero_image'));
    }
}
