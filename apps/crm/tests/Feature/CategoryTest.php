<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class CategoryTest extends TestCase
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

    /** An admin who may look but not touch. */
    private function viewer(): User
    {
        $user = User::create([
            'name' => 'Read Only',
            'email' => 'viewer@example.test',
            'password' => 'viewer-password-1',
            'is_admin' => true,
        ]);

        $user->givePermissionTo('inventory.categories.view');

        return $user;
    }

    private function category(array $attributes = []): Category
    {
        return Category::create([
            'name' => 'Rings',
            'slug' => 'rings',
            'is_active' => true,
            'sort_order' => 0,
            ...$attributes,
        ]);
    }

    private function image(string $name = 'cat.jpg', int $w = 600, int $h = 400): UploadedFile
    {
        return UploadedFile::fake()->image($name, $w, $h);
    }

    /* --------------------------------------------------------------- list */

    public function test_the_list_page_renders(): void
    {
        $this->category(['name' => 'Necklaces', 'slug' => 'necklaces']);

        $this->actingAs($this->admin())
            ->get('/admin/categories')
            ->assertOk()
            ->assertSee('Necklaces')
            ->assertSee('Add Category');
    }

    public function test_a_fragment_request_returns_the_table_alone(): void
    {
        $this->category();

        $this->actingAs($this->admin())
            ->withHeader('X-Fragment', '1')
            ->get('/admin/categories')
            ->assertOk()
            ->assertSee('Rings')
            // No layout: ajax-list.js swaps this straight into the page.
            ->assertDontSee('<body', false)
            ->assertDontSee('class="sidebar"', false)
            // Scripts inside injected markup never execute, so there are none.
            ->assertDontSee('<script', false);
    }

    public function test_the_list_can_be_searched_and_filtered(): void
    {
        $this->category(['name' => 'Gold Rings', 'slug' => 'gold-rings']);
        $this->category(['name' => 'Silver Chains', 'slug' => 'silver-chains']);
        $this->category(['name' => 'Retired Line', 'slug' => 'retired-line', 'is_active' => false]);

        $this->actingAs($this->admin())
            ->get('/admin/categories?q=silver')
            ->assertOk()
            ->assertSee('Silver Chains')
            ->assertDontSee('Gold Rings');

        $this->actingAs($this->admin())
            ->get('/admin/categories?status=inactive')
            ->assertOk()
            ->assertSee('Retired Line')
            ->assertDontSee('Gold Rings');
    }

    public function test_the_add_and_edit_forms_come_back_as_bare_fragments(): void
    {
        $category = $this->category();

        $this->actingAs($this->admin())
            ->get('/admin/categories/create')
            ->assertOk()
            ->assertSee('Create category')
            ->assertDontSee('<body', false)
            ->assertDontSee('<script', false);

        $this->actingAs($this->admin())
            ->get("/admin/categories/{$category->id}/edit")
            ->assertOk()
            ->assertSee('Save changes')
            ->assertSee('rings')
            ->assertDontSee('<script', false);

        $this->actingAs($this->admin())
            ->get("/admin/categories/{$category->id}")
            ->assertOk()
            ->assertSee('Rings')
            ->assertDontSee('<script', false);
    }

    /* -------------------------------------------------------------- write */

    public function test_a_category_is_created(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/admin/categories', [
                'name' => 'Bangles',
                'slug' => '',
                'description' => 'Everything that goes on a wrist.',
                'is_active' => 1,
                'sort_order' => 3,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.slug', 'bangles');

        $this->assertDatabaseHas('categories', ['name' => 'Bangles', 'slug' => 'bangles']);
        $this->assertDatabaseHas('activity_logs', ['event' => 'category.created']);
    }

    public function test_a_blank_slug_is_derived_and_kept_unique(): void
    {
        $this->category(['name' => 'Rings', 'slug' => 'rings']);

        $this->actingAs($this->admin())
            ->postJson('/admin/categories', ['name' => 'Rings'])
            ->assertOk()
            // Counter appended rather than failing on the unique index.
            ->assertJsonPath('data.slug', 'rings-2');
    }

    public function test_an_edit_with_a_blank_slug_keeps_the_existing_one(): void
    {
        $category = $this->category(['slug' => 'rings']);

        $this->actingAs($this->admin())
            ->putJson("/admin/categories/{$category->id}", [
                'name' => 'Rings & Bands',
                'slug' => '',
                'is_active' => 1,
            ])
            ->assertOk();

        // Anything linking to the old slug stays valid.
        $this->assertSame('rings', $category->fresh()->slug);
        $this->assertSame('Rings & Bands', $category->fresh()->name);
    }

    public function test_validation_errors_come_back_per_field(): void
    {
        $this->category(['slug' => 'taken']);

        $this->actingAs($this->admin())
            ->postJson('/admin/categories', [
                'name' => '',
                'slug' => 'taken',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['errors' => ['name', 'slug']]);
    }

    public function test_a_malformed_slug_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/admin/categories', ['name' => 'Rings', 'slug' => 'not a slug!'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['slug']]);
    }

    public function test_a_category_is_deleted_with_its_image(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin())->post('/admin/categories', [
            'name' => 'Doomed',
            'image' => $this->image(),
        ], ['Accept' => 'application/json'])->assertOk();

        $category = Category::where('name', 'Doomed')->firstOrFail();
        $path = $category->image_path;
        Storage::disk('public')->assertExists($path);

        $this->actingAs($this->admin())
            ->deleteJson("/admin/categories/{$category->id}")
            ->assertOk();

        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
        // No orphan left behind on disk.
        Storage::disk('public')->assertMissing($path);
    }

    /* ------------------------------------------------------------- status */

    public function test_the_status_toggles(): void
    {
        $category = $this->category(['is_active' => true]);

        $this->actingAs($this->admin())
            ->putJson("/admin/categories/{$category->id}/status")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertFalse($category->fresh()->is_active);

        $this->actingAs($this->admin())
            ->putJson("/admin/categories/{$category->id}/status")
            ->assertOk()
            ->assertJsonPath('data.is_active', true);

        $this->assertTrue($category->fresh()->is_active);
    }

    public function test_an_unticked_active_box_deactivates(): void
    {
        $category = $this->category(['is_active' => true]);

        // The form posts is_active=0 from its hidden field.
        $this->actingAs($this->admin())
            ->putJson("/admin/categories/{$category->id}", [
                'name' => $category->name,
                'is_active' => 0,
            ])
            ->assertOk();

        $this->assertFalse($category->fresh()->is_active);
    }

    /* -------------------------------------------------------------- image */

    public function test_an_image_uploads(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin())->post('/admin/categories', [
            'name' => 'Pendants',
            'image' => $this->image('pendant.png', 800, 600),
        ], ['Accept' => 'application/json'])->assertOk();

        $path = Category::where('name', 'Pendants')->firstOrFail()->image_path;

        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_replacing_an_image_deletes_the_previous_file(): void
    {
        Storage::fake('public');
        $category = $this->category();

        $this->actingAs($this->admin())->post("/admin/categories/{$category->id}", [
            '_method' => 'PUT',
            'name' => $category->name,
            'image' => $this->image('first.jpg'),
        ], ['Accept' => 'application/json'])->assertOk();

        $first = $category->fresh()->image_path;

        $this->actingAs($this->admin())->post("/admin/categories/{$category->id}", [
            '_method' => 'PUT',
            'name' => $category->name,
            'image' => $this->image('second.jpg'),
        ], ['Accept' => 'application/json'])->assertOk();

        $second = $category->fresh()->image_path;

        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
    }

    public function test_saving_without_choosing_a_file_keeps_the_image(): void
    {
        Storage::fake('public');
        $category = $this->category();

        $this->actingAs($this->admin())->post("/admin/categories/{$category->id}", [
            '_method' => 'PUT',
            'name' => $category->name,
            'image' => $this->image(),
        ], ['Accept' => 'application/json'])->assertOk();

        $path = $category->fresh()->image_path;

        // Editing the name later must not silently clear the image.
        $this->actingAs($this->admin())
            ->putJson("/admin/categories/{$category->id}", ['name' => 'Renamed'])
            ->assertOk();

        $this->assertSame($path, $category->fresh()->image_path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_the_image_can_be_removed_on_its_own(): void
    {
        Storage::fake('public');
        $category = $this->category();

        $this->actingAs($this->admin())->post("/admin/categories/{$category->id}", [
            '_method' => 'PUT',
            'name' => $category->name,
            'image' => $this->image(),
        ], ['Accept' => 'application/json'])->assertOk();

        $path = $category->fresh()->image_path;

        $this->actingAs($this->admin())
            ->deleteJson("/admin/categories/{$category->id}/image")
            ->assertOk();

        $this->assertNull($category->fresh()->image_path);
        Storage::disk('public')->assertMissing($path);
        // The category itself survives.
        $this->assertDatabaseHas('categories', ['id' => $category->id]);
    }

    public function test_a_non_image_is_rejected(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin())
            ->post('/admin/categories', [
                'name' => 'Sneaky',
                'image' => UploadedFile::fake()->create('payload.php', 12, 'text/php'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['image']]);

        $this->assertDatabaseMissing('categories', ['name' => 'Sneaky']);
    }

    public function test_a_php_file_renamed_to_jpg_is_still_rejected(): void
    {
        Storage::fake('public');

        // mimes alone would be fooled by the extension; mimetypes is what
        // catches the real content type.
        $this->actingAs($this->admin())
            ->post('/admin/categories', [
                'name' => 'Sneakier',
                'image' => UploadedFile::fake()->create('payload.jpg', 12, 'text/x-php'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['image']]);
    }

    public function test_an_oversized_image_is_rejected(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin())
            ->post('/admin/categories', [
                'name' => 'Huge',
                'image' => $this->image()->size(3072), // 3 MB, over the 2 MB cap
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['image']]);
    }

    public function test_images_outside_the_dimension_range_are_rejected(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin())
            ->post('/admin/categories', [
                'name' => 'Tiny',
                'image' => $this->image('tiny.jpg', 40, 40),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['image']]);

        $this->actingAs($this->admin())
            ->post('/admin/categories', [
                'name' => 'Enormous',
                'image' => $this->image('big.jpg', 5000, 5000),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['image']]);
    }

    public function test_image_path_is_not_mass_assignable(): void
    {
        $category = $this->category();

        $this->actingAs($this->admin())
            ->putJson("/admin/categories/{$category->id}", [
                'name' => $category->name,
                'image_path' => '../../../etc/passwd',
            ])
            ->assertOk();

        $this->assertNull($category->fresh()->image_path);
    }

    /* --------------------------------------------------------- permissions */

    public function test_a_view_only_admin_cannot_write(): void
    {
        $viewer = $this->viewer();
        $category = $this->category();

        $this->actingAs($viewer)->get('/admin/categories')->assertOk();
        $this->actingAs($viewer)->get("/admin/categories/{$category->id}")->assertOk();

        $this->actingAs($viewer)->get('/admin/categories/create')->assertForbidden();
        $this->actingAs($viewer)->postJson('/admin/categories', ['name' => 'Nope'])->assertForbidden();
        $this->actingAs($viewer)
            ->putJson("/admin/categories/{$category->id}", ['name' => 'Nope'])
            ->assertForbidden();
        $this->actingAs($viewer)->putJson("/admin/categories/{$category->id}/status")->assertForbidden();
        $this->actingAs($viewer)->deleteJson("/admin/categories/{$category->id}")->assertForbidden();
        $this->actingAs($viewer)->deleteJson("/admin/categories/{$category->id}/image")->assertForbidden();
    }

    public function test_guests_are_redirected(): void
    {
        $this->get('/admin/categories')->assertRedirect('http://localhost/admin/login');
    }
}
