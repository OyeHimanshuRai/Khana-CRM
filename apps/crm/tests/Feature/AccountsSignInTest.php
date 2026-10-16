<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Khana\Kit\Support\Ulid;
use Khana\Kit\Sso\SignInClient;
use Tests\TestCase;

/**
 * Signing in to CRM through Accounts.
 *
 * Driven through the kit's real /sso/login and /sso/callback, with Accounts'
 * two answers - the token and GET /api/v1/me - faked at the HTTP edge. What
 * matters is what CRM makes of them: the business, outlets, person and role
 * mirrored into its own rows, found again rather than duplicated the second
 * time, and a refusal with a reason whenever something cannot be matched.
 */
class AccountsSignInTest extends TestCase
{
    use RefreshDatabase;

    private const ACCOUNTS = 'https://accounts.khana.test';

    /** @var array<string, mixed> What the faked /me answers with right now. */
    private array $currentMe = [];

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');

        config([
            'khana.sso.accounts_url' => self::ACCOUNTS,
            'khana.sso.client_id' => 'crm-browser',
            'auth.local_login' => null,
        ]);

        // Faked once: a second Http::fake() adds stubs behind the first,
        // so a later sign-in would still be answered with the first /me.
        Http::fake([
            self::ACCOUNTS.'/oauth/token' => Http::response(['access_token' => 'person-token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
            self::ACCOUNTS.'/api/v1/me' => fn () => Http::response($this->currentMe),
        ]);
    }

    /**
     * What Accounts' /me would say about a cashier at one outlet.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function me(array $overrides = []): array
    {
        $outlet = $overrides['_outlet'] ?? Ulid::generate();
        unset($overrides['_outlet']);

        return array_replace([
            'user_id' => Ulid::generate(),
            'name' => 'Asha Cashier',
            'email' => 'asha@spicegarden.test',
            'mobile' => '9800000001',
            'tenant_id' => Ulid::generate(),
            'platform_admin' => false,
            'outlets' => [['outlet_id' => $outlet, 'name' => 'Spice Garden MG Road', 'code' => 'SGMG']],
            'products' => ['crm' => ['role_key' => 'cashier', 'outlet_ids' => [$outlet]]],
        ], $overrides);
    }

    /** @param  array<string, mixed>  $me */
    private function signIn(array $me): \Illuminate\Testing\TestResponse
    {
        $this->get(route('khana.sso.login'))
            ->assertRedirect()
            ->assertRedirectContains(self::ACCOUNTS.'/oauth/authorize');

        $state = session(SignInClient::SESSION_KEY)['state'];

        $this->currentMe = $me;

        return $this->get(route('khana.sso.callback', ['code' => 'one-time-code', 'state' => $state]));
    }

    public function test_a_new_person_brings_their_business_outlet_and_role_with_them(): void
    {
        $me = $this->me();

        $this->signIn($me)->assertRedirect(route('admin.dashboard'));

        $user = User::query()->where('ulid', $me['user_id'])->firstOrFail();
        $tenant = Tenant::query()->where('ulid', $me['tenant_id'])->firstOrFail();
        $shop = Shop::query()->where('ulid', $me['outlets'][0]['outlet_id'])->firstOrFail();

        $this->assertAuthenticatedAs($user);
        $this->assertSame('Asha Cashier', $user->name);
        $this->assertSame($tenant->id, (int) $user->tenant_id);
        $this->assertSame($tenant->id, (int) $shop->tenant_id);
        $this->assertSame('SGMG', $shop->code);
        $this->assertTrue($user->is_admin, 'Every member of staff needs the back office; the role decides the rest.');
        $this->assertSame(['Cashier'], $user->getRoleNames()->all());
        $this->assertSame([$shop->id], $user->shops()->pluck('shops.id')->all());
        $this->assertSame($shop->id, (int) $user->current_shop_id);
    }

    public function test_signing_out_also_signs_out_of_accounts(): void
    {
        $this->signIn($this->me());

        $this->post(route('admin.logout'))
            ->assertRedirect(SignInClient::logoutUrl(route('admin.login')));

        $this->assertGuest();
    }

    public function test_signing_in_again_finds_the_same_rows_and_takes_the_new_details(): void
    {
        $me = $this->me();
        $this->signIn($me);
        $this->post(route('admin.logout'));

        $counts = [User::query()->count(), Tenant::query()->count(), Shop::query()->count()];

        $this->signIn(array_replace($me, [
            'name' => 'Asha Rao',
            'products' => ['crm' => ['role_key' => 'manager', 'outlet_ids' => $me['products']['crm']['outlet_ids']]],
        ]))->assertRedirect(route('admin.dashboard'));

        $this->assertSame($counts, [User::query()->count(), Tenant::query()->count(), Shop::query()->count()]);

        $user = User::query()->where('ulid', $me['user_id'])->firstOrFail();

        $this->assertSame('Asha Rao', $user->name);
        $this->assertSame(['Manager'], $user->getRoleNames()->all(), 'A role changed in Accounts takes effect at the next sign-in.');
    }

    public function test_only_the_outlets_given_for_crm_become_shops(): void
    {
        $crmOutlet = Ulid::generate();
        $erpOnly = Ulid::generate();

        $me = $this->me([
            '_outlet' => $crmOutlet,
            'outlets' => [
                ['outlet_id' => $crmOutlet, 'name' => 'Front of house', 'code' => 'FOH1'],
                ['outlet_id' => $erpOnly, 'name' => 'Central kitchen', 'code' => 'CK1'],
            ],
        ]);

        $this->signIn($me)->assertRedirect(route('admin.dashboard'));

        $this->assertTrue(Shop::query()->where('ulid', $crmOutlet)->exists());
        $this->assertFalse(Shop::query()->where('ulid', $erpOnly)->exists());
    }

    public function test_an_existing_local_account_with_the_same_email_is_adopted_not_duplicated(): void
    {
        $local = User::query()->create([
            'name' => 'Old Name',
            'email' => 'adopt.me@spicegarden.test',
            'password' => 'irrelevant-now',
            'is_admin' => true,
            'is_active' => true,
            'tenant_id' => null,
        ]);

        $me = $this->me(['email' => 'adopt.me@spicegarden.test']);

        $this->signIn($me)->assertRedirect(route('admin.dashboard'));

        $this->assertSame($me['user_id'], $local->fresh()->ulid);
        $this->assertSame(1, User::query()->where('email', 'adopt.me@spicegarden.test')->count());
        $this->assertAuthenticatedAs($local->fresh());
    }

    public function test_a_role_crm_has_no_rights_for_is_refused_with_the_reason(): void
    {
        $me = $this->me();
        $me['products']['crm']['role_key'] = 'sommelier';

        $this->signIn($me)
            ->assertRedirect(route('admin.login'))
            ->assertSessionHas('error', fn (string $message) => str_contains($message, '"sommelier"'));

        $this->assertGuest();
        $this->assertFalse(User::query()->where('ulid', $me['user_id'])->exists(), 'A refused sign-in must leave nothing half-created behind.');
    }

    /**
     * A business's role key that names a platform role is not a way in.
     * Only Accounts' platform flag makes somebody a platform administrator.
     */
    public function test_a_platform_role_key_inside_a_business_is_refused(): void
    {
        foreach (['super_admin', 'admin'] as $key) {
            $me = $this->me();
            $me['products']['crm']['role_key'] = $key;

            $this->signIn($me)
                ->assertRedirect(route('admin.login'))
                ->assertSessionHas('error', fn (string $message) => str_contains($message, 'belongs to the platform'));

            $this->assertGuest();
            $this->assertFalse(User::query()->where('ulid', $me['user_id'])->exists());
        }
    }

    public function test_a_person_without_crm_access_is_refused(): void
    {
        $me = $this->me(['products' => ['erp' => ['role_key' => 'storekeeper', 'outlet_ids' => []]]]);

        $this->signIn($me)
            ->assertRedirect(route('admin.login'))
            ->assertSessionHas('error');

        $this->assertGuest();
    }

    public function test_an_email_already_used_in_another_business_is_refused(): void
    {
        $other = Tenant::query()->firstOrFail();

        User::query()->create([
            'name' => 'Somebody Else',
            'email' => 'taken@elsewhere.test',
            'password' => 'irrelevant',
            'is_admin' => true,
            'is_active' => true,
            'tenant_id' => $other->id,
        ]);

        $this->signIn($this->me(['email' => 'taken@elsewhere.test']))
            ->assertRedirect(route('admin.login'))
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'another business'));

