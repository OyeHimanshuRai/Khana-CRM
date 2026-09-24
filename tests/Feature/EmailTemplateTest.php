<?php

namespace Tests\Feature;

use App\Mail\EmailTemplateTestMail;
use App\Models\EmailTemplate;
use App\Models\User;
use App\Services\EmailTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailTemplateTest extends TestCase
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
    private function template(array $attributes = []): EmailTemplate
    {
        $fillable = array_flip([
            'name', 'slug', 'category', 'description',
            'subject', 'preheader', 'content', 'is_active',
        ]);

        $defaults = [
            'name' => 'Order Confirmation',
            'subject' => "What's new this month",
            'content' => '<p>Hello {{name}}, here is the news.</p>',
            'is_active' => true,
        ];

        $merged = array_merge($defaults, array_intersect_key($attributes, $fillable));
        $merged['slug'] ??= EmailTemplate::uniqueSlug($merged['name']);

        $template = new EmailTemplate($merged);

        $template->forceFill(array_merge(
            ['created_by_name' => 'Tester'],
            array_diff_key($attributes, $fillable),
        ))->save();

        return $template->refresh();
    }

    /* --------------------------------------------------------------- list */

    public function test_the_list_page_renders(): void
    {
        $this->template();

        $this->actingAs($this->admin())
            ->get('/admin/email/templates')
            ->assertOk()
            ->assertSee('Order Confirmation')
            ->assertSee('New Template');
    }

    public function test_fragments_carry_no_layout_or_scripts(): void
    {
        $template = $this->template();

        foreach ([
            '/admin/email/templates',
            '/admin/email/templates/create',
            "/admin/email/templates/{$template->id}",
            "/admin/email/templates/{$template->id}/edit",
            "/admin/email/templates/{$template->id}/test",
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
        $this->template(['name' => 'Spring Promo', 'category' => 'Promotions']);
        $this->template(['name' => 'Winter Notice', 'category' => 'Notices', 'is_active' => false]);

        $this->actingAs($this->admin())->get('/admin/email/templates?q=Spring')
            ->assertOk()->assertSee('Spring Promo')->assertDontSee('Winter Notice');

        $this->actingAs($this->admin())->get('/admin/email/templates?status=inactive')
            ->assertOk()->assertSee('Winter Notice')->assertDontSee('Spring Promo');

        $this->actingAs($this->admin())->get('/admin/email/templates?category=Promotions')
            ->assertOk()->assertSee('Spring Promo')->assertDontSee('Winter Notice');
    }

    /* ------------------------------------------------------------- writes */

    public function test_a_template_is_created_and_slugged(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/admin/email/templates', [
                'name' => 'Diwali Greeting',
                'subject' => 'Happy Diwali, {{name}}',
                'content' => '<p>From all of us at {{company}}.</p>',
                'is_active' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('data.slug', 'diwali-greeting');

        $template = EmailTemplate::firstOrFail();

        $this->assertNotNull($template->uuid);
        $this->assertSame($this->admin()->id, $template->created_by);
        $this->assertDatabaseHas('activity_logs', ['event' => 'email_template.created']);
    }

    public function test_a_duplicate_slug_gets_a_counter_rather_than_an_error(): void
    {
        $this->template(['name' => 'Repeat', 'slug' => 'repeat']);

        $this->actingAs($this->admin())
            ->postJson('/admin/email/templates', [
                'name' => 'Repeat',
                'subject' => 'Again',
            ])
            ->assertOk()
            ->assertJsonPath('data.slug', 'repeat-2');
    }

    public function test_a_slug_taken_by_another_template_is_refused(): void
    {
        $this->template(['name' => 'Taken', 'slug' => 'taken']);

        $this->actingAs($this->admin())
            ->postJson('/admin/email/templates', [
                'name' => 'Something else',
                'subject' => 'Hello',
                'slug' => 'taken',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['slug']]);
    }

    public function test_editing_without_touching_the_slug_keeps_it(): void
    {
        $template = $this->template(['name' => 'Original', 'slug' => 'original']);

        $this->actingAs($this->admin())
            ->putJson("/admin/email/templates/{$template->id}", [
                'name' => 'Renamed Entirely',
                'subject' => 'Still the same handle',
            ])
            ->assertOk()
            // Something may be calling this handle; a rename must not break it.
            ->assertJsonPath('data.slug', 'original');
    }

    public function test_the_status_toggles(): void
    {
        $template = $this->template(['is_active' => true]);

        $this->actingAs($this->admin())
            ->putJson("/admin/email/templates/{$template->id}/status")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertFalse($template->fresh()->is_active);
    }

    public function test_deleting_is_soft(): void
    {
        $template = $this->template();

        $this->actingAs($this->admin())
            ->deleteJson("/admin/email/templates/{$template->id}")
            ->assertOk();

        $this->assertSoftDeleted('email_templates', ['id' => $template->id]);
    }

    public function test_a_duplicate_starts_inactive(): void
    {
        $template = $this->template(['usage_count' => 12, 'last_used_at' => now()]);

        $this->actingAs($this->admin())
            ->postJson("/admin/email/templates/{$template->id}/duplicate")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $copy = EmailTemplate::where('name', 'Order Confirmation (copy)')->firstOrFail();

        $this->assertSame($template->content, $copy->content);
        $this->assertSame('order-confirmation-copy', $copy->slug);
        // A copy has not been used for anything yet.
        $this->assertSame(0, $copy->usage_count);
        $this->assertNull($copy->last_used_at);
    }

    /* ---------------------------------------------------------- variables */

    public function test_merge_tags_are_detected_from_the_content_on_save(): void
    {
        $template = $this->template([
            'subject' => 'Hello {{name}}',
            'content' => '<p>From {{company}}. Reply to {{email}}.</p>',
        ]);

        $this->assertSame(
            ['{{name}}', '{{email}}', '{{company}}'],
            $template->variables,
        );

        // Derived, not declared - editing the body updates the list.
        $template->update(['content' => '<p>Nothing personal here.</p>', 'subject' => 'Plain']);

        $this->assertSame([], $template->fresh()->variables);
    }

    /* ------------------------------------------------------------ preview */

    public function test_the_preview_renders_the_real_email(): void
    {
        $template = $this->template([
            'content' => '<p>Hello {{name}}, from {{company}}.</p>',
        ]);

        $response = $this->actingAs($this->admin())
            ->get("/admin/email/templates/{$template->id}/preview");

        $response->assertOk()->assertHeader('Content-Type', 'text/html; charset=UTF-8');

        $html = $response->getContent();

        // Sample values in the placeholders, wrapped in a document - the same
        // string a test send puts in the message body.
        $this->assertStringContainsString('Sample Recipient', $html);
        $this->assertStringNotContainsString('{{name}}', $html);
        $this->assertStringContainsString('<html', $html);
    }

    public function test_the_preview_is_sandboxed_rather_than_inlined(): void
    {
        // The body is operator HTML. Inlining it in the admin DOM would let
        // whoever wrote it run script in the session of everyone who looks.
        $template = $this->template([
            'content' => '<p>Harmless</p><script>alert(1)</script>',
        ]);

        $this->actingAs($this->admin())
            ->get("/admin/email/templates/{$template->id}")
            ->assertOk()
            ->assertSee('sandbox', false)
            ->assertSee("/templates/{$template->id}/preview", false)
            // The body itself never reaches the admin document.
            ->assertDontSee('alert(1)', false);
    }

    /* --------------------------------------------------------------- test */

    public function test_a_test_send_goes_out_marked_as_a_test(): void
    {
        Mail::fake();

        $template = $this->template();

        $this->actingAs($this->admin())
            ->postJson("/admin/email/templates/{$template->id}/test", ['email' => 'me@example.test'])
            ->assertOk();

        Mail::assertSent(
            EmailTemplateTestMail::class,
            fn (EmailTemplateTestMail $mail) => $mail->hasTo('me@example.test')
                && str_starts_with($mail->envelope()->subject, '[Test]'),
        );

        $this->assertDatabaseHas('activity_logs', ['event' => 'email_template.tested']);
    }

    public function test_a_test_send_defaults_to_the_operators_own_address(): void
    {
        Mail::fake();

        $template = $this->template();

        $this->actingAs($this->admin())
            ->postJson("/admin/email/templates/{$template->id}/test", [])
            ->assertOk();

        $admin = $this->admin();

        Mail::assertSent(
            EmailTemplateTestMail::class,
            fn (EmailTemplateTestMail $mail) => $mail->hasTo($admin->email),
        );
    }

    public function test_an_empty_template_cannot_be_tested(): void
    {
        Mail::fake();

        $template = $this->template(['content' => null]);

        $this->actingAs($this->admin())
            ->postJson("/admin/email/templates/{$template->id}/test", ['email' => 'me@example.test'])
            ->assertStatus(422);

        Mail::assertNothingSent();
    }

    public function test_the_content_endpoint_feeds_the_picker(): void
    {
        $template = $this->template();

        $this->actingAs($this->admin())
            ->getJson("/admin/email/templates/{$template->id}/content")
            ->assertOk()
            ->assertJsonPath('data.subject', $template->subject)
            ->assertJsonPath('data.content', $template->content);
    }

    /* --------------------------------------------------------- permissions */

    public function test_a_view_only_admin_cannot_write(): void
    {
        $viewer = $this->operator('viewer@example.test', ['email.templates.view']);
        $template = $this->template();

        $this->actingAs($viewer)->get('/admin/email/templates')->assertOk();
        $this->actingAs($viewer)->get("/admin/email/templates/{$template->id}")->assertOk();
        $this->actingAs($viewer)->get("/admin/email/templates/{$template->id}/preview")->assertOk();

        $this->actingAs($viewer)->get('/admin/email/templates/create')->assertForbidden();
        $this->actingAs($viewer)
            ->postJson('/admin/email/templates', ['name' => 'Nope', 'subject' => 'Nope'])
            ->assertForbidden();
        $this->actingAs($viewer)
            ->putJson("/admin/email/templates/{$template->id}", ['name' => 'Nope', 'subject' => 'Nope'])
            ->assertForbidden();
        $this->actingAs($viewer)
            ->putJson("/admin/email/templates/{$template->id}/status")
            ->assertForbidden();
        $this->actingAs($viewer)
            ->deleteJson("/admin/email/templates/{$template->id}")
            ->assertForbidden();

        $this->assertSame('Order Confirmation', $template->fresh()->name);
    }

    public function test_guests_are_redirected(): void
    {
        $this->get('/admin/email/templates')->assertRedirect('http://localhost/admin/login');
    }

    /* ------------------------------------------------------------ service */

    public function test_the_preview_and_a_real_send_share_one_rendering_path(): void
    {
        $template = $this->template(['content' => '<p>Hello {{name}}</p>']);

        $preview = app(EmailTemplateService::class)->preview($template);

        // Both go through render(), so a preview cannot drift away from what
        // a recipient actually gets.
        $this->assertStringContainsString('Hello Sample Recipient', $preview);
        // The whole document, wrapper included - not just the body fragment.
        $this->assertStringContainsString('<html', $preview);
    }
}
