<?php

namespace Tests\Feature;

use App\Models\Slider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class SliderTest extends TestCase
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

        $user->givePermissionTo('content.sliders.view');

        return $user;
    }

    private function slider(array $attributes = []): Slider
    {
        return Slider::create([
            'title' => 'Main Banner',
            'layout' => 'main_banner',
            'item_no' => 1,
            'is_active' => true,
            ...$attributes,
        ]);
    }

    /** Within the desktop slot's 800 x 400 ceiling. */
    private function image(string $name = 'slide.jpg', int $w = 800, int $h = 400): UploadedFile
    {
        return UploadedFile::fake()->image($name, $w, $h);
    }

    private function video(string $name = 'clip.mp4'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 500, 'video/mp4');
    }

    /* --------------------------------------------------------------- list */

    public function test_the_list_page_renders(): void
    {
        $this->slider(['title' => 'Gifting Guide', 'layout' => 'gifting_guide']);

        $this->actingAs($this->admin())
            ->get('/admin/sliders')
            ->assertOk()
            ->assertSee('Gifting Guide')
            ->assertSee('Add Slider');
    }

    public function test_fragments_carry_no_layout_or_scripts(): void
    {
        $slider = $this->slider();

        foreach ([
            '/admin/sliders',
            '/admin/sliders/create',
            "/admin/sliders/{$slider->id}",
            "/admin/sliders/{$slider->id}/edit",
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

    public function test_the_list_can_be_searched_and_filtered(): void
    {
        /*
         | Titles chosen not to collide with any layout label: every label is
         | rendered in the filter dropdown, so asserting a layout name is
         | absent would fail on the toolbar rather than the rows.
         */
        $this->slider(['title' => 'Handmade Story', 'layout' => 'crafting']);
        $this->slider(['title' => 'Ribbon Promo', 'layout' => 'top_strip_bar', 'is_active' => false]);

        $this->actingAs($this->admin())
            ->get('/admin/sliders?q=handmade')
            ->assertOk()
            ->assertSee('Handmade Story')
            ->assertDontSee('Ribbon Promo');

        $this->actingAs($this->admin())
            ->get('/admin/sliders?layout=top_strip_bar')
            ->assertOk()
            ->assertSee('Ribbon Promo')
            ->assertDontSee('Handmade Story');
    }

    /* -------------------------------------------------------------- write */

    public function test_a_slider_is_created(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/admin/sliders', [
                'title' => 'Enjoy the Moment',
                'description' => 'Hero copy.',
                'layout' => 'enjoy_the_moment',
                'redirect_url' => 'https://example.test/collection',
                'is_active' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('data.layout', 'Enjoy the Moment');

        $this->assertDatabaseHas('sliders', ['title' => 'Enjoy the Moment', 'layout' => 'enjoy_the_moment']);
        $this->assertDatabaseHas('activity_logs', ['event' => 'slider.created']);
    }

    public function test_a_blank_item_no_lands_at_the_end_of_its_layout(): void
    {
        $this->slider(['layout' => 'crafting', 'item_no' => 4]);

        $this->actingAs($this->admin())
            ->postJson('/admin/sliders', ['title' => 'Next', 'layout' => 'crafting'])
            ->assertOk()
            // Rather than colliding with whatever is at position 1.
            ->assertJsonPath('data.item_no', 5);
    }

    public function test_a_layout_outside_the_configured_list_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/admin/sliders', ['title' => 'Bad', 'layout' => 'not_a_layout'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['layout']]);
    }

    public function test_the_status_toggles(): void
    {
        $slider = $this->slider(['is_active' => true]);

        $this->actingAs($this->admin())
            ->putJson("/admin/sliders/{$slider->id}/status")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertFalse($slider->fresh()->is_active);
    }

    /* -------------------------------------------------------------- media */

    public function test_all_four_slots_upload_independently(): void
    {
        Storage::fake('public');
        $slider = $this->slider();

        $this->actingAs($this->admin())->post("/admin/sliders/{$slider->id}", [
            '_method' => 'PUT',
            'title' => $slider->title,
            'layout' => $slider->layout,
            'desktop_image' => $this->image(),
            'desktop_video' => $this->video(),
            'mobile_image' => $this->image('m.jpg', 400, 600),
            'mobile_video' => $this->video('m.mp4'),
        ], ['Accept' => 'application/json'])->assertOk();

        $fresh = $slider->fresh();

        foreach (array_keys(Slider::MEDIA) as $column) {
            $this->assertNotNull($fresh->{$column}, "{$column} was not stored");
            Storage::disk('public')->assertExists($fresh->{$column});
        }
    }

    public function test_deleting_a_slider_removes_all_four_files(): void
    {
        Storage::fake('public');
        $slider = $this->slider();

        $this->actingAs($this->admin())->post("/admin/sliders/{$slider->id}", [
            '_method' => 'PUT',
            'title' => $slider->title,
            'layout' => $slider->layout,
            'desktop_image' => $this->image(),
            'mobile_image' => $this->image('m.jpg', 400, 600),
        ], ['Accept' => 'application/json'])->assertOk();

        $paths = array_filter([
            $slider->fresh()->desktop_image_path,
            $slider->fresh()->mobile_image_path,
        ]);

        $this->actingAs($this->admin())
            ->deleteJson("/admin/sliders/{$slider->id}")
            ->assertOk();

        // Nothing left orphaned on disk.
        foreach ($paths as $path) {
            Storage::disk('public')->assertMissing($path);
        }
    }

    public function test_one_slot_can_be_cleared_without_touching_the_others(): void
    {
        Storage::fake('public');
        $slider = $this->slider();

        $this->actingAs($this->admin())->post("/admin/sliders/{$slider->id}", [
            '_method' => 'PUT',
            'title' => $slider->title,
            'layout' => $slider->layout,
            'desktop_image' => $this->image(),
            'mobile_image' => $this->image('m.jpg', 400, 600),
        ], ['Accept' => 'application/json'])->assertOk();

        $mobile = $slider->fresh()->mobile_image_path;

        $this->actingAs($this->admin())
            ->deleteJson("/admin/sliders/{$slider->id}/media/desktop_image")
            ->assertOk();

        $this->assertNull($slider->fresh()->desktop_image_path);
        // The other slot is untouched.
        $this->assertSame($mobile, $slider->fresh()->mobile_image_path);
        Storage::disk('public')->assertExists($mobile);
    }

    public function test_saving_without_choosing_files_keeps_the_stored_media(): void
    {
        Storage::fake('public');
        $slider = $this->slider();

        $this->actingAs($this->admin())->post("/admin/sliders/{$slider->id}", [
            '_method' => 'PUT',
            'title' => $slider->title,
            'layout' => $slider->layout,
            'desktop_image' => $this->image(),
        ], ['Accept' => 'application/json'])->assertOk();

        $path = $slider->fresh()->desktop_image_path;

        // Editing the title later must not silently clear the media.
        $this->actingAs($this->admin())
            ->putJson("/admin/sliders/{$slider->id}", [
                'title' => 'Renamed',
                'layout' => $slider->layout,
            ])
            ->assertOk();

        $this->assertSame($path, $slider->fresh()->desktop_image_path);
    }

    public function test_an_oversized_image_is_rejected_per_slot(): void
    {
        Storage::fake('public');

        /*
         | Sized against the slot's real ceiling, read from the model rather
         | than typed here.
         |
         | This test had 1200 x 600 in it against a limit of 800 x 400, and
         | then the desktop slot was widened to 2400 x 1200 - a deliberate
         | change, because a banner is drawn edge to edge and an 800px file
         | arrived soft on a 1920px screen. The test kept its old numbers,
         | started uploading a picture that was now perfectly legal, and had
         | been failing ever since. Deriving the size means the next change
         | to the ceiling cannot leave it stale again.
         */
        $slot = Slider::MEDIA['desktop_image_path'];

        $this->actingAs($this->admin())->post('/admin/sliders', [
            'title' => 'Too big',
            'layout' => 'main_banner',
            'desktop_image' => $this->image('big.jpg', $slot['max_width'] + 200, $slot['max_height'] + 200),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['desktop_image']]);

        $this->assertSame(0, Slider::count());
    }

    public function test_a_video_in_an_image_slot_is_rejected(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin())->post('/admin/sliders', [
            'title' => 'Wrong slot',
            'layout' => 'main_banner',
            'desktop_image' => $this->video(),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['desktop_image']]);
    }

    public function test_an_svg_carrying_script_is_refused(): void
    {
        Storage::fake('public');

        // An SVG is a document: served from our own origin it could run
        // script in a signed-in admin's session.
        $hostile = UploadedFile::fake()->createWithContent(
            'evil.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'
        );

        $this->actingAs($this->admin())->post('/admin/sliders', [
            'title' => 'Hostile',
            'layout' => 'main_banner',
            'desktop_image' => $hostile,
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['desktop_image']]);

        $this->assertSame(0, Slider::count());
    }

    public function test_a_plain_svg_is_accepted(): void
    {
        Storage::fake('public');

        $clean = UploadedFile::fake()->createWithContent(
            'logo.svg',
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10"/></svg>'
        );

        $this->actingAs($this->admin())->post('/admin/sliders', [
            'title' => 'Vector',
            'layout' => 'main_banner',
            'desktop_image' => $clean,
        ], ['Accept' => 'application/json'])->assertOk();

        $this->assertNotNull(Slider::firstOrFail()->desktop_image_path);
    }

    public function test_media_paths_are_not_mass_assignable(): void
    {
        $slider = $this->slider();

        $this->actingAs($this->admin())
            ->putJson("/admin/sliders/{$slider->id}", [
                'title' => $slider->title,
                'layout' => $slider->layout,
                'desktop_image_path' => '../../../etc/passwd',
            ])
            ->assertOk();

        $this->assertNull($slider->fresh()->desktop_image_path);
    }

    /* ---------------------------------------------------------- duplicate */

    public function test_duplicating_copies_the_files_rather_than_sharing_them(): void
    {
        Storage::fake('public');
        $slider = $this->slider();

        $this->actingAs($this->admin())->post("/admin/sliders/{$slider->id}", [
            '_method' => 'PUT',
            'title' => $slider->title,
            'layout' => $slider->layout,
            'desktop_image' => $this->image(),
        ], ['Accept' => 'application/json'])->assertOk();

        $original = $slider->fresh()->desktop_image_path;

        $this->actingAs($this->admin())
            ->postJson("/admin/sliders/{$slider->id}/duplicate")
            ->assertOk();

        $copy = Slider::where('id', '!=', $slider->id)->firstOrFail();

        $this->assertStringContainsString('(copy)', $copy->title);
        // A duplicate is a draft until someone says otherwise.
        $this->assertFalse($copy->is_active);

        // Separate file, so deleting either slide cannot break the other.
        $this->assertNotSame($original, $copy->desktop_image_path);
        Storage::disk('public')->assertExists($copy->desktop_image_path);

        $this->actingAs($this->admin())->deleteJson("/admin/sliders/{$slider->id}")->assertOk();
        Storage::disk('public')->assertExists($copy->fresh()->desktop_image_path);
    }

    /* --------------------------------------------------------- permissions */

    public function test_a_view_only_admin_cannot_write(): void
    {
        $viewer = $this->viewer();
        $slider = $this->slider();

        $this->actingAs($viewer)->get('/admin/sliders')->assertOk();
        $this->actingAs($viewer)->get("/admin/sliders/{$slider->id}")->assertOk();

        $this->actingAs($viewer)->get('/admin/sliders/create')->assertForbidden();
        $this->actingAs($viewer)
            ->postJson('/admin/sliders', ['title' => 'Nope', 'layout' => 'main_banner'])
            ->assertForbidden();
        $this->actingAs($viewer)->postJson("/admin/sliders/{$slider->id}/duplicate")->assertForbidden();
        $this->actingAs($viewer)->putJson("/admin/sliders/{$slider->id}/status")->assertForbidden();
        $this->actingAs($viewer)->deleteJson("/admin/sliders/{$slider->id}")->assertForbidden();
    }

    public function test_guests_are_redirected(): void
    {
        $this->get('/admin/sliders')->assertRedirect('http://localhost/admin/login');
    }
}