        $this->assertGuest();
    }

    public function test_a_platform_admin_is_a_super_admin_with_no_business(): void
    {
        $me = $this->me([
            'tenant_id' => null,
            'platform_admin' => true,
            'outlets' => [],
            'products' => [],
        ]);

        $this->signIn($me)->assertRedirect(route('admin.dashboard'));

        $user = User::query()->where('ulid', $me['user_id'])->firstOrFail();

        $this->assertNull($user->tenant_id);
        $this->assertTrue($user->hasRole(User::SUPER_ADMIN));
    }

    public function test_a_state_that_was_not_started_here_is_refused(): void
    {
        $this->get(route('khana.sso.callback', ['code' => 'stolen', 'state' => 'made-up']))
            ->assertRedirect(route('admin.login'))
            ->assertSessionHas('error');

        $this->assertGuest();
    }

    /* ------------------------------------------------ the sign-in page */

    public function test_with_accounts_configured_the_page_offers_accounts_and_not_a_password(): void
    {
        $this->get(route('admin.login'))
            ->assertOk()
            ->assertSee('Sign in with Khana Accounts')
            ->assertSee(route('khana.sso.login'), false)
            ->assertDontSee('name="password"', false)
            ->assertSee(self::ACCOUNTS.'/signup', false);
    }

    public function test_with_accounts_configured_a_password_post_is_refused(): void
    {
        $this->post(route('admin.login.store'), [
            'email' => config('admin.default_email'),
            'password' => config('admin.default_password'),
        ])->assertRedirect(route('admin.login'));

        $this->assertGuest();
    }

    public function test_without_accounts_the_page_keeps_the_password_form(): void
    {
        config(['khana.sso.accounts_url' => null, 'khana.sso.client_id' => null]);

        $this->get(route('admin.login'))
            ->assertOk()
            ->assertDontSee('Sign in with Khana Accounts')
            ->assertSee('name="password"', false);
    }

    public function test_the_password_form_can_be_kept_beside_accounts_on_purpose(): void
    {
        config(['auth.local_login' => true]);

        $this->get(route('admin.login'))
            ->assertOk()
            ->assertSee('Sign in with Khana Accounts')
            ->assertSee('name="password"', false);
    }
}
