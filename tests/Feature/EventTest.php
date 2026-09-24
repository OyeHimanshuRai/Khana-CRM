<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EventTest extends TestCase
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

    private function viewer(): User
    {
        $user = User::create([
            'name' => 'Read Only',
            'email' => 'viewer@example.test',
            'password' => 'viewer-password-1',
            'is_admin' => true,
        ]);

        $user->givePermissionTo('content.events.view');

        return $user;
    }

    private function event(array $attributes = []): Event
    {
        return Event::create([
            'title' => 'Food Festival',
            'name' => 'Hall 3, Expo Centre',
            'timing' => '10:00 AM – 6:00 PM',
            'booth_no' => 'A-14',
            'is_active' => true,
            ...$attributes,
        ]);
    }

    /** Within the 800 x 400 ceiling. */
    private function image(string $name = 'event.jpg', int $w = 800, int $h = 400): UploadedFile
    {
        return UploadedFile::fake()->image($name, $w, $h);
    }

    /* --------------------------------------------------------------- list */

    public function test_the_list_page_renders(): void
    {
        $this->event();

        $this->actingAs($this->admin())
            ->get('/admin/events')
            ->assertOk()
            ->assertSee('Food Festival')
            ->assertSee('A-14')
            ->assertSee('Add Event');
    }

    public function test_fragments_carry_no_layout_or_scripts(): void
    {
        $event = $this->event();

        foreach ([
            '/admin/events',
            '/admin/events/create',
            "/admin/events/{$event->id}",
            "/admin/events/{$event->id}/edit",
        ] as $url) {
            $this->actingAs($this->admin())
                ->withHeader('X-Fragment', '1')
                ->get($url)
                ->assertOk()
                ->assertDontSee('<body', false)
                // Injected markup never runs its scripts, so there are none.
                ->assertDontSee('<script', false);
        }
    }

    public function test_the_list_can_be_searched(): void
    {
        $this->event(['title' => 'Spring Showcase', 'booth_no' => 'B-02']);
        $this->event(['title' => 'Winter Fair', 'booth_no' => 'C-09']);

        $this->actingAs($this->admin())
            ->get('/admin/events?q=B-02')
            ->assertOk()
            ->assertSee('Spring Showcase')
            ->assertDontSee('Winter Fair');
    }

    public function test_the_list_can_be_filtered_by_calendar_phase(): void
    {
        $this->event([
            'title' => 'Already Over',
            'from_date' => now()->subDays(10),
            'to_date' => now()->subDays(5),
        ]);
        $this->event([
            'title' => 'Still To Come',
            'from_date' => now()->addDays(5),
            'to_date' => now()->addDays(8),
        ]);
        $this->event([
            'title' => 'Happening Now',
            'from_date' => now()->subDay(),
            'to_date' => now()->addDay(),
        ]);

        $this->actingAs($this->admin())->get('/admin/events?phase=past')
            ->assertOk()->assertSee('Already Over')->assertDontSee('Still To Come');

        $this->actingAs($this->admin())->get('/admin/events?phase=upcoming')
            ->assertOk()->assertSee('Still To Come')->assertDontSee('Already Over');

        $this->actingAs($this->admin())->get('/admin/events?phase=running')
            ->assertOk()->assertSee('Happening Now')->assertDontSee('Already Over');
    }

    /* -------------------------------------------------------------- write */

    public function test_an_event_is_created(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/admin/events', [
                'title' => 'Bridal Week',
                'name' => 'Grand Ballroom',
                'timing' => '11am onwards',
                'from_date' => '2026-03-12',
                'to_date' => '2026-03-15',
                'booth_no' => 'D-21',
                'is_active' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('data.title', 'Bridal Week');

        $this->assertDatabaseHas('events', ['title' => 'Bridal Week', 'booth_no' => 'D-21']);
        $this->assertDatabaseHas('activity_logs', ['event' => 'event.created']);
    }

    public function test_an_end_date_before_the_start_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/admin/events', [
                'title' => 'Backwards',
                'from_date' => '2026-03-15',
                'to_date' => '2026-03-12',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['to_date']]);

        $this->assertSame(0, Event::count());
    }

    public function test_the_status_toggles(): void
    {
        $event = $this->event(['is_active' => true]);

        $this->actingAs($this->admin())
            ->putJson("/admin/events/{$event->id}/status")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertFalse($event->fresh()->is_active);
    }

    public function test_an_event_is_deleted_with_its_image(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin())->post('/admin/events', [
            'title' => 'Doomed',
            'image' => $this->image(),
        ], ['Accept' => 'application/json'])->assertOk();

        $event = Event::where('title', 'Doomed')->firstOrFail();
        $path = $event->image_path;
        Storage::disk('public')->assertExists($path);

        $this->actingAs($this->admin())
            ->deleteJson("/admin/events/{$event->id}")
            ->assertOk();

        $this->assertDatabaseMissing('events', ['id' => $event->id]);
        // No orphan left behind on disk.
        Storage::disk('public')->assertMissing($path);
    }

    /* --------------------------------------------------------- date range */

    public function test_the_date_range_collapses_what_the_dates_share(): void
    {
        $sameMonth = $this->event(['from_date' => '2026-03-12', 'to_date' => '2026-03-15']);
        $this->assertSame('12 – 15 Mar 2026', $sameMonth->dateRange());

        $sameYear = $this->event(['from_date' => '2026-03-30', 'to_date' => '2026-04-02']);
        $this->assertSame('30 Mar – 02 Apr 2026', $sameYear->dateRange());

        $oneDay = $this->event(['from_date' => '2026-03-12', 'to_date' => '2026-03-12']);
        $this->assertSame('12 Mar 2026', $oneDay->dateRange());

        $openEnded = $this->event(['from_date' => '2026-03-12', 'to_date' => null]);
        $this->assertSame('From 12 Mar 2026', $openEnded->dateRange());

        $undated = $this->event(['from_date' => null, 'to_date' => null]);
        $this->assertSame('—', $undated->dateRange());
    }

    public function test_the_phase_is_separate_from_the_active_switch(): void
    {
        // "Past" is the calendar; "Inactive" is a decision someone made.
        $past = $this->event([
            'from_date' => now()->subDays(10),
            'to_date' => now()->subDays(5),
            'is_active' => true,
        ]);

        $this->assertSame('past', $past->phase());
        $this->assertTrue($past->is_active);
    }

    /* -------------------------------------------------------------- image */

    public function test_an_image_uploads_and_can_be_replaced_then_removed(): void
    {
        Storage::fake('public');
        $event = $this->event();

        $this->actingAs($this->admin())->post("/admin/events/{$event->id}", [
            '_method' => 'PUT',
            'title' => $event->title,
            'image' => $this->image('first.jpg'),
        ], ['Accept' => 'application/json'])->assertOk();

        $first = $event->fresh()->image_path;
        Storage::disk('public')->assertExists($first);

        $this->actingAs($this->admin())->post("/admin/events/{$event->id}", [
            '_method' => 'PUT',
            'title' => $event->title,
            'image' => $this->image('second.jpg'),
        ], ['Accept' => 'application/json'])->assertOk();

        $second = $event->fresh()->image_path;
        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);

        $this->actingAs($this->admin())
            ->deleteJson("/admin/events/{$event->id}/image")
            ->assertOk();

        $this->assertNull($event->fresh()->image_path);
        Storage::disk('public')->assertMissing($second);
        // The event itself survives.
        $this->assertDatabaseHas('events', ['id' => $event->id]);
    }

    public function test_saving_without_choosing_a_file_keeps_the_image(): void
    {
        Storage::fake('public');
        $event = $this->event();

        $this->actingAs($this->admin())->post("/admin/events/{$event->id}", [
            '_method' => 'PUT',
            'title' => $event->title,
            'image' => $this->image(),
        ], ['Accept' => 'application/json'])->assertOk();

        $path = $event->fresh()->image_path;

        $this->actingAs($this->admin())
            ->putJson("/admin/events/{$event->id}", ['title' => 'Renamed'])
            ->assertOk();

        $this->assertSame($path, $event->fresh()->image_path);
    }

    public function test_an_oversized_image_is_rejected(): void
    {
        Storage::fake('public');

        // 1200 x 600 is over the 800 x 400 ceiling.
        $this->actingAs($this->admin())->post('/admin/events', [
            'title' => 'Too big',
            'image' => $this->image('big.jpg', 1200, 600),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['image']]);

        $this->assertSame(0, Event::count());
    }

    public function test_an_svg_carrying_script_is_refused(): void
    {
        Storage::fake('public');

        // An SVG is a document: served from our own origin it could run
        // script in a signed-in admin's session.
        $hostile = UploadedFile::fake()->createWithContent(
            'evil.svg',
            '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>'
        );

        $this->actingAs($this->admin())->post('/admin/events', [
            'title' => 'Hostile',
            'image' => $hostile,
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['image']]);

        $this->assertSame(0, Event::count());
    }

    public function test_a_plain_svg_is_accepted_and_skips_the_pixel_cap(): void
    {
        Storage::fake('public');

        // A vector is resolution independent, so the 800 x 400 limit does
        // not apply to it.
        $clean = UploadedFile::fake()->createWithContent(
            'logo.svg',
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 4000 4000"><rect width="10" height="10"/></svg>'
        );

        $this->actingAs($this->admin())->post('/admin/events', [
            'title' => 'Vector',
            'image' => $clean,
        ], ['Accept' => 'application/json'])->assertOk();

        $this->assertNotNull(Event::firstOrFail()->image_path);
    }

    public function test_image_path_is_not_mass_assignable(): void
    {
        $event = $this->event();

        $this->actingAs($this->admin())
            ->putJson("/admin/events/{$event->id}", [
                'title' => $event->title,
                'image_path' => '../../../etc/passwd',
            ])
            ->assertOk();

        $this->assertNull($event->fresh()->image_path);
    }

    /* --------------------------------------------------------- permissions */

    public function test_a_view_only_admin_cannot_write(): void
    {
        $viewer = $this->viewer();
        $event = $this->event();

        $this->actingAs($viewer)->get('/admin/events')->assertOk();
        $this->actingAs($viewer)->get("/admin/events/{$event->id}")->assertOk();

        $this->actingAs($viewer)->get('/admin/events/create')->assertForbidden();
        $this->actingAs($viewer)->postJson('/admin/events', ['title' => 'Nope'])->assertForbidden();
        $this->actingAs($viewer)
            ->putJson("/admin/events/{$event->id}", ['title' => 'Nope'])
            ->assertForbidden();
        $this->actingAs($viewer)->putJson("/admin/events/{$event->id}/status")->assertForbidden();
        $this->actingAs($viewer)->deleteJson("/admin/events/{$event->id}")->assertForbidden();
    }

    public function test_guests_are_redirected(): void
    {
        $this->get('/admin/events')->assertRedirect('http://localhost/admin/login');
    }
}
