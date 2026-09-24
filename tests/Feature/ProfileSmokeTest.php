<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class ProfileSmokeTest extends TestCase
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

    private function actor(): User
    {
        return User::where('is_admin', true)->firstOrFail();
    }

    public function test_profile_page_renders(): void
    {
        $this->actingAs($this->actor())
            ->get('/admin/profile')
            ->assertOk()
            ->assertSee('Your details')
            ->assertSee('Change password');
    }

    public function test_fragment_request_returns_the_cards_without_page_chrome(): void
    {
        $response = $this->actingAs($this->actor())
            ->withHeader('X-Fragment', '1')
            ->get('/admin/profile');

        $response->assertOk()
            ->assertSee('Your details')
            ->assertSee('Change password')
            // No layout: the modal injects this straight into .modal-body.
            ->assertDontSee('<body', false)
            ->assertDontSee('class="sidebar"', false)
            // Scripts inside injected markup never execute, so there must be none.
            ->assertDontSee('<script', false);
    }

    public function test_details_save_over_ajax(): void
    {
        $user = $this->actor();

        $response = $this->actingAs($user)
            ->putJson('/admin/profile', [
                'name' => 'Renamed Admin',
                'email' => 'renamed@example.test',
            ]);

        $response->assertOk()->assertJson([
            'success' => true,
            'message' => 'Profile updated.',
        ]);

        $this->assertSame('RA', $response->json('data.initials'));

        $fresh = $user->fresh();
        $this->assertSame('Renamed Admin', $fresh->name);
        $this->assertSame('renamed@example.test', $fresh->email);
    }

    public function test_unchanged_details_do_not_write_an_audit_row(): void
    {
        $user = $this->actor();

        $this->actingAs($user)
            ->putJson('/admin/profile', ['name' => $user->name, 'email' => $user->email])
            ->assertOk()
            ->assertJsonPath('message', 'No changes to save.');

        $this->assertDatabaseMissing('activity_logs', ['event' => 'profile.updated']);
    }

    public function test_duplicate_email_is_rejected_with_field_errors(): void
    {
        $user = $this->actor();
        $other = User::create([
            'name' => 'Someone Else',
            'email' => 'taken@example.test',
            'password' => 'whatever-1234',
            'is_admin' => true,
        ]);

        $this->actingAs($user)
            ->putJson('/admin/profile', ['name' => $user->name, 'email' => $other->email])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['errors' => ['email']]);
    }

    public function test_password_change_requires_the_current_password(): void
    {
        $this->actingAs($this->actor())
            ->putJson('/admin/profile/password', [
                'current_password' => 'definitely-not-it',
                'password' => 'brand-new-secret-1',
                'password_confirmation' => 'brand-new-secret-1',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['current_password']]);
    }

    public function test_password_changes_with_the_right_current_password(): void
    {
        $user = $this->actor();
        $user->forceFill(['password' => Hash::make('known-password-1')])->save();

        $this->actingAs($user->fresh())
            ->putJson('/admin/profile/password', [
                'current_password' => 'known-password-1',
                'password' => 'known-password-2',
                'password_confirmation' => 'known-password-2',
            ])
            ->assertOk()
            ->assertJson(['success' => true, 'message' => 'Password changed.']);

        $this->assertTrue(Hash::check('known-password-2', $user->fresh()->password));
        $this->assertDatabaseHas('activity_logs', ['event' => 'profile.password_changed']);
    }

    public function test_new_password_must_differ_from_the_current_one(): void
    {
        $user = $this->actor();
        $user->forceFill(['password' => Hash::make('known-password-1')])->save();

        $this->actingAs($user->fresh())
            ->putJson('/admin/profile/password', [
                'current_password' => 'known-password-1',
                'password' => 'known-password-1',
                'password_confirmation' => 'known-password-1',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['password']]);
    }

    public function test_guests_are_redirected(): void
    {
        $this->get('/admin/profile')->assertRedirect('http://localhost/admin/login');
    }

    /* ------------------------------------------------------------ avatar */

    public function test_avatar_uploads_and_is_returned_for_the_header(): void
    {
        Storage::fake('public');
        $user = $this->actor();

        $response = $this->actingAs($user)
            ->post('/admin/profile/avatar', [
                'avatar' => UploadedFile::fake()->image('me.jpg', 300, 300),
            ], ['Accept' => 'application/json']);

        $response->assertOk()->assertJson(['success' => true, 'message' => 'Picture updated.']);

        $path = $user->fresh()->avatar_path;
        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);

        // The header repaints itself from this, so it must come back non-null.
        $this->assertNotNull($response->json('data.avatar'));
        $this->assertDatabaseHas('activity_logs', ['event' => 'profile.avatar_updated']);
    }

    public function test_replacing_an_avatar_deletes_the_previous_file(): void
    {
        Storage::fake('public');
        $user = $this->actor();

        $this->actingAs($user)->post('/admin/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('first.jpg', 200, 200),
        ], ['Accept' => 'application/json'])->assertOk();

        $first = $user->fresh()->avatar_path;

        $this->actingAs($user)->post('/admin/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('second.jpg', 200, 200),
        ], ['Accept' => 'application/json'])->assertOk();

        $second = $user->fresh()->avatar_path;

        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
    }

    public function test_non_images_and_oversized_files_are_rejected(): void
    {
        Storage::fake('public');
        $user = $this->actor();

        $this->actingAs($user)->post('/admin/profile/avatar', [
            'avatar' => UploadedFile::fake()->create('resume.pdf', 40, 'application/pdf'),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['avatar']]);

        // 3 MB, over the 2 MB ceiling.
        $this->actingAs($user)->post('/admin/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('huge.jpg')->size(3072),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['avatar']]);

        $this->assertNull($user->fresh()->avatar_path);
    }

    public function test_avatar_can_be_removed(): void
    {
        Storage::fake('public');
        $user = $this->actor();

        $this->actingAs($user)->post('/admin/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('me.jpg', 200, 200),
        ], ['Accept' => 'application/json'])->assertOk();

        $path = $user->fresh()->avatar_path;

        $this->actingAs($user)
            ->deleteJson('/admin/profile/avatar')
            ->assertOk()
            ->assertJsonPath('message', 'Picture removed.')
            // Null tells the header to fall back to initials.
            ->assertJsonPath('data.avatar', null);

        $this->assertNull($user->fresh()->avatar_path);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_avatar_is_not_mass_assignable_through_the_user_form(): void
    {
        $user = $this->actor();

        $this->actingAs($user)->putJson('/admin/profile', [
            'name' => $user->name,
            'email' => $user->email,
            'avatar_path' => 'avatars/injected.jpg',
        ])->assertOk();

        $this->assertNull($user->fresh()->avatar_path);
    }

    public function test_a_missing_file_falls_back_to_initials(): void
    {
        Storage::fake('public');
        $user = $this->actor();

        // Row points at a file that is not on disk - a restored database, say.
        $user->forceFill(['avatar_path' => 'avatars/gone.jpg'])->save();

        $this->assertNull($user->fresh()->avatarUrl());
    }
}
