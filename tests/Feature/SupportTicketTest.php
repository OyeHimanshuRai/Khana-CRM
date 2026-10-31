<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\SupportTicket;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SupportTicketService;
use App\Support\CurrentShop;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The support desk (§2).
 *
 * Two things here are worth protecting, and they are not the CRUD.
 *
 * The first is the company boundary. One restaurant must never see another's
 * thread, and the only thing standing between them is
 * SupportTicket::scopeVisibleTo() plus the `manage` right that widens it. The
 * model carries no global scope, so a forgotten filter fails *open* - which is
 * the opposite of every other model in this codebase and the reason these
 * tests exist.
 *
 * The second is the internal note. A desk that writes "the owner is three
 * months in arrears, stall them" into a thread has to be certain the customer
 * cannot read it, and "the view does not render it" is not certain enough.
 */
class SupportTicketTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');

        $this->seed();

        CurrentShop::forget();
        CurrentTenant::forget();
    }

    protected function tearDown(): void
    {
        CurrentShop::forget();
        CurrentTenant::forget();

        parent::tearDown();
    }

    /* ---------------------------------------------------------- fixtures */

    private function shop(): Shop
    {
        return Shop::query()->withoutGlobalScopes()->orderBy('id')->firstOrFail();
    }

    /**
     * Somebody who works for a restaurant.
     *
     * @param  array<int, string>  $permissions
     */
    private function owner(array $permissions = ['support.tickets.view', 'support.tickets.create']): User
    {
        $shop = $this->shop();

        $user = User::create([
            'tenant_id' => $shop->tenant_id,
            'name' => 'Rahul Owner',
            'email' => 'owner'.uniqid().'@example.test',
            'password' => 'owner-password-1',
            'is_admin' => true,
        ]);

        $user->shops()->syncWithoutDetaching([$shop->id => ['is_default' => true]]);
        $user->forceFill([
            'current_shop_id' => $shop->id,
            'all_shops_view' => false,
        ])->save();

        $user->givePermissionTo(array_merge(['dashboard.overview.view'], $permissions));

        return $user->fresh();
    }

    /** Somebody who works the platform's support queue. */
    private function agent(): User
    {
        $shop = $this->shop();

        $user = User::create([
            'tenant_id' => $shop->tenant_id,
            'name' => 'Priya Support',
            'email' => 'agent'.uniqid().'@example.test',
            'password' => 'agent-password-1',
            'is_admin' => true,
        ]);

        $user->shops()->syncWithoutDetaching([$shop->id => ['is_default' => true]]);
        $user->forceFill([
            'current_shop_id' => $shop->id,
            'all_shops_view' => false,
        ])->save();

        $user->givePermissionTo([
            'dashboard.overview.view',
            'support.tickets.view',
            'support.tickets.edit',
            'support.tickets.manage',
        ]);

        return $user->fresh();
    }

    /**
     * A second company on the platform, with a ticket of its own.
     *
     * This is the fixture the boundary tests are actually about: without a
     * second tenant, every "can they see it" assertion passes for the wrong
     * reason.
     */
    private function rivalTicket(): SupportTicket
    {
        $tenant = Tenant::create([
            'name' => 'Rival Restaurants Pvt Ltd',
            'code' => 'RIVAL',
            'slug' => 'rival-restaurants',
            'is_active' => true,
        ]);

        return SupportTicket::create([
            'tenant_id' => $tenant->id,
            'reference' => SupportTicket::nextReference(),
            'subject' => 'Our takings are down and the reports disagree',
            'body' => 'Commercially sensitive.',
            'category' => 'bug',
            'priority' => SupportTicket::NORMAL,
            'status' => SupportTicket::OPEN,
        ]);
    }

    /* -------------------------------------------------------- raising one */

    public function test_a_restaurant_can_raise_a_ticket(): void
    {
        $user = $this->owner();

        $this->actingAs($user)
            ->post(route('admin.support.store'), [
                'subject' => 'UPI payments declining since this morning',
                'body' => 'Every UPI attempt fails at the gateway step.',
                'category' => 'payments',
                'priority' => SupportTicket::HIGH,
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $ticket = SupportTicket::query()->firstOrFail();

        $this->assertSame('UPI payments declining since this morning', $ticket->subject);
        $this->assertSame(SupportTicket::OPEN, $ticket->status);
        $this->assertSame($user->tenant_id, $ticket->tenant_id);
        $this->assertSame($user->id, $ticket->opened_by);

        // The reference is what a person quotes on the phone, so it has to be
        // there from the first save rather than assigned later.
        $this->assertStringStartsWith('TKT-', $ticket->reference);
    }

    /**
     * The company is taken from the actor, never from the form.
     *
     * Posting somebody else's tenant_id is the cheapest attack on this module:
     * file a ticket against a rival, and the desk's reply arrives in a thread
     * the attacker can read.
     */
    public function test_a_tenant_id_in_the_payload_is_ignored(): void
    {
        $user = $this->owner();
        $rival = $this->rivalTicket();

        $this->actingAs($user)
            ->post(route('admin.support.store'), [
                'subject' => 'Filed against somebody else',
                'body' => 'Should not land in their queue.',
                'category' => 'other',
                'priority' => SupportTicket::NORMAL,
                'tenant_id' => $rival->tenant_id,
            ])
            ->assertOk();

        $ticket = SupportTicket::query()->where('subject', 'Filed against somebody else')->firstOrFail();

        $this->assertSame($user->tenant_id, $ticket->tenant_id);
        $this->assertNotSame($rival->tenant_id, $ticket->tenant_id);
    }

    /**
     * The branch dropdown lists only branches this company owns.
     *
     * Shop is one of the few models with no global scope - it is what the
     * scope is defined in terms of - so `Shop::query()` returns every branch on
     * the platform. The first version of this form used exactly that, which
     * put rival restaurants' branch names in a select box.
     */
    public function test_the_branch_picker_does_not_leak_other_companies(): void
    {
        $user = $this->owner();

        $rivalTenant = Tenant::create([
            'name' => 'Rival Restaurants Pvt Ltd',
            'code' => 'RIVAL2',
            'slug' => 'rival-restaurants-2',
            'is_active' => true,
        ]);

        $rivalShop = Shop::create([
            'tenant_id' => $rivalTenant->id,
            'name' => 'Rival Rooftop Kitchen',
            'code' => 'RIVAL-RT',
            'slug' => 'rival-rooftop-kitchen',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.support.create'))
            ->assertOk()
            ->assertDontSee('Rival Rooftop Kitchen');

        // And it is not merely hidden: filing against it is refused.
        $this->actingAs($user)
            ->post(route('admin.support.store'), [
                'subject' => 'Filed against a rival branch',
                'body' => 'Should be refused.',
                'category' => 'other',
                'priority' => SupportTicket::NORMAL,
                'shop_id' => $rivalShop->id,
            ])
            ->assertStatus(422);

        $this->assertDatabaseMissing('support_tickets', ['shop_id' => $rivalShop->id]);
    }

    /* ----------------------------------------------------- the boundary -- */

    public function test_a_restaurant_never_sees_another_companys_ticket(): void
    {
        $user = $this->owner();
        $rival = $this->rivalTicket();

        // Not in the list...
        $this->actingAs($user)
            ->get(route('admin.support.index'))
            ->assertOk()
            ->assertDontSee($rival->reference)
            ->assertDontSee('Commercially sensitive.');

        // ...and not reachable by guessing the id either. 404, not 403 - see
        // the controller docblock.
        $this->actingAs($user)
            ->get(route('admin.support.show', $rival))
            ->assertNotFound();
    }

    public function test_a_restaurant_cannot_reply_to_another_companys_ticket(): void
    {
        $user = $this->owner(['support.tickets.view', 'support.tickets.create']);
        $rival = $this->rivalTicket();

        $this->actingAs($user)
            ->post(route('admin.support.reply', $rival), ['body' => 'Let me in'])
            ->assertNotFound();

        $this->assertSame(0, $rival->replies()->count());
    }

    public function test_the_desk_sees_every_companys_tickets(): void
    {
        $agent = $this->agent();
        $rival = $this->rivalTicket();

        $this->actingAs($agent)
            ->get(route('admin.support.index'))
            ->assertOk()
            ->assertSee($rival->reference);

        $this->actingAs($agent)
            ->get(route('admin.support.show', $rival))
            ->assertOk();
    }

    /* -------------------------------------------------- internal notes -- */

    public function test_an_internal_note_is_never_shown_to_the_restaurant(): void
    {
        $owner = $this->owner();
        $agent = $this->agent();
        $service = app(SupportTicketService::class);

        $ticket = $service->open([
            'tenant_id' => $owner->tenant_id,
            'subject' => 'Card machine',
            'body' => 'It is refusing everything.',
            'category' => 'hardware',
            'priority' => SupportTicket::NORMAL,
        ], $owner);

        $service->reply($ticket, 'Three months in arrears - stall them.', $agent, fromStaff: true, internal: true);
        $service->reply($ticket, 'We are looking into it now.', $agent, fromStaff: true);

        $this->actingAs($owner)
            ->get(route('admin.support.show', $ticket))
            ->assertOk()
            ->assertSee('We are looking into it now.')
            ->assertDontSee('Three months in arrears');

        // The desk does see it, or the note would be write-only.
        $this->actingAs($agent)
            ->get(route('admin.support.show', $ticket))
            ->assertOk()
            ->assertSee('Three months in arrears');
    }

    /**
     * A restaurant cannot forge one by posting the flag.
     *
     * The form only renders the checkbox for the desk, but a hidden input is
     * not a closed door - the controller decides `internal` from the actor's
     * rights and ignores what was sent.
     */
    public function test_a_restaurant_cannot_post_an_internal_note(): void
    {
        $owner = $this->owner();
        $service = app(SupportTicketService::class);

        $ticket = $service->open([
            'tenant_id' => $owner->tenant_id,
            'subject' => 'A question',
            'body' => 'Asking something.',
            'category' => 'other',
            'priority' => SupportTicket::NORMAL,
        ], $owner);

        $this->actingAs($owner)
            ->post(route('admin.support.reply', $ticket), [
                'body' => 'Trying to write a note',
                'internal' => 1,
            ])
            ->assertOk();

        $reply = $ticket->replies()->latest('id')->firstOrFail();

        $this->assertFalse($reply->internal);
        // ...and it must not be attributed to support either.
        $this->assertFalse($reply->from_staff);
    }

    /* ---------------------------------------------------- whose turn it is */

    public function test_a_reply_moves_the_ticket_to_the_other_side(): void
    {
        $owner = $this->owner();
        $agent = $this->agent();
        $service = app(SupportTicketService::class);

        $ticket = $service->open([
            'tenant_id' => $owner->tenant_id,
            'subject' => 'Printer',
            'body' => 'Nothing prints.',
            'category' => 'hardware',
            'priority' => SupportTicket::NORMAL,
        ], $owner);

        $this->assertSame(SupportTicket::OPEN, $ticket->status);

        $service->reply($ticket, 'Have you power-cycled it?', $agent, fromStaff: true);
        $this->assertSame(SupportTicket::AWAITING_CUSTOMER, $ticket->fresh()->status);

        $service->reply($ticket, 'Yes, twice.', $owner, fromStaff: false);
        $this->assertSame(SupportTicket::AWAITING_SUPPORT, $ticket->fresh()->status);
    }

    /** An internal note is the desk talking to itself; it moves nothing. */
    public function test_an_internal_note_does_not_move_the_ticket(): void
    {
        $owner = $this->owner();
        $agent = $this->agent();
        $service = app(SupportTicketService::class);

        $ticket = $service->open([
            'tenant_id' => $owner->tenant_id,
            'subject' => 'Something',
            'body' => 'Anything.',
            'category' => 'other',
            'priority' => SupportTicket::NORMAL,
        ], $owner);

        $service->reply($ticket, 'Checking with billing.', $agent, fromStaff: true, internal: true);

        $fresh = $ticket->fresh();

        $this->assertSame(SupportTicket::OPEN, $fresh->status);
        $this->assertNull($fresh->last_reply_at);
        // Crucially: it does not count as having answered them.
        $this->assertNull($fresh->first_responded_at);
    }

    /**
     * First response time is stamped once and never moved.
     *
     * It cannot be recovered later - a thread with nine replies no longer
     * remembers which was the first from the desk - so a second reply
     * overwriting it would quietly destroy the only number a support desk is
     * judged on.
     */
    public function test_first_response_is_recorded_once(): void
    {
        $owner = $this->owner();
        $agent = $this->agent();
        $service = app(SupportTicketService::class);

        $ticket = $service->open([
            'tenant_id' => $owner->tenant_id,
            'subject' => 'Timing',
            'body' => 'Question.',
            'category' => 'other',
            'priority' => SupportTicket::NORMAL,
        ], $owner);

        $service->reply($ticket, 'First answer.', $agent, fromStaff: true);
        $first = $ticket->fresh()->first_responded_at;

        $this->assertNotNull($first);

        $this->travel(5)->minutes();
        $service->reply($ticket, 'Second answer.', $agent, fromStaff: true);

        $this->assertEquals($first, $ticket->fresh()->first_responded_at);
    }

    /* ------------------------------------------------ resolving and closing */

    public function test_a_reply_reopens_a_resolved_ticket_but_not_a_closed_one(): void
    {
        $owner = $this->owner();
        $agent = $this->agent();
        $service = app(SupportTicketService::class);

        $ticket = $service->open([
            'tenant_id' => $owner->tenant_id,
            'subject' => 'Recurring',
            'body' => 'It keeps happening.',
            'category' => 'bug',
            'priority' => SupportTicket::NORMAL,
        ], $owner);

        $service->resolve($ticket);
        $this->assertSame(SupportTicket::RESOLVED, $ticket->fresh()->status);

        // "That did not fix it" is the most useful message a desk gets.
        $service->reply($ticket, 'It is still broken.', $owner, fromStaff: false);
        $fresh = $ticket->fresh();

        $this->assertSame(SupportTicket::AWAITING_SUPPORT, $fresh->status);
        $this->assertNull($fresh->resolved_at);

        // Closed is the state that does not come back on its own.
        $service->close($fresh, $agent);

        $this->actingAs($owner)
            ->post(route('admin.support.reply', $fresh), ['body' => 'Hello?'])
            ->assertStatus(422);

        $this->assertSame(SupportTicket::CLOSED, $fresh->fresh()->status);
    }

    /* ------------------------------------------------------ desk-only acts */

    public function test_a_restaurant_cannot_assign_or_reprioritise(): void
    {
        $owner = $this->owner(['support.tickets.view', 'support.tickets.create', 'support.tickets.edit']);
        $service = app(SupportTicketService::class);

        $ticket = $service->open([
            'tenant_id' => $owner->tenant_id,
            'subject' => 'Mine',
            'body' => 'My own ticket.',
            'category' => 'other',
            'priority' => SupportTicket::LOW,
        ], $owner);

        // Their own ticket, and they still may not work the queue with it.
        $this->actingAs($owner)
            ->put(route('admin.support.priority', $ticket), ['priority' => SupportTicket::URGENT])
            ->assertForbidden();

        $this->actingAs($owner)
            ->put(route('admin.support.assign', $ticket), ['assigned_to' => $owner->id])
            ->assertForbidden();

        $this->assertSame(SupportTicket::LOW, $ticket->fresh()->priority);
    }

    /**
     * Reading a thread is not permission to write in it.
     *
     * `support.tickets.view` is deliberately wide - the Auditor role is
     * read-only across the whole system and holds it, and the reply route asks
     * only for `view` because that is what decides reachability. Writing needs
     * `create`. Without that distinction "read-only" would not be.
     */
    public function test_a_read_only_user_cannot_reply(): void
    {
        $owner = $this->owner();
        $service = app(SupportTicketService::class);

        $ticket = $service->open([
            'tenant_id' => $owner->tenant_id,
            'subject' => 'Readable',
            'body' => 'Anyone in the company may read this.',
            'category' => 'other',
            'priority' => SupportTicket::NORMAL,
        ], $owner);

        // Same company, so the ticket is visible - but view only.
        $auditor = $this->owner(['support.tickets.view']);

        $this->actingAs($auditor)
            ->get(route('admin.support.show', $ticket))
            ->assertOk();

        $this->actingAs($auditor)
            ->post(route('admin.support.reply', $ticket), ['body' => 'Writing anyway'])
            ->assertForbidden();

        $this->assertSame(0, $ticket->replies()->count());
    }

    /**
     * The seeded Auditor role really is one of those users.
     *
     * The test above proves the rule; this proves the rule applies to the role
     * that actually exists, so renaming or re-granting Auditor cannot quietly
     * turn it into a role that can write.
     */
    public function test_the_seeded_auditor_role_cannot_write_to_a_ticket(): void
    {
        $auditor = \Spatie\Permission\Models\Role::where('name', 'Auditor')->firstOrFail();
        $held = $auditor->permissions->pluck('name');

        $this->assertTrue($held->contains('support.tickets.view'), 'Auditor should be able to read tickets.');
        $this->assertFalse($held->contains('support.tickets.create'), 'Auditor is read-only and must not be able to reply.');
        $this->assertFalse($held->contains('support.tickets.manage'));
    }

    /**
     * No ordinary role may hold the cross-tenant right.
     *
     * The seeder's grants are mostly written as exclusions, so a new
     * platform-only permission lands in half of them by default. This is the
     * test that fails when that happens.
     */
    public function test_no_customer_role_holds_the_manage_right(): void
    {
        $roles = \Spatie\Permission\Models\Role::query()
            ->whereNotIn('name', [User::SUPER_ADMIN, 'Admin'])
            ->with('permissions')
            ->get();

        $this->assertNotEmpty($roles, 'Roles were not seeded; the assertion below would pass for nothing.');

        foreach ($roles as $role) {
            $this->assertFalse(
                $role->permissions->contains('name', 'support.tickets.manage'),
                "Role [{$role->name}] holds support.tickets.manage, which reads every company's tickets.",
            );
        }
    }
}
