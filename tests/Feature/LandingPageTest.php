<?php

namespace Tests\Feature;

use App\Models\Faq;
use App\Models\Plan;
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
 *   - the prices are the real `plans` rows, so there is no second copy of a
 *     price to drift;
 *   - an archived plan stops being sold the moment it is archived.
 *
 * And that it renders for an install where nothing has been filled in, because
 * that is when somebody first opens it to see whether the deploy worked.
 */
class LandingPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');
    }

    /** @param array<string, mixed> $attributes */
    private function plan(array $attributes = []): Plan
    {
        return Plan::create([
            'name' => 'Starter',
            'code' => 'STARTER',
            'slug' => 'starter',
            'monthly_price' => 799,
            'yearly_price' => 7990,
            'currency' => 'INR',
            'trial_days' => 14,
            'max_shops' => 1,
            'max_users' => 5,
            'is_active' => true,
            'sort_order' => 1,
            ...$attributes,
        ]);
    }

    /* ----------------------------------------------------- the empty case */

    /**
     * Nothing configured at all: no plans, no services, no FAQs, no settings.
     *
     * The page must still say what the product is, because that is the part
     * that does not depend on data.
     */
    public function test_it_renders_with_nothing_configured(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Run the whole restaurant from one screen')
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
        $this->assertStringContainsString('<h1>Run the whole restaurant from one screen</h1>', $response->getContent());
    }

    /** The subheading is overridable, because that field really is a description. */
    public function test_the_subheading_can_be_edited(): void
    {
        Setting::put(['meta_description_home' => 'One system for every outlet you run.']);

        $this->get('/')
            ->assertOk()
            ->assertSee('One system for every outlet you run.');
    }

    /* ------------------------------------------------------------ pricing */

    public function test_plans_are_priced_from_the_plans_table(): void
    {
        $this->plan([
            'name' => 'Restaurant',
            'code' => 'REST',
            'slug' => 'restaurant',
            'monthly_price' => 1999,
            'yearly_price' => 19990,
            'blurb' => 'For a single busy dining room.',
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('Plans')
            ->assertSee('Restaurant')
            ->assertSee('For a single busy dining room.')
            ->assertSee('₹1,999')
            ->assertSee('₹19,990')
            // Ten months' money for twelve months' service.
            ->assertSee('2 months free')
            ->assertSee('14-day free trial');
    }

    /** An archived plan is one existing subscribers keep and nobody new can buy. */
    public function test_an_inactive_plan_is_not_advertised(): void
    {
        $this->plan(['name' => 'Legacy Deal', 'code' => 'LEG', 'slug' => 'legacy', 'is_active' => false]);

        $this->get('/')
            ->assertOk()
            ->assertDontSee('Legacy Deal');
    }

    /**
     * The limits on a card are the plan's own, rendered by the model.
     *
     * Same method the tenant's subscription screen uses, so the promise on the
     * landing page and the enforcement inside the app cannot disagree.
     */
    public function test_a_plans_limits_are_listed(): void
    {
        $this->plan(['max_shops' => 3, 'max_users' => 25]);

        $this->get('/')
            ->assertOk()
            ->assertSee('25 staff accounts');
    }

    /**
     * A price is for one outlet, and the card never says otherwise.
     *
     * The page has always read "Per outlet, billed monthly or yearly" while
     * the card underneath it listed "3 branches" - so a visitor was told the
     * same figure covered one restaurant and three. Subscriptions are sold
     * per outlet now, and `max_shops` survives only as a rule about blanket
     * rows taken out before that; it is not a thing this plan grants, so it
     * is not on the price list. See Plan::limitLines().
     */
    public function test_a_plan_is_priced_for_one_outlet_and_advertises_no_branch_count(): void
    {
        $this->plan(['max_shops' => 3, 'max_users' => 25]);

        $this->get('/')
            ->assertOk()
            ->assertSee('One outlet')
            ->assertDontSee('3 branches');
    }

    /** Unlimited is a null column, and must read as words rather than a blank. */
    public function test_null_limits_read_as_unlimited(): void
    {
        $this->plan(['max_shops' => null, 'max_users' => null]);

        $this->get('/')
            ->assertOk()
            ->assertSee('One outlet')
            ->assertDontSee('Unlimited branches')
            ->assertSee('Unlimited staff accounts');
    }

    /**
     * The middle plan is flagged, and only when there is a middle to flag.
     */
    public function test_one_plan_is_never_flagged_most_popular(): void
    {
        $this->plan();

        $this->get('/')
            ->assertOk()
            ->assertDontSee('Most popular');
    }

    public function test_the_middle_plan_is_flagged(): void
    {
        $this->plan(['name' => 'Starter', 'code' => 'A', 'slug' => 'a', 'sort_order' => 1]);
        $this->plan(['name' => 'Restaurant', 'code' => 'B', 'slug' => 'b', 'sort_order' => 2]);
        $this->plan(['name' => 'Chain', 'code' => 'C', 'slug' => 'c', 'sort_order' => 3]);

        $response = $this->get('/')->assertOk();

        $response->assertSee('Most popular');
        // Exactly one, or the flag means nothing.
        $this->assertSame(1, substr_count($response->getContent(), 'Most popular'));
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
