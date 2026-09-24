<?php

namespace Tests\Feature;

use App\Mail\WelcomeUserMail;
use App\Models\Setting;
use App\Models\User;
use App\Support\MailConfigurator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class WelcomeEmailTest extends TestCase
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

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function newUserPayload(array $overrides = []): array
    {
        return [
            'name' => 'Rahul Sharma',
            'email' => 'rahul@example.test',
            'password' => 'a-strong-password-1',
            'password_confirmation' => 'a-strong-password-1',
            'is_admin' => 1,
            'send_welcome' => 1,
            ...$overrides,
        ];
    }

    /* ------------------------------------------------------------ sending */

    public function test_creating_a_user_sends_the_welcome_email(): void
    {
        Mail::fake();

        $this->actingAs($this->admin())
            ->post('/admin/users', $this->newUserPayload())
            ->assertRedirect();

        $user = User::where('email', 'rahul@example.test')->firstOrFail();

        Mail::assertQueued(
            WelcomeUserMail::class,
            fn (WelcomeUserMail $mail) => $mail->hasTo('rahul@example.test')
                && $mail->user->is($user)
                && filled($mail->setPasswordUrl)
        );

        $this->assertDatabaseHas('activity_logs', ['event' => 'user.welcome_sent']);
    }

    public function test_the_email_is_skipped_when_the_box_is_unticked(): void
    {
        Mail::fake();

        $this->actingAs($this->admin())
            ->post('/admin/users', $this->newUserPayload(['send_welcome' => 0]))
            ->assertRedirect();

        Mail::assertNothingQueued();
        $this->assertDatabaseHas('users', ['email' => 'rahul@example.test']);
    }

    public function test_the_email_never_carries_the_password(): void
    {
        Mail::fake();

        $this->actingAs($this->admin())
            ->post('/admin/users', $this->newUserPayload(['password' => 'super-secret-abc1',
                'password_confirmation' => 'super-secret-abc1']))
            ->assertRedirect();

        Mail::assertQueued(WelcomeUserMail::class, function (WelcomeUserMail $mail) {
            // Mailboxes are searchable, forwardable and rarely encrypted at
            // rest - the recipient gets a link, never the password.
            $body = $mail->render();

            return ! str_contains($body, 'super-secret-abc1');
        });
    }

    public function test_a_failing_mailer_does_not_undo_the_account(): void
    {
        // Creating an account and telling someone about it are two jobs; an
        // unreachable SMTP server must not lose the first.
        Mail::shouldReceive('to->send')->andThrow(new \RuntimeException('smtp down'));

        $this->actingAs($this->admin())
            ->post('/admin/users', $this->newUserPayload())
            ->assertRedirect()
            ->assertSessionHas('warning');

        $this->assertDatabaseHas('users', ['email' => 'rahul@example.test']);
        $this->assertDatabaseHas('activity_logs', ['event' => 'user.welcome_failed']);
    }

    public function test_the_email_can_be_resent(): void
    {
        Mail::fake();
        $user = User::create([
            'name' => 'Resend Me',
            'email' => 'resend@example.test',
            'password' => 'password-1234',
            'is_admin' => true,
        ]);

        $this->actingAs($this->admin())
            ->postJson("/admin/users/{$user->id}/welcome")
            ->assertOk()
            ->assertJsonPath('success', true);

        Mail::assertQueued(WelcomeUserMail::class, fn ($mail) => $mail->hasTo('resend@example.test'));
    }

    public function test_a_deactivated_account_is_not_sent_one(): void
    {
        Mail::fake();
        $user = User::create([
            'name' => 'Dormant',
            'email' => 'dormant@example.test',
            'password' => 'password-1234',
            'is_admin' => true,
            'is_active' => false,
        ]);

        $this->actingAs($this->admin())
            ->postJson("/admin/users/{$user->id}/welcome")
            ->assertStatus(422);

        Mail::assertNothingQueued();
    }

    /* ------------------------------------------------------- set password */

    public function test_the_emailed_link_sets_a_password_and_works_once(): void
    {
        $user = User::create([
            'name' => 'New Joiner',
            'email' => 'joiner@example.test',
            'password' => 'temporary-1234',
            'is_admin' => true,
        ]);

        $token = Password::broker('welcome')->createToken($user);

        $this->get("/admin/password/set/{$token}?email={$user->email}")
            ->assertOk()
            ->assertSee('Set your password');

        $this->post('/admin/password/set', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'chosen-password-99',
            'password_confirmation' => 'chosen-password-99',
        ])->assertRedirect('http://localhost/admin/login');

        $this->assertTrue(Hash::check('chosen-password-99', $user->fresh()->password));
        $this->assertDatabaseHas('activity_logs', ['event' => 'user.password_set']);

        // One-time: the token is consumed, so a replay fails.
        $this->post('/admin/password/set', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'another-password-99',
            'password_confirmation' => 'another-password-99',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('chosen-password-99', $user->fresh()->password));
    }

    public function test_a_forged_token_is_refused(): void
    {
        $user = User::create([
            'name' => 'Target',
            'email' => 'target@example.test',
            'password' => 'original-password-1',
            'is_admin' => true,
        ]);

        $this->post('/admin/password/set', [
            'token' => 'not-a-real-token',
            'email' => $user->email,
            'password' => 'attacker-password-1',
            'password_confirmation' => 'attacker-password-1',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('original-password-1', $user->fresh()->password));
    }

    public function test_the_welcome_link_outlasts_the_reset_link(): void
    {
        // A welcome email may sit unread over a weekend; a forgotten-password
        // link is used within minutes.
        $this->assertGreaterThan(
            config('auth.passwords.users.expire'),
            config('auth.passwords.welcome.expire'),
        );
    }

    /* ----------------------------------------------------- mail settings */

    public function test_the_saved_mail_settings_are_applied(): void
    {
        Setting::put([
            'mail_mailer' => 'smtp',
            'mail_host' => 'smtp.example.test',
            'mail_port' => '2525',
            'mail_username' => 'someone@example.test',
            'mail_password' => 'a-secret',
            'mail_encryption' => 'tls',
            'mail_from_address' => 'noreply@example.test',
            'mail_from_name' => 'Example Co',
        ]);

        MailConfigurator::apply();

        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('smtp.example.test', config('mail.mailers.smtp.host'));
        $this->assertSame(2525, config('mail.mailers.smtp.port'));
        $this->assertSame('noreply@example.test', config('mail.from.address'));
        // Laravel 11+ calls it scheme, and "tls" means the plain smtp scheme.
        $this->assertSame('smtp', config('mail.mailers.smtp.scheme'));
    }

    public function test_ssl_maps_to_the_smtps_scheme(): void
    {
        Setting::put(['mail_mailer' => 'smtp', 'mail_encryption' => 'ssl']);

        MailConfigurator::apply();

        // The old "ssl" wording means implicit TLS, which Laravel spells smtps.
        $this->assertSame('smtps', config('mail.mailers.smtp.scheme'));
    }

    public function test_a_blank_setting_leaves_the_env_value_alone(): void
    {
        config(['mail.mailers.smtp.host' => 'from-env.example']);

        Setting::put(['mail_mailer' => 'smtp', 'mail_host' => '']);

        MailConfigurator::apply();

        $this->assertSame('from-env.example', config('mail.mailers.smtp.host'));
    }
}
