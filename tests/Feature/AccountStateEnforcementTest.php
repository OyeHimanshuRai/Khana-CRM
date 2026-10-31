<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\User;
use App\Support\CurrentShop;
use App\Support\Modules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * An account's state is read on every request, not only at sign-in.
 *
 * Switching somebody off, taking away their administrator flag or one of
 * their permissions has to stop them on the very next request - through the
 * browser session they already hold, and through any API token they minted
 * while they still had the right.
 */
class AccountStateEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');
        $this->seed();
    }

    private function shop(): Shop
    {
        return Shop::query()->withoutGlobalScopes()->orderBy('id')->firstOrFail();
    }

    /** @param  array<int, string>  $permissions */
    private function staff(array $permissions = []): User
    {
        $shop = $this->shop();

        $user = User::create([
            'tenant_id' => $shop->tenant_id,
            'name' => 'Counter Staff',
            'email' => 'staff'.uniqid().'@example.test',
            'password' => 'Staff-pass-123',
            'is_admin' => true,
            'is_active' => true,
            'current_shop_id' => $shop->id,
        ]);
        $user->forceFill(['all_shops_view' => false])->save();
        $user->shops()->attach($shop->id, ['is_default' => true]);
        $user->givePermissionTo(array_merge(['dashboard.overview.view'], $permissions));

        return $user->fresh();
    }

    private function signIn(User $user): void
    {
        $this->post('/login', ['email' => $user->email, 'password' => 'Staff-pass-123'])
            ->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    /** Forget what this test process remembers, as a new request would. */
    private function nextRequest(): void
    {
        Auth::forgetGuards();
        CurrentShop::forget();
        Modules::forget();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }

    /* ------------------------------------------------------ browser session */

    public function test_a_deactivated_member_of_staff_is_signed_out_of_the_session_they_hold(): void
    {
        $user = $this->staff();
        $this->signIn($user);
        $this->get('/superadmin/dashboard')->assertOk();

        User::query()->whereKey($user->id)->update(['is_active' => false]);
        $this->nextRequest();

        $this->get('/superadmin/dashboard')->assertRedirect(route('login'));
        $this->assertGuest('web');

        // And it stays that way: the session is gone, not just this page.
        $this->nextRequest();
        $this->get('/superadmin/pos')->assertRedirect(route('login'));
    }

    public function test_a_deactivated_member_of_staff_gets_a_json_refusal_on_an_ajax_call(): void
    {
        $user = $this->staff();
        $this->signIn($user);

        User::query()->whereKey($user->id)->update(['is_active' => false]);
        $this->nextRequest();

        $this->getJson('/superadmin/dashboard')->assertForbidden()
            ->assertJsonPath('message', 'This account has been deactivated. Please contact your administrator.');
    }

    public function test_an_active_member_of_staff_is_not_disturbed(): void
    {
        $user = $this->staff();
        $this->signIn($user);
        $this->nextRequest();

        $this->get('/superadmin/dashboard')->assertOk();
        $this->assertAuthenticatedAs($user);
    }

    /* ------------------------------------------------------------ API tokens */

    public function test_a_token_stops_working_the_moment_its_owner_is_deactivated(): void
    {
        $user = $this->staff(['sales.orders.view']);
        $token = $user->createToken('pos', ['orders:read'])->plainTextToken;

        $this->getJson('/api/v1/orders', $this->bearer($token))->assertOk();

        User::query()->whereKey($user->id)->update(['is_active' => false]);
        $this->nextRequest();

        $this->getJson('/api/v1/orders', $this->bearer($token))->assertForbidden();
    }

    public function test_a_token_stops_working_when_its_owner_is_no_longer_an_administrator(): void
    {
        $user = $this->staff(['inventory.products.view']);
        $token = $user->createToken('menu', ['menu:read'])->plainTextToken;

        $this->getJson('/api/v1/menu', $this->bearer($token))->assertOk();

        User::query()->whereKey($user->id)->update(['is_admin' => false]);
        $this->nextRequest();

        $this->getJson('/api/v1/menu', $this->bearer($token))->assertForbidden();
    }

    public function test_a_token_can_do_no_more_than_its_owner_may_do_today(): void
    {
        $user = $this->staff(['sales.orders.view', 'inventory.products.view']);
        $token = $user->createToken('both', ['orders:read', 'menu:read'])->plainTextToken;

        $this->getJson('/api/v1/orders', $this->bearer($token))->assertOk();

        // The right to read orders is taken away; the token's own ability is not.
        $user->revokePermissionTo('sales.orders.view');
        $this->nextRequest();

        $this->getJson('/api/v1/orders', $this->bearer($token))->assertForbidden();
        $this->nextRequest();
        $this->getJson('/api/v1/menu', $this->bearer($token))->assertOk();
    }

    public function test_a_token_respects_the_branchs_modules(): void
    {
        $user = $this->staff(['sales.orders.view']);
        $token = $user->createToken('orders', ['orders:read'])->plainTextToken;

        // Retail is what carries online orders; a till-only branch does not take them.
        $this->shop()->forceFill(['modules' => ['pos']])->saveQuietly();
        $this->nextRequest();

        $this->getJson('/api/v1/orders', $this->bearer($token))->assertForbidden();
    }

    /* --------------------------------------------------------------- minting */

    public function test_nobody_can_mint_an_ability_they_do_not_hold_themselves(): void
    {
        $user = $this->staff(['settings.api_tokens.view', 'settings.api_tokens.create', 'inventory.products.view']);
        $this->signIn($user);

        $this->postJson('/superadmin/api-tokens', ['name' => 'Sneaky', 'abilities' => ['menu:read', 'orders:write']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('abilities');

        $this->assertSame(0, $user->tokens()->count());

        $this->postJson('/superadmin/api-tokens', ['name' => 'Fine', 'abilities' => ['menu:read']])->assertOk();
        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_a_token_without_an_expiry_lasts_a_year_not_forever(): void
    {
        $user = $this->staff(['settings.api_tokens.view', 'settings.api_tokens.create', 'inventory.products.view']);
        $this->signIn($user);

        $this->postJson('/superadmin/api-tokens', ['name' => 'Menu sync', 'abilities' => ['menu:read']])->assertOk();

        $expires = $user->tokens()->first()->expires_at;
        $this->assertNotNull($expires);
        $this->assertEqualsWithDelta(now()->addDays(365)->timestamp, $expires->timestamp, 5);
    }
}
