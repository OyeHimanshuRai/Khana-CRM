<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/** What a search engine sees: robots.txt, the sitemap, the titles and the structured data. */
class SeoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');
        $this->seed();
    }

    public function test_robots_txt_allows_the_site_blocks_the_private_screens_and_names_the_sitemap(): void
    {
        $robots = $this->get('/robots.txt')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8')->getContent();

        $this->assertStringContainsString("User-agent: *\nAllow: /", $robots);
        $this->assertStringContainsString('Disallow: /superadmin/', $robots);
        $this->assertStringContainsString('Disallow: /login', $robots);
        $this->assertStringContainsString('Sitemap: http://localhost/sitemap.xml', $robots);
        // The public billing page is a page to index, not the payment area.
        $this->assertStringNotContainsString("Disallow: /billing\n", $robots);
    }

    public function test_the_sitemap_lists_the_public_pages_and_the_published_policies_only(): void
    {
        Page::create(['title' => 'Terms', 'slug' => 'terms-and-conditions', 'body' => 'x', 'is_active' => true, 'show_in_footer' => true]);
        Page::create(['title' => 'Draft', 'slug' => 'draft-page', 'body' => 'x', 'is_active' => false, 'show_in_footer' => false]);

        $xml = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->getContent();

        foreach (['http://localhost/', '/pricing', '/features', '/how-it-works', '/billing', '/faq', '/contact-support', '/register', '/p/terms-and-conditions'] as $path) {
            $this->assertStringContainsString('<loc>'.(str_starts_with($path, 'http') ? $path : 'http://localhost'.$path).'</loc>', $xml, $path);
        }

        $this->assertStringNotContainsString('draft-page', $xml);
        $this->assertStringNotContainsString('/login', $xml);
        $this->assertStringNotContainsString('/superadmin', $xml);
        $this->assertNotFalse(simplexml_load_string($xml), 'a well-formed document');
    }

    public function test_the_home_page_has_a_keyword_title_unless_the_owner_wrote_one_of_their_own(): void
    {
        Setting::put(['meta_title_home' => 'RestroOne']);
        $this->get('/')->assertOk()->assertSee('Restaurant POS Software, QR Ordering &amp; GST Billing', false);

        Setting::put(['meta_title_home' => 'Best Restaurant Billing Software in Jaipur | RestroOne']);
        $this->get('/')->assertOk()->assertSee('<title>Best Restaurant Billing Software in Jaipur | RestroOne</title>', false);
    }

    public function test_the_home_page_describes_the_organisation_the_website_and_the_software(): void
    {
        Setting::put(['phone' => '+91 90547 32126', 'company_email' => 'support@restroone.test']);

        $html = $this->get('/')->assertOk()->getContent();

        preg_match('/<script type="application\/ld\+json">(.*?)<\/script>/s', $html, $m);
        $graph = json_decode($m[1] ?? '', true);

        $this->assertSame('https://schema.org', $graph['@context']);
        $types = array_column($graph['@graph'], '@type');
        $this->assertContains('Organization', $types);
        $this->assertContains('WebSite', $types);
        $this->assertContains('SoftwareApplication', $types);

        $org = collect($graph['@graph'])->firstWhere('@type', 'Organization');
        $this->assertSame('+91 90547 32126', $org['contactPoint']['telephone']);
        $this->assertSame('support@restroone.test', $org['contactPoint']['email']);
    }

    public function test_inner_pages_carry_a_breadcrumb_trail(): void
    {
        foreach (['/pricing', '/features', '/how-it-works', '/billing', '/contact-support'] as $path) {
            $this->get($path)->assertOk()->assertSee('"@type":"BreadcrumbList"', false)->assertSee('"name":"Home"', false);
        }
    }

    public function test_the_search_console_and_bing_codes_are_added_when_set(): void
    {
        $this->get('/')->assertOk()->assertDontSee('google-site-verification', false);

        Setting::put(['google_site_verification' => 'abc123XYZ', 'bing_site_verification' => 'bing-456']);

        $this->get('/')->assertOk()
            ->assertSee('<meta name="google-site-verification" content="abc123XYZ">', false)
            ->assertSee('<meta name="msvalidate.01" content="bing-456">', false);
    }

    public function test_the_sign_up_page_may_be_indexed_but_the_sign_in_page_may_not(): void
    {
        $this->get('/register')->assertOk()->assertDontSee('noindex', false);
        $this->get('/login')->assertOk()->assertSee('<meta name="robots" content="noindex,nofollow">', false);
    }

    public function test_every_public_page_has_one_h1_a_title_and_a_canonical_address(): void
    {
        foreach (['/', '/pricing', '/features', '/how-it-works', '/billing', '/faq', '/contact-support', '/register'] as $path) {
            $html = $this->get($path)->assertOk()->getContent();

            $this->assertSame(1, substr_count($html, '<h1'), "$path has exactly one h1");
            $this->assertMatchesRegularExpression('/<title>.{15,}<\/title>/', $html, "$path has a real title");
            $this->assertStringContainsString('<link rel="canonical" href="http://localhost'.($path === '/' ? '/' : $path).'">', $html, $path);
            $this->assertStringContainsString('<meta name="description" content="', $html, $path);
        }
    }
}
