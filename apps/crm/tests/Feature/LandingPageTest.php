<?php

namespace Tests\Feature;

use App\Models\Faq;
use App\Models\Service;
use App\Models\Setting;
use App\Models\Slider;
use App\Support\Modules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The product's own landing page.
 *
 * What is worth holding here is not "it returns 200". It is that the page
 * cannot lie about the product:
 *
 *   - the feature grid is the real module catalogue, so it cannot advertise
 *     something that is not built;
 *   - it sells nothing itself: prices and the signup form are Khana
 *     Accounts', and the buttons go there or are not drawn.
 *
 * And that it renders for an install where nothing has been filled in, because
 * that is when somebody first opens it to see whether the deploy worked.
 */
class LandingPageTest extends TestCase
{
    use RefreshDatabase;

    // An install where nothing has been filled in - not the seeded baseline.
    protected bool $startsEmpty = true;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');
    }

    /* ----------------------------------------------------- the empty case */

    /**
     * Nothing configured at all: no services, no FAQs, no settings.
     *
     * The page must still say what the product is, because that is the part
     * that does not depend on data.
     */
    public function test_it_renders_with_nothing_configured(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Run your entire restaurant from', false)
            ->assertSee('How a table runs')
            // Sections with no rows must not leave their headings behind.
            ->assertDontSee('Plans')
            ->assertDontSee('Asked often')
            ->assertDontSee('What we do for you');
    }

    public function test_it_is_public(): void
    {
        $this->assertGuest();

        $this->get('/')->assertOk();
    }

    /* -------------------------------------------------- it tells the truth */

    /**
     * The feature grid is the module catalogue, not a copy deck.
     *
     * Every module the platform actually has must appear, with the same label
     * and blurb the admin uses - so a feature cannot be advertised here and
     * missing from the product.
     */
    public function test_the_features_are_the_real_module_catalogue(): void
    {
        $response = $this->get('/')->assertOk();

        foreach (Modules::catalogue() as $module) {
            $response->assertSee($module['label']);

            if (! empty($module['blurb'])) {
                $response->assertSee($module['blurb']);
            }
        }
    }

    /**
     * The headline is the product's sentence, not the SEO title.
     *
     * `meta_title_home` holds the company name on a real install, which is
     * right for a browser tab and useless as an <h1>. It must reach the title
     * tag and stop there.
     */
    public function test_the_seo_title_does_not_become_the_headline(): void
    {
        Setting::put(['meta_title_home' => 'Tiara Softwares']);

        $response = $this->get('/')->assertOk();

        $this->assertStringContainsString('<title>Tiara Softwares</title>', $response->getContent());
        $this->assertStringContainsString(
            '<h1>Run your entire restaurant from <em>one powerful system</em></h1>',
            $response->getContent(),
        );
    }

    /** The subheading is overridable, because that field really is a description. */
    public function test_the_subheading_can_be_edited(): void
    {
        Setting::put(['meta_description_home' => 'One system for every outlet you run.']);

        $this->get('/')
            ->assertOk()
            ->assertSee('One system for every outlet you run.');
    }

    /* -------------------------------------------------- opening an account */

    // The trial buttons go to Khana Accounts' signup form.
    public function test_free_trial_goes_to_khana_accounts(): void
    {
        config(['khana.urls.accounts' => 'https://accounts.example.test']);

        $this->get('/')
            ->assertOk()
            ->assertSee('Start free trial')
            ->assertSee('href="https://accounts.example.test/signup"', false);
    }

    // With no Accounts yet, the trial buttons still show and lead to the trial request form.
    public function test_without_an_accounts_address_free_trial_goes_to_the_request_form(): void
    {
        config(['khana.urls.accounts' => null]);

        $this->get('/')
            ->assertOk()
            ->assertSee('Start free trial')
            ->assertDontSee('/signup"', false)
            ->assertSee('Book a free demo');
    }

    /** An old price card's link still lands somewhere useful. */
    public function test_the_old_signup_address_forwards(): void
    {
        config(['khana.urls.accounts' => 'https://accounts.example.test/']);

        $this->get('/signup/restaurant')->assertRedirect('https://accounts.example.test/signup');

        config(['khana.urls.accounts' => null]);

        $this->get('/signup')->assertRedirect(route('landing'));
    }

    /* ---------------------------------------------------- other sections */

    public function test_services_appear_once_they_exist(): void
    {
        Service::create([
            'name' => 'On-site setup & training',
            'slug' => 'setup-training',
            'short_description' => 'We load your menu and train the floor.',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('What we do for you')
            // Escaped, because Blade renders the ampersand as &amp;.
            ->assertSee('On-site setup & training')
            ->assertSee('We load your menu and train the floor.');
    }

    public function test_an_inactive_row_does_not_render(): void
    {
        Service::create([
            'name' => 'Retired offering',
            'slug' => 'retired-offering',
            'is_active' => false,
            'sort_order' => 1,
        ]);

        $this->get('/')->assertOk()->assertDontSee('Retired offering');
    }

    public function test_faqs_render_as_open_close_details(): void
    {
        Faq::create([
            'question' => 'Can I use my own printers?',
            'answer' => 'Any ESC/POS printer on the network.',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $response = $this->get('/')->assertOk();

        $response->assertSee('Can I use my own printers?')
            ->assertSee('Any ESC/POS printer on the network.');

        // <details>, so it works with JavaScript off.
        $this->assertStringContainsString('<details class="lp-faq">', $response->getContent());
    }

    /* ----------------------------------------------------------- the strip */

    public function test_the_announcement_strip_prefers_a_slider_over_the_setting(): void
    {
        Setting::put(['topbar_offer_text' => 'Plain setting text']);

        Slider::create([
            'title' => 'Two months free on annual plans',
            'layout' => 'top_strip_bar',
            'item_no' => 1,
            'is_active' => true,
            'redirect_url' => 'https://example.test/offer',
        ]);

        $this->get('/')
            ->assertOk()
            // The slide wins, because it can carry a link.
            ->assertSee('Two months free on annual plans')
            ->assertDontSee('Plain setting text');
    }

    public function test_the_strip_falls_back_to_the_setting(): void
    {
        Setting::put(['topbar_offer_text' => 'Free migration from your old till']);

        $this->get('/')
            ->assertOk()
            ->assertSee('Free migration from your old till');
    }

    /* --------------------------------------------------------- the footer */

    public function test_the_company_name_comes_from_settings(): void
    {
        Setting::put(['company_name' => 'Tiara Softwares']);

        $this->get('/')->assertOk()->assertSee('Tiara Softwares');
    }

    public function test_an_unset_social_link_is_not_rendered(): void
    {
        Setting::put(['instagram_url' => 'https://instagram.test/acme', 'facebook_url' => '']);

        $response = $this->get('/')->assertOk();

        $response->assertSee('https://instagram.test/acme', false);
        // An empty href would render as a link to the current page.
        $response->assertDontSee('>Facebook</a>', false);
    }
}
