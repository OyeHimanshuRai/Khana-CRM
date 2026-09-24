<?php

namespace Tests\Feature;

use App\Models\EmailLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The global outbound mail log.
 *
 * Note what is deliberately absent from these tests: Mail::fake(). Faking the
 * mailer swallows the MessageSending/MessageSent events the log is built on,
 * so these send through the `array` transport phpunit.xml configures and
 * assert on the rows that come out.
 */
class EmailLogTest extends TestCase
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
     * @param  array<int, string>  $permissions
     */
    private function operator(string $email, array $permissions): User
    {
        $user = User::create([
            'name' => 'Operator',
            'email' => $email,
            'password' => 'operator-password-1',
            'is_admin' => true,
        ]);

        $user->givePermissionTo($permissions);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function log(array $attributes = []): EmailLog
    {
        $log = new EmailLog();

        $log->forceFill(array_merge([
            'to_email' => 'rahul@example.test',
            'to_name' => 'Rahul Kumar',
            'subject' => 'Your account is ready',
            'from_email' => 'hello@example.com',
            'mailer' => 'array',
            'status' => EmailLog::SENT,
            'sent_at' => now(),
            'mailable' => \App\Mail\WelcomeUserMail::class,
        ], $attributes))->save();

        return $log->refresh();
    }

    /* ------------------------------------------------------------ capture */

    public function test_every_sent_email_is_logged_exactly_once(): void
    {
        Mail::raw('Hello there', function ($message) {
            $message->to('someone@example.test', 'Some One')->subject('A plain note');
        });

        // Exactly one: a second row would mean the listeners are registered
        // twice, which is the failure mode of adding event discovery later.
        $this->assertSame(1, EmailLog::count());

        $log = EmailLog::firstOrFail();

        $this->assertSame('someone@example.test', $log->to_email);
        $this->assertSame('Some One', $log->to_name);
        $this->assertSame('A plain note', $log->subject);
        $this->assertSame(EmailLog::SENT, $log->status);
        $this->assertNotNull($log->sent_at);
        $this->assertNotNull($log->uuid);
        // Mail::raw uses no mailable, so there is nothing to name.
        $this->assertNull($log->mailable);
    }

    public function test_a_welcome_email_is_logged_with_its_mailable(): void
    {
        $this->actingAs($this->admin())->post('/admin/users', [
            'name' => 'Rahul Kumar',
            'email' => 'rahul@example.test',
            'password' => 'rahul-password-99',
            'password_confirmation' => 'rahul-password-99',
            'is_admin' => 1,
            'send_welcome' => 1,
        ])->assertRedirect();

        $log = EmailLog::where('to_email', 'rahul@example.test')->firstOrFail();

        $this->assertSame(\App\Mail\WelcomeUserMail::class, $log->mailable);
        $this->assertSame(EmailLog::SENT, $log->status);
        $this->assertStringContainsString('account is ready', (string) $log->subject);
    }

    public function test_the_log_never_stores_the_message_body(): void
    {
        $this->actingAs($this->admin())->post('/admin/users', [
            'name' => 'Rahul Kumar',
            'email' => 'rahul@example.test',
            'password' => 'rahul-password-99',
            'password_confirmation' => 'rahul-password-99',
            'is_admin' => 1,
            'send_welcome' => 1,
        ])->assertRedirect();

        $log = EmailLog::where('to_email', 'rahul@example.test')->firstOrFail();

        /*
         | The welcome email carries a one-time password-set link. If any
         | column here held the rendered body, anyone with email.logs.view
         | could lift a working credential straight out of the log.
         */
        foreach ($log->getAttributes() as $column => $value) {
            $this->assertStringNotContainsString(
                'password/set',
                (string) $value,
                "The `{$column}` column is carrying the message body.",
            );
        }

        $this->assertArrayNotHasKey('body', $log->getAttributes());
    }

    public function test_a_failed_send_is_recorded_with_its_reason(): void
    {
        /*
         | Registered after the app's own listener, so the log row is opened
         | first and this throws on the way past - which is exactly the shape
         | of a real transport failure.
         */
        Event::listen(MessageSending::class, function () {
            throw new \RuntimeException('Connection refused by the mail server');
        });

        $this->actingAs($this->admin())->post('/admin/users', [
            'name' => 'Rahul Kumar',
            'email' => 'rahul@example.test',
            'password' => 'rahul-password-99',
            'password_confirmation' => 'rahul-password-99',
            'is_admin' => 1,
            'send_welcome' => 1,
        ])->assertRedirect();

        $log = EmailLog::where('to_email', 'rahul@example.test')->firstOrFail();

        // Laravel fires no event for a failed send, so this only works
        // because WelcomeMailer's catch block closes the row by hand.
        $this->assertSame(EmailLog::FAILED, $log->status);
        $this->assertStringContainsString('Connection refused', (string) $log->error);
        $this->assertNull($log->sent_at);

        // The account still exists: telling someone about it is a separate job.
        $this->assertDatabaseHas('users', ['email' => 'rahul@example.test']);
    }

    /* --------------------------------------------------------------- list */

    public function test_the_list_page_renders(): void
    {
        $this->log();

        $this->actingAs($this->admin())
            ->get('/admin/email/logs')
            ->assertOk()
            ->assertSee('rahul@example.test')
            ->assertSee('Your account is ready')
            ->assertSee('Email Logs');
    }

    public function test_fragments_carry_no_layout_or_scripts(): void
    {
        $log = $this->log();

        foreach (['/admin/email/logs', "/admin/email/logs/{$log->id}"] as $url) {
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
        $this->log(['to_email' => 'sent@example.test', 'subject' => 'Delivered fine']);
        $this->log([
            'to_email' => 'broken@example.test',
            'subject' => 'Never arrived',
            'status' => EmailLog::FAILED,
            'error' => 'Connection refused',
            'sent_at' => null,
        ]);

        $this->actingAs($this->admin())->get('/admin/email/logs?q=broken')
            ->assertOk()->assertSee('broken@example.test')->assertDontSee('sent@example.test');

        $this->actingAs($this->admin())->get('/admin/email/logs?status=failed')
            ->assertOk()->assertSee('Never arrived')->assertDontSee('Delivered fine');

        // Searching the failure text is how someone follows up on an outage.
        $this->actingAs($this->admin())->get('/admin/email/logs?q=Connection+refused')
            ->assertOk()->assertSee('broken@example.test');
    }

    public function test_the_detail_screen_shows_the_failure_reason(): void
    {
        $log = $this->log([
            'status' => EmailLog::FAILED,
            'error' => 'Expected response code 250 but got 535',
            'sent_at' => null,
        ]);

        $this->actingAs($this->admin())
            ->get("/admin/email/logs/{$log->id}")
            ->assertOk()
            ->assertSee('Expected response code 250 but got 535')
            ->assertSee('Mail Configuration');
    }

    /* ------------------------------------------------------------- export */

    public function test_the_export_follows_the_filters(): void
    {
        $this->log(['to_email' => 'kept@example.test']);
        $this->log([
            'to_email' => 'dropped@example.test',
            'status' => EmailLog::FAILED,
            'sent_at' => null,
        ]);

        $response = $this->actingAs($this->admin())->get('/admin/email/logs/export?status=sent');

        $response->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();

        $this->assertStringContainsString('kept@example.test', $csv);
        $this->assertStringNotContainsString('dropped@example.test', $csv);
        $this->assertDatabaseHas('activity_logs', ['event' => 'email_log.exported']);
    }

    /* -------------------------------------------------------------- prune */

    public function test_pruning_drops_only_what_is_past_the_retention(): void
    {
        $old = $this->log(['to_email' => 'ancient@example.test']);
        $old->forceFill(['created_at' => now()->subDays(120)])->save();

        $this->log(['to_email' => 'recent@example.test']);

        $this->actingAs($this->admin())
            ->deleteJson('/admin/email/logs', ['older_than_days' => 90])
            ->assertOk()
            ->assertJsonPath('data.deleted', 1);

        $this->assertDatabaseMissing('email_logs', ['to_email' => 'ancient@example.test']);
        $this->assertDatabaseHas('email_logs', ['to_email' => 'recent@example.test']);
        $this->assertDatabaseHas('activity_logs', ['event' => 'email_log.pruned']);
    }

    public function test_an_arbitrary_retention_is_refused(): void
    {
        $this->actingAs($this->admin())
            ->deleteJson('/admin/email/logs', ['older_than_days' => 1])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['older_than_days']]);
    }

    public function test_the_prune_command_clears_old_entries(): void
    {
        $old = $this->log(['to_email' => 'ancient@example.test']);
        $old->forceFill(['created_at' => now()->subDays(200)])->save();

        $this->log(['to_email' => 'recent@example.test']);

        $this->artisan('email:prune-logs --days=90')->assertSuccessful();

        $this->assertSame(1, EmailLog::count());
        $this->assertDatabaseHas('email_logs', ['to_email' => 'recent@example.test']);
    }

    public function test_the_prune_command_refuses_a_nonsense_window(): void
    {
        $this->log();

        $this->artisan('email:prune-logs --days=0')->assertFailed();

        $this->assertSame(1, EmailLog::count());
    }

    /* --------------------------------------------------------- permissions */

    public function test_a_view_only_admin_cannot_export_or_prune(): void
    {
        $viewer = $this->operator('viewer@example.test', ['email.logs.view']);
        $this->log();

        $this->actingAs($viewer)->get('/admin/email/logs')->assertOk();

        $this->actingAs($viewer)->get('/admin/email/logs/export')->assertForbidden();
        $this->actingAs($viewer)
            ->deleteJson('/admin/email/logs', ['older_than_days' => 90])
            ->assertForbidden();

        $this->assertSame(1, EmailLog::count());
    }

    public function test_the_log_is_not_editable_through_the_panel(): void
    {
        $log = $this->log();

        // There is no update route at all: the log is a record, and nothing
        // should be able to rewrite what was sent.
        $this->actingAs($this->admin())
            ->putJson("/admin/email/logs/{$log->id}", ['subject' => 'Rewritten'])
            ->assertStatus(405);

        $this->assertSame('Your account is ready', $log->fresh()->subject);
    }

    public function test_guests_are_redirected(): void
    {
        $this->get('/admin/email/logs')->assertRedirect('http://localhost/admin/login');
    }
}
