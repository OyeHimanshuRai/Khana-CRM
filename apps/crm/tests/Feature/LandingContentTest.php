<?php

namespace Tests\Feature;

use App\Models\DemoRequest;
use App\Models\Integration;
use App\Models\LandingStat;
use App\Models\OutletType;
use App\Models\Shop;
use App\Models\Showcase;
use App\Models\Testimonial;
use App\Models\User;
use App\Support\CurrentShop;
use App\Support\Modules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The six sections the landing page grew (§19).
 *
 * Three things are worth holding, and none of them is "the row saves".
 *
 * **Switched off is not deleted.** Every one of these lists is a marketing
 * decision somebody reverses: a testimonial from a customer who has since left
 * is not a mistake to erase. If `is_active` ever stopped hiding a row, the
 * page would advertise things nobody meant to advertise.
 *
 * **A counted statistic is counted.** That is the entire reason `source`
 * exists - see LandingStat - and the moment it silently falls back to a typed
 * number, the feature is a lie with extra steps.
 *
 * **The demo form is the till.** It is the only public write on this site, so
 * it has to accept a real enquiry that gives only a phone number, refuse one
 * that gives no way to reply at all, and quietly swallow a bot.
 */
class LandingContentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');

        CurrentShop::forget();
    }

    protected function tearDown(): void
    {
        CurrentShop::forget();

        parent::tearDown();
    }

    /* ---------------------------------------------------------- fixtures */

    /** @param array<int, string> $permissions */
    private function staff(array $permissions): User
    {
        $this->seed();

        $shop = Shop::query()->withoutGlobalScopes()->orderBy('id')->firstOrFail();

        $user = User::create([
            'tenant_id' => $shop->tenant_id,
            'name' => 'Marketing',
            'email' => 'marketing'.uniqid().'@example.test',
            'password' => 'marketing-password-1',
            'is_admin' => true,
        ]);

        $user->shops()->syncWithoutDetaching([$shop->id => ['is_default' => true]]);
        $user->forceFill(['current_shop_id' => $shop->id, 'all_shops_view' => false])->save();

        $user->givePermissionTo(array_merge(['dashboard.overview.view'], $permissions));

        return $user->fresh();
    }

    /* ------------------------------------------------ switched off hides */

    public function test_an_inactive_row_never_reaches_the_page(): void
    {
        Testimonial::create([
            'quote' => 'A quote that is still live.',
            'author_name' => 'Rahul Mehta',
            'is_active' => true,
        ]);

        Testimonial::create([
            'quote' => 'A quote from somebody who has left.',
            'author_name' => 'Departed Customer',
            'is_active' => false,
        ]);

        OutletType::create(['name' => 'Cloud kitchen', 'icon' => 'package', 'is_active' => true]);
        OutletType::create(['name' => 'Retired format', 'icon' => 'package', 'is_active' => false]);

        Integration::create(['name' => 'Razorpay', 'is_active' => true]);
        Integration::create(['name' => 'Old Gateway', 'is_active' => false]);

        $this->get('/')
            ->assertOk()
            ->assertSee('A quote that is still live.')
            ->assertSee('Cloud kitchen')
            ->assertSee('Razorpay')
            ->assertDontSee('A quote from somebody who has left.')
            ->assertDontSee('Retired format')
            ->assertDontSee('Old Gateway');
    }

    /** A section with no rows leaves no heading behind. */
    public function test_empty_sections_do_not_render_their_headings(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertDontSee('What restaurants say')
            ->assertDontSee('Works with what you already use')
            ->assertDontSee('Built for how you actually trade')
            ->assertDontSee('What it looks like');
    }

    /**
     * A screenshot with no file is kept but not shown.
     *
     * A screenshot section is its screenshots; rendering an empty frame is
     * worse than rendering nothing.
     */
    public function test_a_screenshot_with_no_image_is_skipped(): void
    {
        /*
         | A title that appears nowhere else on the page. "The kitchen display"
         | would have passed for the wrong reason - it is also the blurb on the
         | kitchen module, which the feature grid prints.
         */
        Showcase::create([
            'title' => 'Zzz Unuploaded Screen',
            'caption' => 'Every ticket routed to the station that cooks it.',
            'is_active' => true,
        ]);

        $this->get('/')
            ->assertOk()
            ->assertDontSee('What it looks like')
            ->assertDontSee('Zzz Unuploaded Screen');

        // ...but the row is still there to attach a file to.
        $this->assertDatabaseCount('showcases', 1);
    }

    /* -------------------------------------------------------------------
     | Trust numbers - the module, not the page.
     |
     | These two used to open `/` and look for the row under the headline.
     | The page stopped drawing it deliberately: a counted figure is honest,
     | and "1 Outlet running on it" is honest in the worst available way on a
     | young install. See the note in LandingController::__invoke(), which
     | also records the markup to re-assert against if it ever comes back.
     |
     | What they were really protecting is the counting itself, and that is
     | asserted here directly - no page, no round trip, nothing to go red the
     | next time a marketing decision is made about the hero.
     */

    /**
     * A counted statistic is counted, not read off the typed value.
     *
     * `value` is deliberately something obviously wrong: if display() ever
     * prints it, the source lookup has quietly stopped working.
     */
    public function test_a_counted_statistic_uses_the_real_figure(): void
    {
        $stat = new LandingStat([
            'label' => 'Modules included',
            'source' => 'modules',
            'value' => '999',
        ]);

        $this->assertTrue($stat->isLive());
        $this->assertSame(number_format(count(Modules::keys())), $stat->display());
    }

    public function test_a_typed_statistic_is_printed_as_written(): void
    {
        $stat = new LandingStat(['label' => 'Support', 'value' => '24/7']);

        $this->assertFalse($stat->isLive());
        $this->assertSame('24/7', $stat->display());
    }

    /**
     * An unknown source falls back rather than throwing.
     *
     * This runs on the public page, where an exception is a 500 for every
     * visitor. "I could not count that" has to be an answer.
     */
    public function test_an_unknown_source_falls_back_to_the_typed_value(): void
    {
        $stat = new LandingStat(['label' => 'Nonsense', 'source' => 'not_a_source', 'value' => 'fallback']);

        $this->assertFalse($stat->isLive());
        $this->assertSame('fallback', $stat->display());
    }

    /** And with nothing to fall back to, a dash rather than a blank. */
    public function test_a_statistic_with_nothing_to_show_prints_a_dash(): void
    {
        $stat = new LandingStat(['label' => 'Empty']);

        $this->assertSame('—', $stat->display());
    }

    /* --------------------------------------------------------- demo form */

    public function test_a_phone_number_alone_is_enough(): void
    {
        $this->post(route('demo-request.store'), [
            'name' => 'Rahul Mehta',
            'phone' => '9876543210',
            'business_name' => 'Spice Route',
            'city' => 'Jaipur',
            'outlets' => 3,
        ])->assertRedirect(route('landing').'#demo');

        $this->assertDatabaseHas('demo_requests', [
            'name' => 'Rahul Mehta',
            'phone' => '9876543210',
            'status' => DemoRequest::NEW,
        ]);
    }

    public function test_an_email_alone_is_enough(): void
    {
        $this->post(route('demo-request.store'), [
            'name' => 'Aditi Rao',
            'email' => 'aditi@example.test',
        ])->assertRedirect();

        $this->assertDatabaseHas('demo_requests', ['email' => 'aditi@example.test']);
    }

    /** No way to reply is the one thing an enquiry cannot be. */
    public function test_neither_a_phone_nor_an_email_is_refused(): void
    {
        $this->post(route('demo-request.store'), ['name' => 'Anonymous'])
            ->assertSessionHasErrors(['email', 'phone']);

        $this->assertDatabaseCount('demo_requests', 0);
    }

    /**
     * The honeypot accepts and discards.
     *
     * Telling a bot it failed only teaches whoever wrote it to leave the field
     * blank next time, so the response is indistinguishable from success.
     */
    public function test_the_honeypot_swallows_a_bot(): void
    {
        $this->post(route('demo-request.store'), [
            'name' => 'Spam Bot',
            'phone' => '1111111111',
            'website' => 'http://spam.example',
        ])->assertRedirect(route('landing').'#demo');

        $this->assertDatabaseCount('demo_requests', 0);
    }

    /** The enquirer's IP is recorded, for abuse rather than for marketing. */
    public function test_the_ip_is_recorded(): void
    {
        $this->post(route('demo-request.store'), ['name' => 'Rahul', 'phone' => '9876543210']);

        $this->assertNotNull(DemoRequest::query()->firstOrFail()->ip_address);
    }

    /* ------------------------------------------------------------- admin */

    public function test_the_admin_screens_open(): void
    {
        $user = $this->staff([
            'content.testimonials.view',
            'content.outlet_types.view',
            'content.integrations.view',
            'content.showcases.view',
            'content.stats.view',
            'content.demo_requests.view',
        ]);

        foreach ([
            'admin.testimonials.index',
            'admin.outlet-types.index',
            'admin.integrations.index',
            'admin.showcases.index',
            'admin.landing-stats.index',
            'admin.demo-requests.index',
        ] as $route) {
            $this->actingAs($user)->get(route($route))->assertOk();
        }
    }

    public function test_a_screen_is_closed_without_its_own_right(): void
    {
        // Holds every landing right except testimonials.
        $user = $this->staff(['content.outlet_types.view', 'content.stats.view']);

        $this->actingAs($user)->get(route('admin.testimonials.index'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.demo-requests.index'))->assertForbidden();
    }

    public function test_an_admin_can_add_a_testimonial(): void
    {
        $user = $this->staff(['content.testimonials.view', 'content.testimonials.create']);

        $this->actingAs($user)
            ->post(route('admin.testimonials.store'), [
                'quote' => 'The kitchen stopped losing tickets on day one.',
                'author_name' => 'Rahul Mehta',
                'author_role' => 'Owner',
                'company' => 'Spice Route',
                'rating' => 5,
                'is_active' => 1,
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('testimonials', ['author_name' => 'Rahul Mehta', 'rating' => 5]);

        $this->get('/')->assertOk()->assertSee('The kitchen stopped losing tickets on day one.');
    }

    /**
     * Hiding a row is a separate action from deleting it.
     *
     * The whole reason `is_active` exists rather than a soft delete.
     */
    public function test_hiding_a_row_keeps_it(): void
    {
        $user = $this->staff(['content.testimonials.view', 'content.testimonials.edit']);

        $row = Testimonial::create([
            'quote' => 'Still on file.',
            'author_name' => 'Imran Sheikh',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->put(route('admin.testimonials.status', $row->id))
            ->assertOk();

        $this->assertFalse($row->fresh()->is_active);
        $this->assertDatabaseCount('testimonials', 1);

        $this->get('/')->assertOk()->assertDontSee('Still on file.');
    }

    /**
     * The icon is constrained to the icon map.
     *
     * The icon component echoes its markup unescaped, so an arbitrary string
     * must never reach it.
     */
    public function test_an_unknown_icon_is_refused(): void
    {
        $user = $this->staff(['content.outlet_types.view', 'content.outlet_types.create']);

        /*
         | postJson, because that is how the modal form posts: app.js sends it
         | with fetch and an Accept: application/json header, so Laravel
         | answers 422 with field errors rather than redirecting back. A plain
         | post() here would assert against a code path the UI never takes.
         */
        $this->actingAs($user)
            ->postJson(route('admin.outlet-types.store'), [
                'name' => 'Sneaky',
                'icon' => '"><script>alert(1)</script>',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('icon');

        $this->assertDatabaseCount('outlet_types', 0);
    }

    /* ---------------------------------------------------- the demo inbox */

    public function test_the_inbox_opens_on_new_requests(): void
    {
        $user = $this->staff(['content.demo_requests.view']);

        DemoRequest::create(['name' => 'Waiting Caller', 'phone' => '1', 'status' => DemoRequest::NEW]);
        DemoRequest::create(['name' => 'Already Closed', 'phone' => '2', 'status' => DemoRequest::CLOSED]);

        $this->actingAs($user)
            ->get(route('admin.demo-requests.index'))
            ->assertOk()
            ->assertSee('Waiting Caller')
            // Defaulting to everything would bury the hour-old lead under
            // thirty that were closed last month.
            ->assertDontSee('Already Closed');
    }

    /** "All" has to be reachable, or the default is a trap. */
    public function test_the_inbox_can_show_everything(): void
    {
        $user = $this->staff(['content.demo_requests.view']);

        DemoRequest::create(['name' => 'Already Closed', 'phone' => '2', 'status' => DemoRequest::CLOSED]);

        $this->actingAs($user)
            ->get(route('admin.demo-requests.index', ['status' => '']))
            ->assertOk()
            ->assertSee('Already Closed');
    }

    /**
     * Whoever first moves a lead off "new" is recorded as having picked it up.
     */
    public function test_taking_a_lead_stamps_who_took_it(): void
    {
        $user = $this->staff(['content.demo_requests.view', 'content.demo_requests.edit']);

        $lead = DemoRequest::create(['name' => 'Rahul', 'phone' => '9876543210', 'status' => DemoRequest::NEW]);

        $this->actingAs($user)
            ->put(route('admin.demo-requests.update', $lead), [
                'status' => DemoRequest::CONTACTED,
                'note' => 'Rang Tuesday, wants a callback after 6pm.',
            ])
            ->assertOk();

        $fresh = $lead->fresh();

        $this->assertSame(DemoRequest::CONTACTED, $fresh->status);
        $this->assertSame($user->id, $fresh->handled_by);
        $this->assertNotNull($fresh->handled_at);
    }

    /** Nothing in the admin may rewrite what the enquirer typed. */
    public function test_the_inbox_cannot_edit_the_enquiry_itself(): void
    {
        $user = $this->staff(['content.demo_requests.view', 'content.demo_requests.edit']);

        $lead = DemoRequest::create([
            'name' => 'Rahul',
            'phone' => '9876543210',
            'message' => 'The original words.',
            'status' => DemoRequest::NEW,
        ]);

        $this->actingAs($user)
            ->put(route('admin.demo-requests.update', $lead), [
                'status' => DemoRequest::CONTACTED,
                'name' => 'Rewritten',
                'phone' => '0000000000',
                'message' => 'Something else entirely.',
            ])
            ->assertOk();

        $fresh = $lead->fresh();

        $this->assertSame('Rahul', $fresh->name);
        $this->assertSame('9876543210', $fresh->phone);
        $this->assertSame('The original words.', $fresh->message);
    }
}
