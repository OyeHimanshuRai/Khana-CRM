<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Khana\Kit\Events\Envelope;
use Khana\Kit\Events\Inbox\Handlers;
use Khana\Kit\Sso\SignInClient;
use Khana\Kit\Support\Ulid;
use Tests\TestCase;

/**
 * Getting in to ERP: only through Khana Accounts, only as somebody Accounts
 * gave a role in ERP, and only into the outlets Accounts gave them.
 */
class SignInTest extends TestCase
{
    use RefreshDatabase;

    private const ACCOUNTS = 'https://accounts.example.test';

    /** @var array<string, mixed> What the faked /me answers with right now. */
    private array $currentMe = [];

    /** @var array{tenant: string, outlet: string} */
    private array $at;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');

        config([
            'khana.sso.accounts_url' => self::ACCOUNTS,
            'khana.sso.client_id' => 'erp-browser',
            'auth.local_login' => null,
        ]);

        // Faked once: a second Http::fake() adds stubs behind the first.
        Http::fake([
            self::ACCOUNTS.'/oauth/token' => Http::response(['access_token' => 'person-token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
            self::ACCOUNTS.'/api/v1/me' => fn () => Http::response($this->currentMe),
        ]);

        $this->at = $this->business();
    }

    /**
     * What Accounts' /me would say about a storekeeper at the outlet.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function me(array $overrides = []): array
    {
        return array_replace([
            'user_id' => Ulid::generate(),
            'name' => 'Ravi Storekeeper',
            'email' => 'ravi@spicegarden.test',
            'mobile' => '9800000002',
            'tenant_id' => $this->at['tenant'],
            'platform_admin' => false,
            'outlets' => [['outlet_id' => $this->at['outlet'], 'name' => 'Spice Garden', 'code' => 'SG']],
            'products' => ['erp' => ['role_key' => 'storekeeper', 'outlet_ids' => [$this->at['outlet']]]],
        ], $overrides);
    }

    /** @param  array<string, mixed>  $me */
    private function signIn(array $me): TestResponse
    {
        $this->get(route('khana.sso.login'))
            ->assertRedirect()
            ->assertRedirectContains(self::ACCOUNTS.'/oauth/authorize');

        $state = session(SignInClient::SESSION_KEY)['state'];

        $this->currentMe = $me;

        return $this->get(route('khana.sso.callback', ['code' => 'one-time-code', 'state' => $state]));
    }

    private function deliver(string $type, array $data): void
    {
        app(Handlers::class)->for($type, 1)(new Envelope(
            id: Ulid::generate(), type: $type, version: 1, source: 'accounts', seq: 1,
            occurredAt: '2026-10-01T06:00:00Z', tenantId: $this->at['tenant'], outletId: null, data: $data,
        ));
    }

    /* ---------------------------------------------------------- the way in */

    public function test_a_signed_out_person_is_sent_to_the_sign_in_page_which_offers_accounts(): void
    {
        $this->get('/')->assertRedirect(route('login'));

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Sign in with Restro One Accounts')
            ->assertSee(route('khana.sso.login'), false)
            // No way in that skips Accounts.
            ->assertDontSee('Open ERP as');

        $this->post(route('login.local'), ['user' => 1])->assertNotFound();
    }

    public function test_a_person_arrives_with_their_business_role_and_outlets(): void
    {
        $me = $this->me();

        $this->signIn($me)->assertRedirect(route('home'));

        $user = User::query()->where('ulid', $me['user_id'])->sole();

        $this->assertAuthenticatedAs($user);
        $this->assertSame($this->at['tenant'], $user->tenant_id);
        $this->assertSame('storekeeper', $user->role_key);
        $this->assertSame([$this->at['outlet']], $user->outlet_ids);
        $this->assertFalse($user->is_platform_admin);

        $this->get('/')->assertOk()->assertSee('Today');
    }

    public function test_signing_in_again_takes_the_new_role_and_makes_no_second_row(): void
    {
        $me = $this->me();
        $this->signIn($me)->assertRedirect(route('home'));
        // Signing out of ERP signs out of Accounts too, then comes back to ERP's sign-in.
        $this->post(route('logout'))->assertRedirect(SignInClient::logoutUrl(route('login')));

        $me['products']['erp']['role_key'] = 'store_manager';
        $me['name'] = 'Ravi Manager';

        $this->signIn($me)->assertRedirect(route('home'));

        $user = User::query()->where('ulid', $me['user_id'])->sole();
        $this->assertSame('store_manager', $user->role_key);
        $this->assertSame('Ravi Manager', $user->name);
    }

    /* ------------------------------------------------------------ refusals */

    public function test_a_person_with_no_role_in_erp_is_refused_with_the_reason(): void
    {
        $me = $this->me(['products' => ['crm' => ['role_key' => 'cashier', 'outlet_ids' => [$this->at['outlet']]]]]);

        $this->signIn($me)
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'access to ERP'));

