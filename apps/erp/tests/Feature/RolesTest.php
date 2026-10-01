<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Khana\Kit\Events\Envelope;
use Khana\Kit\Events\Inbox\Handlers;
use Khana\Kit\Sso\SignInClient;
use Khana\Kit\Support\Ulid;
use Tests\Concerns\MintsMachineTokens;
use Tests\TestCase;

/**
 * A business's own roles: made by the owner from a copy of a preset, held by
 * whoever Accounts says, and opening exactly what was ticked.
 */
class RolesTest extends TestCase
{
    use MintsMachineTokens, RefreshDatabase;

    /** @var array{tenant: string, outlet: string} */
    private array $at;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');

        $this->at = $this->business();
    }

    /** A head chef: writes recipes and logs wastage, and never sees a bill. */
    private function headChef(?string $tenant = null): Role
    {
        return Role::allTenants()->create([
            'tenant_id' => $tenant ?? $this->at['tenant'],
            'key' => 'head_chef',
            'name' => 'Head Chef',
            'abilities' => ['stock.view', 'wastage', 'recipes.view', 'recipes.manage'],
        ]);
    }

    /* ------------------------------------------------------ making one */

    public function test_the_owner_makes_a_role_by_copying_a_preset(): void
    {
        $owner = $this->signInAs('owner', $this->at);

        $this->get(route('roles.index'))->assertOk()->assertSee('Built in')->assertSee('Storekeeper');

        // The form arrives ticked the way the preset it copies is.
        $html = $this->get(route('roles.create', ['from' => 'accountant']))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/value="bills\.manage"[^>]*\schecked/', $html);
        $this->assertDoesNotMatchRegularExpression('/value="wastage"[^>]*\schecked/', $html);
        // Making roles is not on offer to a made role.
        $this->assertStringNotContainsString('value="roles.manage"', $html);

        $this->post(route('roles.store'), [
            'name' => 'Head Chef', 'based_on' => 'storekeeper',
            // Ticked: recipes and wastage. Asked for on the sly: the owner's own ability, and one ERP does not have.
            'abilities' => ['recipes.manage', 'wastage', 'roles.manage', 'fly'],
        ])->assertRedirect(route('roles.index'));

        $role = Role::allTenants()->sole();

        $this->assertSame('head_chef', $role->key);
        $this->assertSame('storekeeper', $role->based_on);
        $this->assertSame($owner->id, $role->user_id);
        // What was ticked, and the screens that work lands on - nothing else.
        $this->assertSame(['stock.view', 'wastage', 'recipes.view', 'recipes.manage'], $role->abilities);

        $this->get(route('roles.index'))->assertOk()->assertSee('Head Chef')->assertSee('Copied from Storekeeper');
    }

    public function test_a_role_needs_a_name_of_its_own_and_something_to_do(): void
    {
        $this->signInAs('owner', $this->at);
        $this->headChef();

        $this->post(route('roles.store'), ['name' => 'head chef', 'abilities' => ['wastage']])->assertSessionHasErrors('name');
        $this->post(route('roles.store'), ['name' => 'Storekeeper', 'abilities' => ['wastage']])->assertSessionHasErrors('name');
        $this->post(route('roles.store'), ['name' => 'Night Shift', 'abilities' => []])->assertSessionHasErrors('abilities');
        $this->post(route('roles.store'), ['name' => 'Night Shift', 'abilities' => ['roles.manage']])->assertSessionHasErrors('abilities');

        $this->assertSame(1, Role::allTenants()->count());
    }

    public function test_a_key_is_a_contract_code_and_never_one_already_taken(): void
    {
        $this->headChef();

        $this->assertSame('head_chef_2', Roles::keyFor('Head Chef', $this->at['tenant']));
        // A preset's key is not to be had either.
        $this->assertSame('owner_2', Roles::keyFor('Owner', $this->at['tenant']));
        $this->assertSame('role_2nd_chef', Roles::keyFor('2nd Chef', $this->at['tenant']));
        // A name with nothing in it a key can be made of still gets one.
        $this->assertSame('role', Roles::keyFor('***', $this->at['tenant']));

        // A name in Hindi, and one far too long, both come out as codes.
        foreach (['रसोइया', str_repeat('Very Long Role Name ', 5)] as $name) {
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]{0,39}$/', Roles::keyFor($name, $this->at['tenant']));
        }
    }

    public function test_editing_changes_what_it_opens_and_keeps_its_key(): void
    {
        $this->signInAs('owner', $this->at);
        $role = $this->headChef();

        $this->get(route('roles.edit', $role->id))->assertOk()->assertSee('Head Chef');

        $this->put(route('roles.update', $role->id), ['name' => 'Executive Chef', 'abilities' => ['recipes.view', 'count']])
            ->assertRedirect(route('roles.index'));

        $role->refresh();
        $this->assertSame(['head_chef', 'Executive Chef'], [$role->key, $role->name]);
        $this->assertSame(['stock.view', 'count', 'recipes.view'], $role->abilities);
    }

    public function test_a_role_somebody_holds_cannot_be_deleted(): void
    {
        $this->signInAs('owner', $this->at);
        $role = $this->headChef();

        $chef = User::create([
            'ulid' => Ulid::generate(), 'tenant_id' => $this->at['tenant'], 'name' => 'Sunita',
            'role_key' => 'head_chef', 'outlet_ids' => [$this->at['outlet']],
        ]);

        $this->delete(route('roles.destroy', $role->id))->assertSessionHas('error', fn (string $m) => str_contains($m, 'Sunita'));
        $this->assertSame(1, Role::allTenants()->count());

        $chef->update(['role_key' => 'storekeeper']);

        $this->delete(route('roles.destroy', $role->id))->assertRedirect(route('roles.index'));
        $this->assertSame(0, Role::allTenants()->count());
    }

    public function test_only_the_owner_makes_roles_and_only_their_own_business_s(): void
    {
        $role = $this->headChef();

        $this->signInAs('store_manager', $this->at);
        $this->get(route('roles.index'))->assertForbidden();
        $this->post(route('roles.store'), ['name' => 'Anything', 'abilities' => ['wastage']])->assertForbidden();

        // Another business's owner cannot reach it by its number.
        $this->flushSession();
        $this->signInAs('owner', $this->business('Rival Dhaba'));

        $this->get(route('roles.edit', $role->id))->assertNotFound();
        $this->put(route('roles.update', $role->id), ['name' => 'Taken Over', 'abilities' => ['wastage']])->assertNotFound();
        $this->delete(route('roles.destroy', $role->id))->assertNotFound();
        $this->get(route('roles.index'))->assertOk()->assertDontSee('Head Chef');
    }

    /* ----------------------------------------------------- holding one */

    public function test_a_made_role_opens_what_was_ticked_and_nothing_else(): void
    {
        $this->headChef();
        $chef = $this->signInAs('head_chef', $this->at);

        $this->assertSame('Head Chef', $chef->roleName());

        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('data-label="Recipes"', $html);
        $this->assertStringContainsString('data-label="Kitchen"', $html);
        $this->assertStringNotContainsString('data-label="Bills"', $html);
        $this->assertStringNotContainsString('data-label="Settings"', $html);

        $this->get(route('recipes.index'))->assertOk();
        $this->get(route('wastage.create'))->assertOk();
        $this->get(route('production.create'))->assertOk();

        $this->get(route('receipts.create'))->assertForbidden();
        $this->get(route('bills.index'))->assertForbidden();
        $this->get(route('roles.index'))->assertForbidden();
    }

    public function test_a_change_to_a_role_reaches_whoever_holds_it_at_once(): void
    {
        $role = $this->headChef();
        $this->signInAs('head_chef', $this->at);

        $this->get(route('counts.create'))->assertForbidden();

        $role->update(['abilities' => ['stock.view', 'count']]);

        $this->get(route('counts.create'))->assertOk();
        $this->get(route('recipes.index'))->assertForbidden();
    }

    public function test_a_made_role_never_opens_everything_whatever_its_row_says(): void
    {
        // Written behind the screen's back: "everything", the owner's own ability, and one ERP has never heard of.
        Role::allTenants()->create([
            'tenant_id' => $this->at['tenant'], 'key' => 'sneaky', 'name' => 'Sneaky',
            'abilities' => ['*', 'roles.manage', 'fly', 'count'],
        ]);

        $user = $this->signInAs('sneaky', $this->at);

        $this->assertSame(['stock.view', 'count'], Roles::abilities('sneaky', $this->at['tenant']));
        $this->assertTrue($user->can('count'));
        $this->assertFalse($user->can('roles.manage'));
        $this->assertFalse($user->can('settings.manage'));

        $this->get(route('roles.index'))->assertForbidden();
        $this->get(route('bills.index'))->assertForbidden();
    }

    public function test_one_business_s_role_is_no_role_at_all_in_another(): void
    {
        $rival = $this->business('Rival Dhaba');
        $this->headChef($rival['tenant']);

        $this->assertTrue(Roles::exists('head_chef', $rival['tenant']));
        $this->assertFalse(Roles::exists('head_chef', $this->at['tenant']));

        // Holding the key here opens nothing.
        $this->signInAs('head_chef', $this->at);
        $this->get(route('recipes.index'))->assertForbidden();
    }

    /* -------------------------------------------------- with Accounts */

    public function test_accounts_is_told_of_a_business_s_own_roles_and_only_its_own(): void
    {
        $this->trustAccountsKey();
        $this->headChef();
        $rival = $this->business('Rival Dhaba');

        $mine = $this->getJson('/api/v1/roles?tenant_id='.$this->at['tenant'], $this->bearer(['roles:read']))->assertOk();

        $mine->assertJsonCount(6, 'roles')
            ->assertJsonPath('roles.0', ['key' => 'owner', 'name' => 'Owner', 'preset' => true])
            ->assertJsonPath('roles.5', ['key' => 'head_chef', 'name' => 'Head Chef', 'preset' => false]);

        // Every key is one Accounts can store (contracts/events/defs.json, `code`).
        foreach ($mine->json('roles') as $role) {
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]{0,39}$/', $role['key']);
        }

        $this->getJson('/api/v1/roles?tenant_id='.$rival['tenant'], $this->bearer(['roles:read']))
            ->assertOk()->assertJsonCount(5, 'roles');
    }

    public function test_a_person_accounts_gave_a_made_role_signs_in_with_it(): void
    {
        $accounts = 'https://accounts.example.test';
        $this->headChef();

        config(['khana.sso.accounts_url' => $accounts, 'khana.sso.client_id' => 'erp-browser', 'auth.local_login' => null]);

        Http::fake([
            $accounts.'/oauth/token' => Http::response(['access_token' => 'person-token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
            $accounts.'/api/v1/me' => Http::response([
                'user_id' => Ulid::generate(), 'name' => 'Sunita Chef', 'email' => 'sunita@spicegarden.test', 'mobile' => null,
                'tenant_id' => $this->at['tenant'], 'platform_admin' => false,
                'outlets' => [['outlet_id' => $this->at['outlet'], 'name' => 'Spice Garden', 'code' => 'SG']],
                'products' => ['erp' => ['role_key' => 'head_chef', 'outlet_ids' => [$this->at['outlet']]]],
            ]),
        ]);

        $this->get(route('khana.sso.login'))->assertRedirect();
        $state = session(SignInClient::SESSION_KEY)['state'];

        $this->get(route('khana.sso.callback', ['code' => 'one-time-code', 'state' => $state]))->assertRedirect(route('home'));

        $user = User::query()->sole();
        $this->assertSame('head_chef', $user->role_key);
        $this->assertTrue($user->can('recipes.manage'));
        $this->assertFalse($user->can('bills.view'));
    }

    public function test_being_moved_to_a_made_role_between_sign_ins_keeps_the_person_in(): void
    {
        $user = $this->signInAs('storekeeper', $this->at);
        $this->headChef();

        app(Handlers::class)->for('accounts.access.changed', 1)(new Envelope(
            id: Ulid::generate(), type: 'accounts.access.changed', version: 1, source: 'accounts', seq: 1,
            occurredAt: '2026-10-01T06:00:00Z', tenantId: $this->at['tenant'], outletId: null,
            data: ['user_id' => $user->ulid, 'product' => 'erp', 'role_key' => 'head_chef', 'outlet_ids' => [$this->at['outlet']]],
        ));

        $user->refresh();
        $this->assertSame('head_chef', $user->role_key);
        $this->assertSame([$this->at['outlet']], $user->outlet_ids);
    }
}
