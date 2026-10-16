<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Whether an outlet may use CRM is Khana Accounts' answer.
 *
 * Accounts sells each outlet a product and says so with
 * accounts.entitlement.changed; the kit keeps the outcome in
 * `khana_entitlements`, keyed by Accounts' outlet id - `shops.ulid` here. These
 * tests write that row the way the event would and check that the back office
 * follows it (EnsureShopIsEntitled):
 *
 *   trial, active, grace   through
 *   suspended              read only
 *   ended, or never sold   refused, without signing anybody out
 *
 * and that a business Accounts has suspended turns its staff out altogether
 * (EnsureTenantIsActive).
 */
class CrmEntitlementTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');

        $this->tenant = Tenant::query()->orderBy('id')->firstOrFail();
        $this->shop = Shop::query()->where('tenant_id', $this->tenant->id)->orderBy('id')->firstOrFail();
    }

    /** @param  list<Shop>  $shops */
    private function manager(array $shops = [], string $email = 'manager@example.test'): User
    {
        $shops = $shops ?: [$this->shop];

        $user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Meera Manager',
            'email' => $email,
            'password' => 'a-strong-password-1',
            'is_admin' => true,
        ]);

        $user->assignRole('Manager');

        foreach ($shops as $i => $shop) {
            $user->shops()->attach($shop->id, ['is_default' => $i === 0]);
        }

        $user->forceFill(['current_shop_id' => $shops[0]->id, 'all_shops_view' => false])->save();

        return $user->fresh();
    }

    private function forgetEntitlement(Shop $shop): void
    {
        DB::table('khana_entitlements')->where('outlet_id', $shop->fresh()->ulid)->delete();
        app(\Khana\Kit\Tenancy\Entitlements::class)->forget();
    }

    /* ------------------------------------------------------------ baseline */

    /** The seeded install can be signed in to: its shop has bought CRM. */
    public function test_the_seeded_shop_is_entitled_under_its_accounts_id(): void
    {
        $this->assertNotNull($this->shop->ulid);
        $this->assertNotNull($this->tenant->ulid);

        $this->assertDatabaseHas('khana_entitlements', [
            'outlet_id' => $this->shop->ulid,
            'tenant_id' => $this->tenant->ulid,
            'product' => 'crm',
            'state' => 'active',
        ]);
    }

    public function test_an_entitled_outlet_works(): void
    {
        foreach (['trial', 'active', 'grace'] as $state) {
            $this->entitle($this->shop, $state);

            $this->actingAs($this->manager([], "{$state}@example.test"))
                ->get('/admin/dashboard')
                ->assertOk();
        }
    }

    /* ------------------------------------------------------------- refused */

    public function test_an_outlet_that_never_bought_crm_is_refused(): void
    {
        $this->forgetEntitlement($this->shop);

        $manager = $this->manager();

        $this->actingAs($manager)
            ->get('/admin/dashboard')
            ->assertForbidden()
            ->assertSee('does not have Restro One CRM');

        $this->actingAs($manager)
            ->getJson('/admin/customers')
            ->assertForbidden()
            ->assertJsonPath('success', false);

        // One branch lapsing is not a reason to sign anybody out.
        $this->assertAuthenticatedAs($manager);
    }

    public function test_an_ended_subscription_is_refused(): void
    {
        $this->entitle($this->shop, 'ended');

        $this->actingAs($this->manager())
            ->get('/admin/dashboard')
            ->assertForbidden();
    }

    /** Suspended for non-payment: looking and exporting yes, changing no. */
    public function test_a_suspended_outlet_is_read_only(): void
    {
        $this->entitle($this->shop, 'suspended');

        $manager = $this->manager();

        $this->actingAs($manager)->get('/admin/dashboard')->assertOk();
        $this->actingAs($manager)->get('/admin/customers')->assertOk();

        $this->actingAs($manager)
            ->postJson('/admin/customers', ['name' => 'Asha', 'mobile' => '9876543210'])
            ->assertForbidden()
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'suspended'));

        $this->assertDatabaseMissing('customers', ['name' => 'Asha']);
    }

    public function test_signing_out_is_never_refused(): void
    {
        $this->forgetEntitlement($this->shop);

        $this->actingAs($this->manager())
            ->post('/admin/logout')
            ->assertRedirect();

        $this->assertGuest();
    }

    /** The reason to open a lapsed outlet is to find out why it lapsed. */
    public function test_a_super_admin_is_never_refused(): void
    {
        $this->forgetEntitlement($this->shop);

        $admin = User::role(User::SUPER_ADMIN)->firstOrFail();

        $this->actingAs($admin)->get('/admin/dashboard')->assertOk();
    }

    /* ------------------------------------------------------ several outlets */

    /**
     * A lapsed branch is that branch's problem: the manager keeps working in
     * the one that is paid for, and the consolidated view - which only reads
     * - stays open while any of their outlets has CRM.
     */
    public function test_one_lapsed_branch_does_not_stop_the_others(): void
    {
        $second = Shop::query()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Riverside',
            'code' => 'RIVER',
            'slug' => Shop::uniqueSlug('Riverside'),
            'is_active' => true,
        ]);

        $this->entitle($second, 'ended');

        $manager = $this->manager([$this->shop, $second]);

        $this->actingAs($manager)->get('/admin/dashboard')->assertOk();

        $manager->forceFill(['current_shop_id' => $second->id])->save();
        // Memoised for the process, which is the whole test here.
        CurrentShop::forget();

        $this->actingAs($manager->fresh())
            ->get('/admin/dashboard')
            ->assertForbidden()
            ->assertSee('Riverside');

        // Switching back is always allowed - it is the way out.
        $this->actingAs($manager->fresh())
            ->postJson('/admin/shops/switch', ['shop' => (string) $this->shop->id])
            ->assertOk();

        $manager->forceFill(['current_shop_id' => null, 'all_shops_view' => true])->save();
        CurrentShop::forget();

        $this->actingAs($manager->fresh())->get('/admin/dashboard')->assertOk();
    }

    /* ----------------------------------------------------------- business */

    /**
     * Suspending a business is Accounts' decision, and "its staff cannot sign
     * in" includes the ones already signed in.
     */
    public function test_a_business_accounts_has_suspended_is_signed_out(): void
    {
        DB::table('khana_tenants')->insert([
            'id' => $this->tenant->fresh()->ulid,
            'name' => $this->tenant->name,
            'status' => 'suspended',
            'changed_at' => now()->utc()->format('Y-m-d H:i:s'),
        ]);

        $this->actingAs($this->manager())
            ->get('/admin/dashboard')
            ->assertRedirect(route('admin.login'));

        $this->assertGuest();
    }

    /* --------------------------------------------------------------- gone */

    /** Plans, subscriptions and billing are Accounts' screens now. */
    public function test_the_old_billing_screens_are_gone(): void
    {
        $admin = User::role(User::SUPER_ADMIN)->firstOrFail();

        foreach (['/admin/plans', '/admin/subscriptions', '/admin/subscription', '/admin/billing', '/admin/tenants', '/admin/users'] as $path) {
            $this->actingAs($admin)->get($path)->assertNotFound();
        }
    }
}
