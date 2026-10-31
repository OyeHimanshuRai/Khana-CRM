<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\User;
use App\Support\CurrentShop;
use App\Support\CurrentTenant;
use App\Support\WeakPasswords;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Somebody who signs in with "password" is told, and in production is kept on
 * the profile screen until they choose another.
 */
class WeakPasswordTest extends TestCase
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

    private function owner(string $password): User
    {
        $shop = Shop::query()->orderBy('id')->firstOrFail();

        $user = User::create([
            'tenant_id' => $shop->tenant_id, 'name' => 'Owner', 'email' => 'owner@example.test',
            'password' => $password, 'is_admin' => true, 'is_active' => true,
        ]);
        $user->assignRole('Tenant Owner');
        $user->shops()->syncWithoutDetaching([$shop->id => ['is_default' => true]]);
        $user->forceFill(['current_shop_id' => $shop->id, 'all_shops_view' => false])->save();

        return $user;
    }

    private function signIn(string $password): void
    {
        $this->post(route('login.store'), ['email' => 'owner@example.test', 'password' => $password])->assertRedirect();
    }

    public function test_the_list_catches_the_usual_suspects_case_insensitively(): void
    {
        foreach (['password', 'PASSWORD', 'Admin', '12345678', 'Qwerty123'] as $weak) {
            $this->assertTrue(WeakPasswords::matches($weak), $weak);
        }

        foreach (['Xk9#mQ2$vLp7wZ', 'correct horse battery', 'Demo@12345'] as $fine) {
            $this->assertFalse(WeakPasswords::matches($fine), $fine);
        }
    }

    public function test_a_weak_sign_in_shows_the_warning_banner_but_is_not_locked_out_locally(): void
    {
        config(['app.force_default_password_change' => false]);
        $this->owner('password');

        $this->signIn('password');

        $this->get(route('admin.dashboard'))->assertOk()->assertSee('well-known default');
    }

    public function test_a_strong_sign_in_shows_no_banner(): void
    {
        $this->owner('Xk9#mQ2$vLp7wZ');

        $this->signIn('Xk9#mQ2$vLp7wZ');

        $this->get(route('admin.dashboard'))->assertOk()->assertDontSee('well-known default');
    }

    public function test_where_it_is_enforced_every_screen_but_the_profile_is_closed(): void
    {
        config(['app.force_default_password_change' => true]);
        $this->owner('password');

        $this->signIn('password');

        $this->get(route('admin.dashboard'))->assertRedirect(route('admin.profile.edit'));
        $this->get(route('admin.products.index'))->assertRedirect(route('admin.profile.edit'));
        $this->getJson(route('admin.customers.index'))->assertForbidden();

        // The way out stays open.
        $this->get(route('admin.profile.edit'))->assertOk();
    }

    public function test_choosing_a_new_password_lifts_the_lock_at_once(): void
    {
        config(['app.force_default_password_change' => true]);
        $this->owner('password');
        $this->signIn('password');

        $this->putJson(route('admin.profile.password'), [
            'current_password' => 'password', 'password' => 'Xk9#mQ2$vLp7wZ', 'password_confirmation' => 'Xk9#mQ2$vLp7wZ',
        ])->assertOk();

        $this->get(route('admin.dashboard'))->assertOk()->assertDontSee('well-known default');
        $this->assertTrue(Hash::check('Xk9#mQ2$vLp7wZ', User::query()->withoutGlobalScopes()->where('email', 'owner@example.test')->value('password')));
    }

    public function test_the_audit_still_flags_the_stored_password_of_an_account_that_never_signed_in(): void
    {
        $this->owner('password');

        $this->artisan('security:audit')->expectsOutputToContain('owner@example.test')->assertExitCode(1);
    }
}
