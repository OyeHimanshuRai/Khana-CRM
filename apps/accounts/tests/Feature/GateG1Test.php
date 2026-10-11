<?php

namespace Tests\Feature;

use App\Models\Outlet;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Outlets;
use App\Services\Signup;
use App\Services\Snapshot;
use App\Services\Subscriptions;
use App\Services\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Khana\Kit\Events\OutboxEvent;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * Gate G1 (architecture doc): sign up, add an outlet, invite a cashier; the
 * cashier's access reaches both products; an entitlement change is published.
 *
 * This is Accounts on its own - it proves Accounts writes the right rows and,
 * crucially, records the right events in the outbox. Whether CRM and ERP
 * apply them within ten seconds is the cross-service smoke; here we prove the
 * events exist and are shaped as the contract says (the outbox validates
 * every event against its schema in the testing environment).
 */
final class GateG1Test extends TestCase
{
    use RefreshDatabase;

    private function suitePlan(): Plan
    {
        return Plan::create([
            'code' => 'suite',
            'name' => 'Suite',
            'products' => ['crm', 'erp'],
            'monthly_price_paise' => 149900,
            'yearly_price_paise' => 1499000,
            'trial_days' => 14,
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function eventsOfType(string $type): array
    {
        return OutboxEvent::query()->where('type', $type)->get()->all();
    }

    public function test_signup_creates_a_whole_business_and_announces_it(): void
    {
        $plan = $this->suitePlan();

        $owner = app(Signup::class)->register(
            business: ['name' => 'Spice Route'],
            outlet: ['name' => 'Ground Floor', 'code' => 'GF', 'city' => 'Delhi', 'state_code' => '07'],
            owner: ['name' => 'Asha', 'email' => 'asha@spice.test', 'password' => 'secret12'],
            plan: $plan,
        );

        // The rows.
        $this->assertDatabaseHas('tenants', ['name' => 'Spice Route']);
        $this->assertDatabaseHas('outlets', ['code' => 'GF']);
        $this->assertTrue($owner->isOwner());
        $this->assertCount(2, $owner->access);           // crm + erp
        $this->assertDatabaseHas('subscriptions', ['plan_id' => $plan->id]);

        // The events, one of each, all valid against their schema.
        $this->assertCount(1, $this->eventsOfType('accounts.tenant.upserted'));
        $this->assertCount(1, $this->eventsOfType('accounts.outlet.upserted'));
        $this->assertCount(1, $this->eventsOfType('accounts.user.upserted'));
        $this->assertCount(2, $this->eventsOfType('accounts.access.changed'));      // both products
        $this->assertCount(2, $this->eventsOfType('accounts.entitlement.changed')); // both products

        // The entitlement the products will copy is a live trial.
        $entitlement = OutboxEvent::query()->where('type', 'accounts.entitlement.changed')->first();
        $this->assertSame('trial', $entitlement->data['state']);
    }

    public function test_me_returns_the_person_their_outlets_and_their_roles(): void
    {
        $plan = $this->suitePlan();
        $owner = app(Signup::class)->register(
            business: ['name' => 'Spice Route'],
            outlet: ['name' => 'Ground Floor', 'code' => 'GF', 'city' => 'Delhi', 'state_code' => '07'],
            owner: ['name' => 'Asha', 'email' => 'asha@spice.test', 'password' => 'secret12'],
            plan: $plan,
        );

        Passport::actingAs($owner, guard: 'api');

        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('user_id', $owner->ulid)
            ->assertJsonPath('name', 'Asha')
            ->assertJsonPath('tenant_id', $owner->tenant->ulid)
            ->assertJsonPath('platform_admin', false)
            ->assertJsonCount(1, 'outlets')
            ->assertJsonPath('products.crm.role_key', 'owner')
            ->assertJsonPath('products.erp.role_key', 'owner');
    }

    public function test_adding_an_outlet_starts_its_trial_and_announces_it(): void
    {
        $plan = $this->suitePlan();
        $owner = app(Signup::class)->register(
            business: ['name' => 'Spice Route'],
            outlet: ['name' => 'Ground Floor', 'code' => 'GF', 'city' => 'Delhi', 'state_code' => '07'],
            owner: ['name' => 'Asha', 'email' => 'asha@spice.test', 'password' => 'secret12'],
            plan: $plan,
        );
        $tenant = $owner->tenant;

        $before = count($this->eventsOfType('accounts.entitlement.changed'));

        app(Outlets::class)->open($tenant, [
            'name' => 'Rooftop', 'code' => 'RF', 'city' => 'Delhi', 'state_code' => '07', 'address_line1' => 'Terrace',
        ], $plan, by: $owner);

        $this->assertDatabaseHas('outlets', ['code' => 'RF']);
        // Two more entitlements (crm + erp) for the new outlet.
        $this->assertCount($before + 2, $this->eventsOfType('accounts.entitlement.changed'));
    }

    public function test_inviting_a_cashier_gives_them_access_before_they_sign_in(): void
    {
        $plan = $this->suitePlan();
        $owner = app(Signup::class)->register(
            business: ['name' => 'Spice Route'],
            outlet: ['name' => 'Ground Floor', 'code' => 'GF', 'city' => 'Delhi', 'state_code' => '07'],
            owner: ['name' => 'Asha', 'email' => 'asha@spice.test', 'password' => 'secret12'],
            plan: $plan,
        );
        $tenant = $owner->tenant;
        $outlet = $tenant->outlets->first();

        [$cashier, $token] = app(Team::class)->invite(
            $tenant,
            ['name' => 'Ravi', 'email' => 'ravi@spice.test'],
            ['crm' => ['role_key' => 'cashier', 'all_outlets' => false, 'outlet_ids' => [$outlet->id]]],
            $owner,
        );

        $this->assertSame(User::INVITED, $cashier->status);
        $this->assertDatabaseHas('product_access', ['user_id' => $cashier->id, 'product' => 'crm', 'role_key' => 'cashier']);

        // The access reached the outbox for CRM, with the one outlet.
        $access = collect($this->eventsOfType('accounts.access.changed'))
            ->first(fn ($e) => $e->data['user_id'] === $cashier->ulid && $e->data['product'] === 'crm');
        $this->assertNotNull($access);
        $this->assertSame([$outlet->ulid], $access->data['outlet_ids']);

        // Accepting sets the password and turns them on.
        $invitation = \App\Models\Invitation::findByToken($token);
        $activated = app(Team::class)->accept($invitation, 'newpass12');
        $this->assertSame(User::ACTIVE, $activated->status);
        $this->assertNotNull($activated->password);
    }

    public function test_snapshot_holds_the_whole_business_in_event_shapes(): void
    {
        $plan = $this->suitePlan();
        $owner = app(Signup::class)->register(
            business: ['name' => 'Spice Route'],
            outlet: ['name' => 'Ground Floor', 'code' => 'GF', 'city' => 'Delhi', 'state_code' => '07'],
            owner: ['name' => 'Asha', 'email' => 'asha@spice.test', 'password' => 'secret12'],
            plan: $plan,
        );

        $snapshot = app(Snapshot::class)->of($owner->tenant->fresh());

        $this->assertSame($owner->tenant->ulid, $snapshot['tenant_id']);
        $this->assertSame('Spice Route', $snapshot['tenant']['name']);
        $this->assertCount(1, $snapshot['outlets']);
        $this->assertSame('GF', $snapshot['outlets'][0]['code']);
        $this->assertArrayHasKey('outlet_id', $snapshot['outlets'][0]);
        $this->assertCount(1, $snapshot['users']);
        $this->assertCount(2, $snapshot['access']);        // owner in crm + erp
        $this->assertCount(2, $snapshot['entitlements']);  // both products
        $this->assertArrayHasKey('taken_at', $snapshot);
    }

    public function test_an_entitlement_change_is_published_when_a_plan_is_dropped(): void
    {
        $plan = $this->suitePlan();
        $owner = app(Signup::class)->register(
            business: ['name' => 'Spice Route'],
            outlet: ['name' => 'Ground Floor', 'code' => 'GF', 'city' => 'Delhi', 'state_code' => '07'],
            owner: ['name' => 'Asha', 'email' => 'asha@spice.test', 'password' => 'secret12'],
            plan: $plan,
        );
        $subscription = Subscription::firstOrFail();

        // Cancel: still usable until the trial's end, so no drop today.
        app(Subscriptions::class)->cancel($subscription);
        $this->assertTrue($subscription->fresh()->isUsable());

        // Past the trial and the 90-day retention: it must read as ended, and
        // that change must have been published.
        $before = count($this->eventsOfType('accounts.entitlement.changed'));

        $this->travel(120)->days();
        app(\App\Services\Entitlements::class)->refresh($owner->tenant->outlets->first()->fresh());

        $this->assertGreaterThan($before, count($this->eventsOfType('accounts.entitlement.changed')));
        $latest = OutboxEvent::query()->where('type', 'accounts.entitlement.changed')->latest('id')->first();
        $this->assertContains($latest->data['state'], ['suspended', 'ended']);

        $this->travelBack();
    }
}
