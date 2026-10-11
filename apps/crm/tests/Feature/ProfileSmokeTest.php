<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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
            ->assertSee('Signed-in devices');
    }

    public function test_fragment_request_returns_the_cards_without_page_chrome(): void
    {
        $response = $this->actingAs($this->actor())
            ->withHeader('X-Fragment', '1')
            ->get('/admin/profile');

        $response->assertOk()
            ->assertSee('Your details')
            ->assertSee('Signed-in devices')
            // No layout: the modal injects this straight into .modal-body.
            ->assertDontSee('<body', false)
            ->assertDontSee('class="sidebar"', false)
            // Scripts inside injected markup never execute, so there must be none.
            ->assertDontSee('<script', false);
    }

    /**
     * Name, email and password are Khana Accounts'. AccountsSignIn copies
     * the first two down at every sign-in, so an edit here would be quietly
     * undone - the screen shows them and links there instead.
     */
    public function test_details_and_password_are_changed_in_accounts_not_here(): void
    {
        config(['khana.urls.accounts' => 'https://accounts.example.test']);

        $user = $this->actor();

        $this->actingAs($user)
            ->get('/admin/profile')
            ->assertOk()
            ->assertSee($user->email)
            ->assertSee('href="https://accounts.example.test/profile"', false)
            ->assertDontSee('name="current_password"', false);

        $this->actingAs($user)
            ->putJson('/admin/profile', ['name' => 'Someone Else', 'email' => $user->email])
            ->assertMethodNotAllowed();

        $this->actingAs($user)
            ->putJson('/admin/profile/password', [])
            ->assertNotFound();

        $this->assertNotSame('Someone Else', $user->fresh()->name);
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

    /**
     * The upload endpoint is the only way to set the column - nothing that
     * fills a user from request data can point it at a file somebody chose.
     */
    public function test_avatar_is_not_mass_assignable(): void
    {
        $user = $this->actor();

        $user->fill([
            'name' => $user->name,
            'avatar_path' => 'avatars/injected.jpg',
        ])->save();

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
