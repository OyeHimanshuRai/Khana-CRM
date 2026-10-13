<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\User;
use App\Support\CurrentShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Khana\Kit\Support\Ulid;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

// App sign-in: Accounts exchange, local password, abilities from role, logout.
class AppSignInTest extends TestCase
{
    use RefreshDatabase;

    private const ACCOUNTS = 'https://accounts.khana.test';

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');
        CurrentShop::forget();
    }

    protected function tearDown(): void
    {
        CurrentShop::forget();

        parent::tearDown();
    }

    private function throughAccounts(): void
    {
        config([
            'khana.sso.accounts_url' => self::ACCOUNTS,
            'khana.sso.client_id' => 'crm-browser',
            'auth.local_login' => null,
        ]);
    }

    // Fake Accounts /me answer for one outlet.
    private function me(string $role = 'captain'): array
    {
        $outlet = Ulid::generate();

        return [
            'user_id' => Ulid::generate(),
            'name' => 'Ravi Captain',
            'email' => 'ravi@spicegarden.test',
            'mobile' => '9800000002',
            'tenant_id' => Ulid::generate(),
            'platform_admin' => false,
            'outlets' => [['outlet_id' => $outlet, 'name' => 'Spice Garden MG Road', 'code' => 'SGMG']],
            'products' => ['crm' => ['role_key' => $role, 'outlet_ids' => [$outlet]]],
        ];
    }

    private function staff(string $role, string $password = 'secret-pass'): User
    {
        $shop = Shop::query()->orderBy('id')->firstOrFail();

        $user = User::factory()->create([
            'tenant_id' => $shop->tenant_id,
            'is_admin' => true,
            'password' => $password,
        ]);

        $user->assignRole($role);
        $user->shops()->syncWithoutDetaching([$shop->id => ['is_default' => true]]);
        $user->forceFill(['current_shop_id' => $shop->id, 'all_shops_view' => false])->save();

        return $user->fresh();
    }

    public function test_an_accounts_token_becomes_a_crm_token_with_the_roles_abilities(): void
    {
        $this->throughAccounts();
        $me = $this->me();

        Http::fake([self::ACCOUNTS.'/api/v1/me' => Http::response($me)]);

        $response = $this->postJson('/api/v1/auth/exchange', [
            'access_token' => 'accounts-token',
            'device_name' => 'Ravi\'s phone',
        ])->assertOk();

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer accounts-token'));

        $user = User::query()->where('ulid', $me['user_id'])->firstOrFail();

        $this->assertSame(['Captain / Waiter'], $user->getRoleNames()->all());
        $this->assertSame(['menu:read', 'tables:read', 'orders:read', 'orders:write'], $response->json('abilities'));
        $this->assertSame('Spice Garden MG Road', $response->json('shop.name'));

        $token = PersonalAccessToken::findToken($response->json('token'));

        $this->assertSame("App: Ravi's phone", $token->name);
        $this->assertFalse($token->can('menu:write'), 'A captain cannot mark dishes sold out from the app.');
        $this->assertTrue($token->expires_at->between(now()->addDays(29), now()->addDays(31)));

        $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$response->json('token')])
            ->assertOk()
            ->assertJsonPath('name', 'Ravi Captain');
    }

    public function test_a_token_accounts_refuses_signs_nobody_in(): void
    {
        $this->throughAccounts();

        Http::fake([self::ACCOUNTS.'/api/v1/me' => Http::response(['message' => 'Unauthenticated.'], 401)]);

        $this->postJson('/api/v1/auth/exchange', ['access_token' => 'stolen-or-expired'])
            ->assertForbidden();

        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    public function test_a_person_without_crm_is_refused_with_the_reason(): void
    {
        $this->throughAccounts();
        $me = array_replace($this->me(), ['products' => ['erp' => ['role_key' => 'manager', 'outlet_ids' => []]]]);

        Http::fake([self::ACCOUNTS.'/api/v1/me' => Http::response($me)]);

        $this->postJson('/api/v1/auth/exchange', ['access_token' => 'accounts-token'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Your business has not given you access to CRM. Ask the owner to add you in Accounts.');
    }

    public function test_exchange_is_not_offered_where_there_is_no_accounts(): void
    {
        config(['khana.sso.accounts_url' => null, 'khana.sso.client_id' => null]);

        $this->postJson('/api/v1/auth/exchange', ['access_token' => 'anything'])->assertNotFound();
    }

    public function test_a_password_signs_in_where_there_is_no_accounts(): void
    {
        config(['khana.sso.accounts_url' => null, 'khana.sso.client_id' => null, 'auth.local_login' => null]);
        $user = $this->staff('Captain / Waiter');

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'secret-pass',
        ])->assertOk();

        $this->assertSame(['menu:read', 'tables:read', 'orders:read', 'orders:write'], $response->json('abilities'));
    }

    public function test_a_wrong_password_gets_no_token(): void
    {
        config(['khana.sso.accounts_url' => null, 'khana.sso.client_id' => null, 'auth.local_login' => null]);
        $user = $this->staff('Captain / Waiter');

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    public function test_the_password_route_is_closed_once_accounts_signs_people_in(): void
    {
        $this->throughAccounts();
        $user = $this->staff('Captain / Waiter');

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'secret-pass'])
            ->assertForbidden();
    }

    public function test_a_role_with_nothing_to_do_in_the_app_gets_no_token(): void
    {
        config(['khana.sso.accounts_url' => null, 'khana.sso.client_id' => null, 'auth.local_login' => null]);
        Role::create(['name' => 'Nothing Here', 'guard_name' => 'web']);
        $user = $this->staff('Nothing Here');

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'secret-pass'])
            ->assertForbidden();

        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    public function test_signing_out_ends_this_phone_only(): void
    {
        $user = $this->staff('Captain / Waiter');
        $phone = $user->createToken('App: phone', ['orders:read'])->plainTextToken;
        $tablet = $user->createToken('App: tablet', ['orders:read'])->plainTextToken;

        $this->postJson('/api/v1/auth/logout', [], ['Authorization' => 'Bearer '.$phone])->assertOk();

        $this->assertNull(PersonalAccessToken::findToken($phone));
        $this->assertNotNull(PersonalAccessToken::findToken($tablet));
    }
}
