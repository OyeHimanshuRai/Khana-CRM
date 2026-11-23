<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\Shop;
use App\Models\User;
use App\Support\CurrentShop;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Admin-managed policy pages: public rendering, the footer, and who may edit.
 */
class SitePagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');
        $this->seed();

        CurrentShop::forget();
        CurrentTenant::forget();
    }

    protected function tearDown(): void
    {
        CurrentShop::forget();
        CurrentTenant::forget();

        parent::tearDown();
    }

    private function page(array $overrides = []): Page
    {
        return Page::create(array_merge([
            'title' => 'Privacy Policy',
            'slug' => 'privacy-policy',
            'body' => "## What we keep\n\nYour **orders**.",
            'is_active' => true,
            'show_in_footer' => true,
            'sort_order' => 0,
        ], $overrides));
    }

    private function superAdmin(): User
    {
        return User::query()->where('email', 'admin@erp.test')->firstOrFail();
    }

    private function tenantOwner(): User
    {
        $shop = Shop::query()->withoutGlobalScopes()->orderBy('id')->firstOrFail();

        $user = User::create([
            'tenant_id' => $shop->tenant_id,
            'name' => 'Restaurant Owner',
            'email' => 'owner'.uniqid().'@example.test',
            'password' => 'owner-password-1',
            'is_admin' => true,
        ]);

        $user->shops()->syncWithoutDetaching([$shop->id => ['is_default' => true]]);
        $user->forceFill(['current_shop_id' => $shop->id, 'all_shops_view' => false])->save();
        $user->assignRole('Tenant Owner');

        return $user->fresh();
    }

    /* --------------------------------------------------------------- public */

    public function test_a_published_page_renders_its_markdown(): void
    {
        $this->page();

        $this->get('/p/privacy-policy')
            ->assertOk()
            ->assertSee('Privacy Policy')
            ->assertSee('<meta name="description" content="What we keep Your orders.">', false)
            ->assertSee('<link rel="canonical" href="http://localhost/p/privacy-policy">', false)
            ->assertSee('<h2>What we keep</h2>', false)
            ->assertSee('<strong>orders</strong>', false);
    }

    public function test_an_empty_policy_page_still_gets_a_search_description(): void
    {
        $this->page(['title' => 'Refund Policy', 'slug' => 'refund-policy', 'body' => '']);

        $this->get('/p/refund-policy')
            ->assertOk()
            ->assertSee('<meta name="description" content="Read Refund Policy information from RestroOne.">', false);
    }

    public function test_html_and_script_in_the_body_are_not_rendered(): void
    {
        $this->page(['body' => "<script>alert(1)</script>\n\n[click](javascript:alert(1))\n\n<img src=x onerror=alert(2)>"]);

        $response = $this->get('/p/privacy-policy')->assertOk();

        $response->assertDontSee('<script>alert(1)</script>', false);
        $response->assertDontSee('href="javascript:', false);
        $response->assertDontSee('onerror=', false);
    }

    public function test_a_hidden_or_missing_page_is_not_found(): void
    {
        $this->page(['is_active' => false]);

        $this->get('/p/privacy-policy')->assertNotFound();
        $this->get('/p/nothing-here')->assertNotFound();
    }

    public function test_the_landing_footer_links_only_published_footer_pages(): void
    {
        $this->page();
        $this->page(['title' => 'Draft Terms', 'slug' => 'terms', 'is_active' => false]);
        $this->page(['title' => 'Hidden From Footer', 'slug' => 'about', 'show_in_footer' => false]);

        $this->get('/')
            ->assertOk()
            ->assertSee('/p/privacy-policy', false)
            ->assertDontSee('/p/terms', false)
            ->assertDontSee('/p/about', false);
    }

    /* ---------------------------------------------------------------- admin */

    public function test_super_admin_creates_a_page_and_the_address_comes_from_the_title(): void
    {
        $this->actingAs($this->superAdmin())
            ->postJson('/superadmin/pages', ['title' => 'Refund & Cancellation Policy', 'body' => 'Text', 'is_active' => 1])
            ->assertOk()
            ->assertJsonPath('data.slug', 'refund-cancellation-policy');

        $this->get('/p/refund-cancellation-policy')->assertOk();
    }

    public function test_duplicate_titles_get_distinct_addresses(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->postJson('/superadmin/pages', ['title' => 'Terms', 'body' => 'a'])->assertOk();
        $this->actingAs($admin)->postJson('/superadmin/pages', ['title' => 'Terms', 'body' => 'b'])
            ->assertOk()
            ->assertJsonPath('data.slug', 'terms-2');
    }

    public function test_a_bad_or_taken_address_is_rejected(): void
    {
        $this->page();

        $this->actingAs($this->superAdmin())
            ->postJson('/superadmin/pages', ['title' => 'X', 'body' => 'x', 'slug' => 'Has Spaces!'])
            ->assertStatus(422);

        $this->actingAs($this->superAdmin())
            ->postJson('/superadmin/pages', ['title' => 'X', 'body' => 'x', 'slug' => 'privacy-policy'])
            ->assertStatus(422);
    }

    public function test_editing_without_an_address_keeps_the_existing_one(): void
    {
        $page = $this->page();

        $this->actingAs($this->superAdmin())
            ->putJson('/superadmin/pages/'.$page->id, ['title' => 'Privacy & Data', 'body' => 'New text'])
            ->assertOk()
            ->assertJsonPath('data.slug', 'privacy-policy');

        $this->assertSame('Privacy & Data', $page->fresh()->title);
    }

    public function test_publishing_and_deleting(): void
    {
        $page = $this->page();
        $admin = $this->superAdmin();

        $this->actingAs($admin)->putJson('/superadmin/pages/'.$page->id.'/status')->assertOk();
        $this->assertFalse($page->fresh()->is_active);
        $this->get('/p/privacy-policy')->assertNotFound();

        $this->actingAs($admin)->deleteJson('/superadmin/pages/'.$page->id)->assertOk();
        $this->assertNull(Page::find($page->id));
    }

    public function test_the_list_page_renders_for_super_admin(): void
    {
        $this->page();

        $this->actingAs($this->superAdmin())
            ->get('/superadmin/pages')
            ->assertOk()
            ->assertSee('Privacy Policy');
    }

    public function test_a_tenant_owner_cannot_touch_the_platforms_pages(): void
    {
        $page = $this->page();
        $owner = $this->tenantOwner();

        $this->actingAs($owner)->get('/superadmin/pages')->assertForbidden();
        $this->actingAs($owner)->postJson('/superadmin/pages', ['title' => 'Evil', 'body' => 'x'])->assertForbidden();
        $this->actingAs($owner)->putJson('/superadmin/pages/'.$page->id, ['title' => 'Evil', 'body' => 'x'])->assertForbidden();
        $this->actingAs($owner)->deleteJson('/superadmin/pages/'.$page->id)->assertForbidden();

        $this->assertSame('Privacy Policy', $page->fresh()->title);
    }

    /* -------------------------------------------------- search + the policies */

    public function test_the_owner_sets_a_meta_title_description_and_keywords(): void
    {
        $page = $this->page();

        $this->actingAs($this->superAdmin())
            ->putJson('/superadmin/pages/'.$page->id, [
                'title' => 'Privacy Policy', 'body' => 'Text', 'is_active' => 1,
                'meta_title' => 'Privacy | RestroOne', 'meta_description' => 'How we keep your data.',
                'meta_keywords' => 'privacy, restaurant software',
            ])->assertOk();

        $this->assertSame('Privacy | RestroOne', $page->fresh()->meta_title);
        $this->assertSame('privacy, restaurant software', $page->fresh()->meta_keywords);

        $this->get('/p/privacy-policy')->assertOk()
            ->assertSee('<title>Privacy | RestroOne</title>', false)
            ->assertSee('<meta name="description" content="How we keep your data.">', false)
            ->assertSee('<meta name="keywords" content="privacy, restaurant software">', false);
    }

    public function test_a_page_without_a_meta_title_uses_its_title_and_the_site_name(): void
    {
        $this->page();

        $this->get('/p/privacy-policy')->assertOk()->assertSee('<title>Privacy Policy · ', false)
            ->assertDontSee('name="keywords"', false);
    }

    public function test_the_admin_form_has_the_search_fields(): void
    {
        $page = $this->page();

        $this->actingAs($this->superAdmin())->get('/superadmin/pages/'.$page->id.'/edit')->assertOk()
            ->assertSee('name="meta_title"', false)
            ->assertSee('name="meta_description"', false)
            ->assertSee('name="meta_keywords"', false);
    }

    public function test_the_three_policies_are_written_once_and_never_overwritten(): void
    {
        $this->assertSame(3, \App\Support\DefaultPages::publish());

        $terms = Page::query()->where('slug', 'terms-and-conditions')->firstOrFail();
        $this->assertStringContainsString('Termination', $terms->body);
        $this->assertNotEmpty($terms->meta_title);
        $this->assertNotEmpty($terms->meta_keywords);
        $this->assertTrue($terms->show_in_footer);
        $this->assertNotNull(Page::query()->where('slug', 'privacy-policy')->first());
        $this->assertNotNull(Page::query()->where('slug', 'refund-and-cancellation-policy')->first());

        // The owner edits it; running it again changes nothing.
        $terms->update(['body' => 'Edited by the owner']);
        $this->assertSame(0, \App\Support\DefaultPages::publish());
        $this->assertSame('Edited by the owner', $terms->fresh()->body);
    }

    public function test_the_terms_page_is_linked_from_the_sign_up_form_and_the_footer(): void
    {
        \App\Support\DefaultPages::publish();

        $this->get('/p/terms-and-conditions')->assertOk()->assertSee('Termination');
        $this->get('/register')->assertOk()
            ->assertSee('href="http://localhost/p/terms-and-conditions"', false)
            ->assertSee('href="http://localhost/p/privacy-policy"', false);
        $this->get('/')->assertOk()->assertSee('Terms and Conditions');
    }
}
