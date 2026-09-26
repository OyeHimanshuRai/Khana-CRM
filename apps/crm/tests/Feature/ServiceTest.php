<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class ServiceTest extends TestCase
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

        $user->givePermissionTo('content.services.view');

        return $user;
    }

    private function service(array $attributes = []): Service
    {
        return Service::create([
            'name' => 'Private Dining',
            'slug' => 'private-dining',
            'is_active' => true,
            'sort_order' => 0,
            ...$attributes,
        ]);
    }

    private function image(string $name = 'svc.jpg', int $w = 600, int $h = 400): UploadedFile
    {
        return UploadedFile::fake()->image($name, $w, $h);
    }

    /* --------------------------------------------------------------- list */

    public function test_the_list_page_renders(): void
    {
        $this->service(['name' => 'Ring Sizing', 'slug' => 'ring-sizing']);

        $this->actingAs($this->admin())
            ->get('/admin/services')
            ->assertOk()
            ->assertSee('Ring Sizing')
            ->assertSee('Add Service');
    }

    public function test_fragments_carry_no_layout_or_scripts(): void
    {
        $service = $this->service();

        foreach ([
            '/admin/services',
            '/admin/services/create',
            "/admin/services/{$service->id}",
            "/admin/services/{$service->id}/edit",
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
        $this->service(['name' => 'Gold Polishing', 'slug' => 'gold-polishing']);
        $this->service(['name' => 'Stone Setting', 'slug' => 'stone-setting']);
        $this->service(['name' => 'Retired Service', 'slug' => 'retired', 'is_active' => false]);

        $this->actingAs($this->admin())
            ->get('/admin/services?q=polish')
            ->assertOk()
            ->assertSee('Gold Polishing')
            ->assertDontSee('Stone Setting');

        $this->actingAs($this->admin())
            ->get('/admin/services?status=inactive')
            ->assertOk()
            ->assertSee('Retired Service')
            ->assertDontSee('Gold Polishing');
    }

    /* -------------------------------------------------------------- write */

    public function test_a_service_is_created(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/admin/services', [
                'name' => 'Custom Design',
                'short_description' => 'Made to order.',
                'full_description' => 'Long copy here.',
                'icon' => 'star',
                'price' => 4999,
                'price_from' => 1,
                'is_active' => 1,
                'sort_order' => 2,
            ])
            ->assertOk()
            ->assertJsonPath('data.slug', 'custom-design')
            ->assertJsonPath('data.price', 'From ₹4,999');

        $this->assertDatabaseHas('services', ['name' => 'Custom Design', 'icon' => 'star']);
        $this->assertDatabaseHas('activity_logs', ['event' => 'service.created']);
    }

    public function test_a_blank_slug_is_derived_and_kept_unique(): void
    {
        $this->service(['name' => 'Repairs', 'slug' => 'repairs']);

        $this->actingAs($this->admin())
            ->postJson('/admin/services', ['name' => 'Repairs'])
            ->assertOk()
            // Counter appended rather than failing on the unique index.
            ->assertJsonPath('data.slug', 'repairs-2');
    }

    public function test_an_edit_with_a_blank_slug_keeps_the_existing_one(): void
    {
        $service = $this->service(['slug' => 'private-dining']);

        $this->actingAs($this->admin())
            ->putJson("/admin/services/{$service->id}", [
                'name' => 'Private Dining & Catering',
                'slug' => '',
                'is_active' => 1,
            ])
            ->assertOk();

        // Anything linking to the old slug stays valid.
        $this->assertSame('private-dining', $service->fresh()->slug);
    }

    public function test_the_status_toggles(): void
    {
        $service = $this->service(['is_active' => true]);

        $this->actingAs($this->admin())
            ->putJson("/admin/services/{$service->id}/status")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertFalse($service->fresh()->is_active);
    }

    public function test_a_service_is_deleted_with_its_image(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin())->post('/admin/services', [
            'name' => 'Doomed',
            'image' => $this->image(),
        ], ['Accept' => 'application/json'])->assertOk();

        $service = Service::where('name', 'Doomed')->firstOrFail();
        $path = $service->image_path;

        $this->actingAs($this->admin())
            ->deleteJson("/admin/services/{$service->id}")
            ->assertOk();

        $this->assertDatabaseMissing('services', ['id' => $service->id]);
        // No orphan left behind on disk.
        Storage::disk('public')->assertMissing($path);
    }

    /* --------------------------------------------------------------- icon */

    public function test_an_icon_outside_the_set_is_rejected(): void
    {
        // A typo must not store a name the icon component renders blank.
        $this->actingAs($this->admin())
            ->postJson('/admin/services', ['name' => 'Typo', 'icon' => 'not-an-icon'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['icon']]);
    }

    public function test_every_configured_icon_is_accepted(): void
    {
        $names = array_keys(config('icons'));
        $this->assertNotEmpty($names);

        $this->actingAs($this->admin())
            ->postJson('/admin/services', ['name' => 'Iconic', 'icon' => $names[0]])
            ->assertOk();
    }

    /* -------------------------------------------------------------- price */

    public function test_the_price_follows_the_configured_currency(): void
    {
        $service = $this->service(['price' => 1500, 'price_from' => false]);
        $this->assertSame('₹1,500', $service->formattedPrice());

        Setting::put(['currency' => 'USD']);
        $this->assertSame('$1,500', $service->fresh()->formattedPrice());
    }

    public function test_a_service_with_no_price_says_so_rather_than_zero(): void
    {
        $this->assertNull($this->service(['price' => null])->formattedPrice());
    }

    public function test_a_real_decimal_keeps_its_paise(): void
    {
        // Trailing .00 is noise; a genuine decimal is not.
        $this->assertSame('₹1,500', $this->service(['price' => 1500.00])->formattedPrice());
        $this->assertSame('₹1,500.50', $this->service([
            'slug' => 'other', 'price' => 1500.50,
        ])->formattedPrice());
    }

    /* -------------------------------------------------------------- image */

    public function test_an_image_uploads_and_can_be_replaced_then_removed(): void
    {
        Storage::fake('public');
        $service = $this->service();

        $this->actingAs($this->admin())->post("/admin/services/{$service->id}", [
            '_method' => 'PUT',
            'name' => $service->name,
            'image' => $this->image('first.jpg'),
        ], ['Accept' => 'application/json'])->assertOk();

        $first = $service->fresh()->image_path;
        Storage::disk('public')->assertExists($first);

        $this->actingAs($this->admin())->post("/admin/services/{$service->id}", [
            '_method' => 'PUT',
            'name' => $service->name,
            'image' => $this->image('second.jpg'),
        ], ['Accept' => 'application/json'])->assertOk();

        $second = $service->fresh()->image_path;
        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);

        $this->actingAs($this->admin())
            ->deleteJson("/admin/services/{$service->id}/image")
            ->assertOk();

        $this->assertNull($service->fresh()->image_path);
        Storage::disk('public')->assertMissing($second);
        // The service itself survives.
        $this->assertDatabaseHas('services', ['id' => $service->id]);
    }

    public function test_saving_without_choosing_a_file_keeps_the_image(): void
    {
        Storage::fake('public');
        $service = $this->service();

        $this->actingAs($this->admin())->post("/admin/services/{$service->id}", [
            '_method' => 'PUT',
            'name' => $service->name,
            'image' => $this->image(),
        ], ['Accept' => 'application/json'])->assertOk();

        $path = $service->fresh()->image_path;

        $this->actingAs($this->admin())
            ->putJson("/admin/services/{$service->id}", ['name' => 'Renamed'])
            ->assertOk();

        $this->assertSame($path, $service->fresh()->image_path);
    }

    public function test_bad_uploads_are_rejected(): void
    {
        Storage::fake('public');
        $admin = $this->admin();

        // A renamed PHP file: mimes would pass on the extension, mimetypes
        // is what catches the real content type.
        $this->actingAs($admin)->post('/admin/services', [
            'name' => 'Sneaky',
            'image' => UploadedFile::fake()->create('payload.jpg', 12, 'text/x-php'),
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonStructure(['errors' => ['image']]);

        // 3 MB, over the 2 MB cap.
        $this->actingAs($admin)->post('/admin/services', [
            'name' => 'Huge',
            'image' => $this->image()->size(3072),
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonStructure(['errors' => ['image']]);

        // Outside the dimension range.
        $this->actingAs($admin)->post('/admin/services', [
            'name' => 'Tiny',
            'image' => $this->image('tiny.jpg', 40, 40),
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonStructure(['errors' => ['image']]);

        $this->assertSame(0, Service::count());
    }

    public function test_image_path_is_not_mass_assignable(): void
    {
        $service = $this->service();

        $this->actingAs($this->admin())
            ->putJson("/admin/services/{$service->id}", [
                'name' => $service->name,
                'image_path' => '../../../etc/passwd',
            ])
            ->assertOk();

        $this->assertNull($service->fresh()->image_path);
    }

    /* --------------------------------------------------------- permissions */

    public function test_a_view_only_admin_cannot_write(): void
    {
        $viewer = $this->viewer();
        $service = $this->service();

        $this->actingAs($viewer)->get('/admin/services')->assertOk();
        $this->actingAs($viewer)->get("/admin/services/{$service->id}")->assertOk();

        $this->actingAs($viewer)->get('/admin/services/create')->assertForbidden();
        $this->actingAs($viewer)->postJson('/admin/services', ['name' => 'Nope'])->assertForbidden();
        $this->actingAs($viewer)
            ->putJson("/admin/services/{$service->id}", ['name' => 'Nope'])
            ->assertForbidden();
        $this->actingAs($viewer)->putJson("/admin/services/{$service->id}/status")->assertForbidden();
        $this->actingAs($viewer)->deleteJson("/admin/services/{$service->id}")->assertForbidden();
    }

    public function test_guests_are_redirected(): void
    {
        $this->get('/admin/services')->assertRedirect('http://localhost/admin/login');
    }
}
