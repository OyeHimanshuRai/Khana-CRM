<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\CollectionMedia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class CollectionTest extends TestCase
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

        $user->givePermissionTo('content.collections.view');

        return $user;
    }

    private function collection(array $attributes = []): Collection
    {
        return Collection::create([
            'name' => 'Bridal Edit',
            'slug' => 'bridal-edit',
            'is_active' => true,
            'sort_order' => 0,
            ...$attributes,
        ]);
    }

    private function image(string $name = 'art.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 1200, 800);
    }

    private function video(string $name = 'clip.mp4'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 500, 'video/mp4');
    }

    /* --------------------------------------------------------------- list */

    public function test_the_list_page_renders(): void
    {
        $this->collection(['name' => 'Festive Picks', 'slug' => 'festive-picks']);

        $this->actingAs($this->admin())
            ->get('/admin/collections')
            ->assertOk()
            ->assertSee('Festive Picks')
            ->assertSee('Add Collection');
    }

    public function test_fragments_carry_no_layout_or_scripts(): void
    {
        $collection = $this->collection();

        foreach ([
            '/admin/collections',
            '/admin/collections/create',
            "/admin/collections/{$collection->id}",
            "/admin/collections/{$collection->id}/edit",
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
        $this->collection(['name' => 'Gold Edit', 'slug' => 'gold-edit', 'is_featured' => true]);
        $this->collection(['name' => 'Silver Edit', 'slug' => 'silver-edit', 'is_featured' => false]);

        $this->actingAs($this->admin())
            ->get('/admin/collections?q=gold')
            ->assertOk()
            ->assertSee('Gold Edit')
            ->assertDontSee('Silver Edit');

        $this->actingAs($this->admin())
            ->get('/admin/collections?featured=yes')
            ->assertOk()
            ->assertSee('Gold Edit')
            ->assertDontSee('Silver Edit');
    }

    /* -------------------------------------------------------------- write */

    public function test_a_collection_is_created(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/admin/collections', [
                'name' => 'Heritage Line',
                'short_description' => 'Pieces from the archive.',
                'sort_order' => 2,
                'is_featured' => 1,
                'is_active' => 1,
                'meta_title' => 'Heritage Line | Tiara',
            ])
            ->assertOk()
            ->assertJsonPath('data.slug', 'heritage-line');

        $this->assertDatabaseHas('collections', ['name' => 'Heritage Line', 'is_featured' => true]);
        $this->assertDatabaseHas('activity_logs', ['event' => 'collection.created']);
    }

    public function test_a_blank_slug_is_derived_and_kept_unique(): void
    {
        $this->collection(['name' => 'Bridal', 'slug' => 'bridal']);

        $this->actingAs($this->admin())
            ->postJson('/admin/collections', ['name' => 'Bridal'])
            ->assertOk()
            // Counter appended rather than failing on the unique index.
            ->assertJsonPath('data.slug', 'bridal-2');
    }

    public function test_an_edit_with_a_blank_slug_keeps_the_existing_one(): void
    {
        $collection = $this->collection(['slug' => 'bridal-edit']);

        $this->actingAs($this->admin())
            ->putJson("/admin/collections/{$collection->id}", [
                'name' => 'Bridal Edit 2026',
                'slug' => '',
                'is_active' => 1,
            ])
            ->assertOk();

        // Anything linking to the old slug stays valid.
        $this->assertSame('bridal-edit', $collection->fresh()->slug);
    }

    public function test_both_toggles_work(): void
    {
        $collection = $this->collection(['is_active' => true, 'is_featured' => false]);

        $this->actingAs($this->admin())
            ->putJson("/admin/collections/{$collection->id}/status")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->actingAs($this->admin())
            ->putJson("/admin/collections/{$collection->id}/featured")
            ->assertOk()
            ->assertJsonPath('data.is_featured', true);

        $fresh = $collection->fresh();
        $this->assertFalse($fresh->is_active);
        $this->assertTrue($fresh->is_featured);
    }

    /* -------------------------------------------------------------- media */

    public function test_the_matrix_offers_every_device_and_position(): void
    {
        $slots = CollectionMedia::slots();

        // 2 devices x 3 positions.
        $this->assertCount(6, $slots);
        $this->assertContains('media_desktop_banner', array_column($slots, 'field'));
        $this->assertContains('media_mobile_hover', array_column($slots, 'field'));
    }

    public function test_each_cell_uploads_independently(): void
    {
        Storage::fake('public');
        $collection = $this->collection();

        $this->actingAs($this->admin())->post("/admin/collections/{$collection->id}", [
            '_method' => 'PUT',
            'name' => $collection->name,
            'media_desktop_banner' => $this->image('banner.jpg'),
            'media_desktop_hover' => $this->video('hover.mp4'),
            'media_mobile_thumbnail' => $this->image('thumb.jpg'),
        ], ['Accept' => 'application/json'])->assertOk();

        $fresh = $collection->fresh()->load('media');
        $this->assertCount(3, $fresh->media);

        foreach ($fresh->media as $piece) {
            Storage::disk('public')->assertExists($piece->path);
        }
    }

    public function test_the_type_is_read_off_the_file_not_typed_by_hand(): void
    {
        Storage::fake('public');
        $collection = $this->collection();

        $this->actingAs($this->admin())->post("/admin/collections/{$collection->id}", [
            '_method' => 'PUT',
            'name' => $collection->name,
            'media_desktop_banner' => $this->image(),
            'media_desktop_hover' => $this->video(),
            // A hand-typed type must not be trusted over the real file.
            'type' => 'video',
        ], ['Accept' => 'application/json'])->assertOk();

        $fresh = $collection->fresh();
        $this->assertSame('image', $fresh->mediaAt('desktop', 'banner')->type);
        $this->assertSame('video', $fresh->mediaAt('desktop', 'hover')->type);
    }

    public function test_one_cell_holds_one_piece(): void
    {
        Storage::fake('public');
        $collection = $this->collection();

        $this->actingAs($this->admin())->post("/admin/collections/{$collection->id}", [
            '_method' => 'PUT',
            'name' => $collection->name,
            'media_desktop_banner' => $this->image('first.jpg'),
        ], ['Accept' => 'application/json'])->assertOk();

        $first = $collection->fresh()->mediaAt('desktop', 'banner')->path;

        $this->actingAs($this->admin())->post("/admin/collections/{$collection->id}", [
            '_method' => 'PUT',
            'name' => $collection->name,
            'media_desktop_banner' => $this->image('second.jpg'),
        ], ['Accept' => 'application/json'])->assertOk();

        $fresh = $collection->fresh()->load('media');

        // Replaced, not stacked - "the desktop banner" means one thing.
        $this->assertCount(1, $fresh->media);
        $this->assertNotSame($first, $fresh->mediaAt('desktop', 'banner')->path);
        Storage::disk('public')->assertMissing($first);
    }

    public function test_one_cell_can_be_cleared_without_touching_the_others(): void
    {
        Storage::fake('public');
        $collection = $this->collection();

        $this->actingAs($this->admin())->post("/admin/collections/{$collection->id}", [
            '_method' => 'PUT',
            'name' => $collection->name,
            'media_desktop_banner' => $this->image('a.jpg'),
            'media_mobile_banner' => $this->image('b.jpg'),
        ], ['Accept' => 'application/json'])->assertOk();

        $mobile = $collection->fresh()->mediaAt('mobile', 'banner')->path;

        $this->actingAs($this->admin())
            ->deleteJson("/admin/collections/{$collection->id}/media/desktop/banner")
            ->assertOk();

        $fresh = $collection->fresh()->load('media');
        $this->assertNull($fresh->mediaAt('desktop', 'banner'));
        $this->assertSame($mobile, $fresh->mediaAt('mobile', 'banner')->path);
        Storage::disk('public')->assertExists($mobile);
    }

    public function test_an_unknown_cell_is_a_404(): void
    {
        $collection = $this->collection();

        $this->actingAs($this->admin())
            ->deleteJson("/admin/collections/{$collection->id}/media/watch/banner")
            ->assertNotFound();

        $this->actingAs($this->admin())
            ->deleteJson("/admin/collections/{$collection->id}/media/desktop/sidebar")
            ->assertNotFound();
    }

    public function test_saving_without_choosing_files_keeps_the_media(): void
    {
        Storage::fake('public');
        $collection = $this->collection();

        $this->actingAs($this->admin())->post("/admin/collections/{$collection->id}", [
            '_method' => 'PUT',
            'name' => $collection->name,
            'media_desktop_banner' => $this->image(),
        ], ['Accept' => 'application/json'])->assertOk();

        $path = $collection->fresh()->mediaAt('desktop', 'banner')->path;

        // Editing the name later must not silently clear the media.
        $this->actingAs($this->admin())
            ->putJson("/admin/collections/{$collection->id}", ['name' => 'Renamed'])
            ->assertOk();

        $this->assertSame($path, $collection->fresh()->mediaAt('desktop', 'banner')->path);
    }

    public function test_deleting_a_collection_removes_all_its_media(): void
    {
        Storage::fake('public');
        $collection = $this->collection();

        $this->actingAs($this->admin())->post("/admin/collections/{$collection->id}", [
            '_method' => 'PUT',
            'name' => $collection->name,
            'media_desktop_banner' => $this->image('a.jpg'),
            'media_mobile_banner' => $this->image('b.jpg'),
        ], ['Accept' => 'application/json'])->assertOk();

        $paths = $collection->fresh()->load('media')->media->pluck('path')->all();
        $this->assertCount(2, $paths);

        $this->actingAs($this->admin())
            ->deleteJson("/admin/collections/{$collection->id}")
            ->assertOk();

        $this->assertDatabaseMissing('collections', ['id' => $collection->id]);
        // The rows cascade; the files have to be cleaned up explicitly.
        $this->assertSame(0, CollectionMedia::count());

        foreach ($paths as $path) {
            Storage::disk('public')->assertMissing($path);
        }
    }

    public function test_an_oversized_image_is_rejected_but_a_video_that_size_is_not(): void
    {
        Storage::fake('public');
        $collection = $this->collection();

        // 5 MB image: over the 4 MB image cap.
        $this->actingAs($this->admin())->post("/admin/collections/{$collection->id}", [
            '_method' => 'PUT',
            'name' => $collection->name,
            'media_desktop_banner' => UploadedFile::fake()->image('huge.jpg')->size(5120),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['media_desktop_banner']]);

        // The same size as a video is fine - the ceilings differ on purpose.
        $this->actingAs($this->admin())->post("/admin/collections/{$collection->id}", [
            '_method' => 'PUT',
            'name' => $collection->name,
            'media_desktop_banner' => UploadedFile::fake()->create('clip.mp4', 5120, 'video/mp4'),
        ], ['Accept' => 'application/json'])->assertOk();

        $this->assertSame('video', $collection->fresh()->mediaAt('desktop', 'banner')->type);
    }

    public function test_a_non_media_file_is_rejected(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin())->post('/admin/collections', [
            'name' => 'Sneaky',
            'media_desktop_banner' => UploadedFile::fake()->create('payload.jpg', 10, 'text/x-php'),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['media_desktop_banner']]);

        $this->assertSame(0, Collection::count());
    }

    public function test_the_thumbnail_prefers_a_desktop_image_over_a_video(): void
    {
        Storage::fake('public');
        $collection = $this->collection();

        $this->actingAs($this->admin())->post("/admin/collections/{$collection->id}", [
            '_method' => 'PUT',
            'name' => $collection->name,
            // A video makes no still without ffmpeg, so it is skipped.
            'media_desktop_banner' => $this->video(),
            'media_desktop_thumbnail' => $this->image('thumb.jpg'),
        ], ['Accept' => 'application/json'])->assertOk();

        $fresh = $collection->fresh()->load('media');
        $expected = $fresh->mediaAt('desktop', 'thumbnail')->url();

        $this->assertSame($expected, $fresh->thumbnailUrl());
    }

    /* --------------------------------------------------------- permissions */

    public function test_a_view_only_admin_cannot_write(): void
    {
        $viewer = $this->viewer();
        $collection = $this->collection();

        $this->actingAs($viewer)->get('/admin/collections')->assertOk();
        $this->actingAs($viewer)->get("/admin/collections/{$collection->id}")->assertOk();

        $this->actingAs($viewer)->get('/admin/collections/create')->assertForbidden();
        $this->actingAs($viewer)->postJson('/admin/collections', ['name' => 'Nope'])->assertForbidden();
        $this->actingAs($viewer)
            ->putJson("/admin/collections/{$collection->id}", ['name' => 'Nope'])
            ->assertForbidden();
        $this->actingAs($viewer)->putJson("/admin/collections/{$collection->id}/status")->assertForbidden();
        $this->actingAs($viewer)->putJson("/admin/collections/{$collection->id}/featured")->assertForbidden();
        $this->actingAs($viewer)
            ->deleteJson("/admin/collections/{$collection->id}/media/desktop/banner")
            ->assertForbidden();
        $this->actingAs($viewer)->deleteJson("/admin/collections/{$collection->id}")->assertForbidden();
    }

    public function test_guests_are_redirected(): void
    {
        $this->get('/admin/collections')->assertRedirect('http://localhost/admin/login');
    }
}