        $this->assertGuest();
        $this->assertSame(0, User::query()->count(), 'A refused sign-in leaves nothing behind.');
    }

    public function test_a_role_erp_has_no_rights_for_is_refused(): void
    {
        $me = $this->me();
        $me['products']['erp']['role_key'] = 'sommelier';

        $this->signIn($me)
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', fn (string $message) => str_contains($message, '"sommelier"'));

        $this->assertGuest();
    }

    public function test_an_outlet_erp_has_not_heard_of_yet_is_told_to_wait(): void
    {
        $unknown = Ulid::generate();
        $me = $this->me();
        $me['products']['erp']['outlet_ids'] = [$unknown];

        $this->signIn($me)
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'still setting up'));

        $this->assertGuest();
    }

    public function test_an_outlet_of_another_business_is_not_let_in_by_naming_it(): void
    {
        $other = $this->business('Rival Dhaba');
        $me = $this->me();
        $me['products']['erp']['outlet_ids'] = [$this->at['outlet'], $other['outlet']];

        $this->signIn($me)->assertRedirect(route('home'));

        $this->assertSame([$this->at['outlet']], User::query()->sole()->outlet_ids);
    }

    public function test_platform_staff_get_in_with_no_business_and_no_role(): void
    {
        $me = $this->me(['tenant_id' => null, 'platform_admin' => true, 'outlets' => [], 'products' => []]);

        $this->signIn($me)->assertRedirect(route('home'));

        $user = User::query()->sole();
        $this->assertTrue($user->is_platform_admin);
        $this->assertNull($user->tenant_id);
        $this->assertTrue($user->can('settings.manage'));
    }

    /* ------------------------------------------------ what a role may open */

    public function test_a_role_opens_what_it_is_for_and_nothing_else(): void
    {
        $this->signInAs('storekeeper', $this->at);

        $this->get(route('stock.index'))->assertOk();
        // Receiving is the storekeeper's job; the item catalogue is not.
        $this->get(route('items.create'))->assertForbidden();
        $this->get(route('stores.index'))->assertForbidden();
    }

    public function test_an_outlet_that_has_not_bought_erp_is_turned_away(): void
    {
        $crmOnly = $this->business('Chai Point', ['crm' => 'active']);

        $this->signInAs('owner', $crmOnly);

        $this->get('/')->assertForbidden()->assertSee('has not bought Khana ERP');
    }

    public function test_a_suspended_outlet_can_look_but_not_change(): void
    {
        $suspended = $this->business('Late Payers', ['erp' => 'suspended']);

        $this->signInAs('owner', $suspended);

        $this->get(route('items.index'))->assertOk();
        $this->post(route('items.store'), ['name' => 'Paneer', 'base_unit' => 'g'])->assertForbidden();
    }

    /* --------------------------------------- what Accounts says in between */

    public function test_taking_an_outlet_away_takes_effect_without_a_new_sign_in(): void
    {
        $second = $this->business('Spice Garden', ['erp' => 'active'], $this->at['tenant']);
        $user = $this->signInAs('storekeeper', $this->at, [$second['outlet']]);

        $this->deliver('accounts.access.changed', [
            'user_id' => $user->ulid, 'product' => 'erp', 'role_key' => 'auditor', 'outlet_ids' => [$second['outlet']],
        ]);

        $user->refresh();
        $this->assertSame('auditor', $user->role_key);
        $this->assertSame([$second['outlet']], $user->outlet_ids);
    }

    public function test_losing_the_role_closes_everything_and_ends_the_session(): void
    {
        $user = $this->signInAs('storekeeper', $this->at);
        DB::table('sessions')->insert(['id' => 'abc', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

        $this->deliver('accounts.access.changed', [
            'user_id' => $user->ulid, 'product' => 'erp', 'role_key' => null, 'outlet_ids' => [],
        ]);

        $user->refresh();
        $this->assertNull($user->role_key);
        $this->assertSame([], $user->outlet_ids);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertFalse($user->can('stock.view'));
    }

    public function test_a_deactivated_person_is_signed_out_and_cannot_come_back(): void
    {
        $me = $this->me();
        $this->signIn($me)->assertRedirect(route('home'));
        $user = User::query()->sole();
        DB::table('sessions')->insert(['id' => 'abc', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

        $this->deliver('accounts.sessions.revoked', ['user_id' => $user->ulid, 'reason' => 'deactivated']);

        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertFalse($user->fresh()->is_active);

        $this->post(route('logout'));

        $this->signIn($me)
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'switched off'));
    }

    /* ----------------------------------------------------------- the API */

    public function test_the_roles_api_needs_a_machine_token(): void
    {
        $this->getJson('/api/v1/roles?tenant_id='.$this->at['tenant'])->assertUnauthorized();
    }
}
