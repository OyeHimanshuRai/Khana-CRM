<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentShop;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Direct messages: who can talk, who can read, and what is stored.
 */
class MessagingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $acme;

    private Tenant $rival;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');
        $this->seed();
        CurrentShop::forget();
        CurrentTenant::forget();

        $this->acme = Tenant::query()->orderBy('id')->firstOrFail();
        $this->rival = Tenant::query()->create([
            'name' => 'Rival Co', 'code' => 'RIV', 'slug' => Tenant::uniqueSlug('Rival Co'), 'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        CurrentShop::forget();
        CurrentTenant::forget();

        parent::tearDown();
    }

    private function person(Tenant $tenant, string $role = 'Tenant Owner'): User
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'is_admin' => true, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function as(User $user): static
    {
        CurrentTenant::forget();
        CurrentShop::forget();
        $this->actingAs($user);

        return $this;
    }

    private function talk(User $from, User $to): int
    {
        return $this->as($from)->postJson(route('admin.messages.start'), ['user_id' => $to->id])
            ->assertOk()->json('data.id');
    }

    public function test_colleagues_can_message_and_the_reply_is_delivered(): void
    {
        $a = $this->person($this->acme);
        $b = $this->person($this->acme);
        $id = $this->talk($a, $b);

        $this->postJson(route('admin.messages.send', $id), ['body' => 'Hello there', 'client_uuid' => 'abc-1'])
            ->assertCreated()->assertJsonPath('data.message.body', 'Hello there');

        $this->as($b)->getJson(route('admin.messages.show', $id))
            ->assertOk()->assertJsonPath('data.messages.0.body', 'Hello there');

        $this->as($b)->getJson(route('admin.messages.conversations'))
            ->assertJsonPath('data.conversations.0.with', $a->name);
    }

    public function test_the_same_pair_always_share_one_conversation(): void
    {
        $a = $this->person($this->acme);
        $b = $this->person($this->acme);

        $this->assertSame($this->talk($a, $b), $this->talk($b, $a));
        $this->assertSame(1, Conversation::query()->count());
    }

    public function test_a_retried_send_stores_the_message_once(): void
    {
        $a = $this->person($this->acme);
        $b = $this->person($this->acme);
        $id = $this->talk($a, $b);

        foreach ([1, 2, 3] as $_) {
            $this->postJson(route('admin.messages.send', $id), ['body' => 'Once', 'client_uuid' => 'same-uuid']);
        }

        $this->assertSame(1, Message::query()->count());
    }

    public function test_the_body_is_encrypted_in_the_database(): void
    {
        $a = $this->person($this->acme);
        $b = $this->person($this->acme);
        $id = $this->talk($a, $b);
        $this->postJson(route('admin.messages.send', $id), ['body' => 'top secret plan', 'client_uuid' => 'e-1']);

        $raw = DB::table('messages')->value('body');

        $this->assertStringNotContainsString('top secret', $raw);
        $this->assertSame('top secret plan', Message::query()->first()->plainBody());
    }

    public function test_someone_in_another_company_cannot_be_messaged(): void
    {
        $a = $this->person($this->acme);
        $stranger = $this->person($this->rival);

        $this->as($a)->postJson(route('admin.messages.start'), ['user_id' => $stranger->id])->assertNotFound();
        $this->getJson(route('admin.messages.contacts'))->assertJsonMissing(['id' => $stranger->id]);
        $this->assertSame(0, Conversation::query()->count());
    }

    public function test_an_outsider_cannot_read_or_write_someone_elses_thread(): void
    {
        $a = $this->person($this->acme);
        $b = $this->person($this->acme);
        $spy = $this->person($this->acme);
        $id = $this->talk($a, $b);
        $this->postJson(route('admin.messages.send', $id), ['body' => 'private', 'client_uuid' => 'p-1']);

        $this->as($spy)->getJson(route('admin.messages.show', $id))->assertNotFound();
        $this->postJson(route('admin.messages.send', $id), ['body' => 'injected', 'client_uuid' => 'x-1'])->assertNotFound();
        $this->assertSame(1, Message::query()->count());
    }

    public function test_even_a_super_admin_cannot_read_a_thread_they_are_not_in(): void
    {
        $a = $this->person($this->acme);
        $b = $this->person($this->acme);
        $id = $this->talk($a, $b);

        $root = User::query()->withoutGlobalScopes()->where("email", "admin@erp.test")->firstOrFail();

        $this->as($root)->getJson(route('admin.messages.show', $id))->assertNotFound();
    }

    public function test_the_platform_admin_can_message_a_restaurant(): void
    {
        $root = User::query()->where('email', 'admin@erp.test')->firstOrFail();
        $owner = $this->person($this->rival);

        $this->talk($root, $owner);
        $this->assertSame(1, Conversation::query()->count());
    }

    public function test_messages_are_validated_on_the_server(): void
    {
        $a = $this->person($this->acme);
        $b = $this->person($this->acme);
        $id = $this->talk($a, $b);

        $this->postJson(route('admin.messages.send', $id), ['body' => '   ', 'client_uuid' => 'v-1'])->assertStatus(422);
        $this->postJson(route('admin.messages.send', $id), ['body' => str_repeat('x', 2001), 'client_uuid' => 'v-2'])
            ->assertStatus(422)->assertJsonValidationErrors('body');
        $this->postJson(route('admin.messages.send', $id), ['body' => 'ok', 'client_uuid' => 'bad uuid!'])
            ->assertStatus(422)->assertJsonValidationErrors('client_uuid');
        $this->assertSame(0, Message::query()->count());
    }

    public function test_html_is_stored_as_text_and_returned_unchanged_for_the_client_to_escape(): void
    {
        $a = $this->person($this->acme);
        $b = $this->person($this->acme);
        $id = $this->talk($a, $b);

        $this->postJson(route('admin.messages.send', $id), ['body' => '<script>alert(1)</script>', 'client_uuid' => 'h-1'])
            ->assertCreated()->assertJsonPath('data.message.body', '<script>alert(1)</script>');
    }

    public function test_reading_marks_incoming_messages_read_and_the_sender_sees_it(): void
    {
        $a = $this->person($this->acme);
        $b = $this->person($this->acme);
        $id = $this->talk($a, $b);
        $this->postJson(route('admin.messages.send', $id), ['body' => 'ping', 'client_uuid' => 'r-1']);

        $this->as($b)->getJson(route('admin.messages.unread'))->assertJsonPath('data.unread', 1);
        $this->getJson(route('admin.messages.show', $id));
        $this->getJson(route('admin.messages.unread'))->assertJsonPath('data.unread', 0);

        $this->as($a)->getJson(route('admin.messages.show', $id))->assertJsonPath('data.peer_read_up_to', Message::query()->value('id'));
    }

    public function test_a_deactivated_person_can_no_longer_be_written_to(): void
    {
        $a = $this->person($this->acme);
        $b = $this->person($this->acme);
        $id = $this->talk($a, $b);

        $b->forceFill(['is_active' => false])->save();

        $this->as($a)->postJson(route('admin.messages.send', $id), ['body' => 'hi', 'client_uuid' => 'd-1'])->assertForbidden();
        $this->assertSame(0, Message::query()->count());
    }

    public function test_a_user_without_the_permission_is_refused_and_guests_are_turned_away(): void
    {
        $nobody = User::factory()->create(['tenant_id' => $this->acme->id, 'is_admin' => true, 'is_active' => true]);

        $this->as($nobody)->getJson(route('admin.messages.conversations'))->assertForbidden();
        $this->postJson(route('admin.messages.start'), ['user_id' => 1])->assertForbidden();

        $this->app['auth']->guard('web')->logout();
        $this->getJson(route('admin.messages.conversations'))->assertUnauthorized();
    }

    public function test_the_page_renders(): void
    {
        $this->as($this->person($this->acme))->get(route('admin.messages.index'))
            ->assertOk()->assertSee('Messages')->assertSee('messages.js', false);
    }
}
