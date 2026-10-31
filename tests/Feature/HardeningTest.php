<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rules\Password;
use RuntimeException;
use Tests\TestCase;

class HardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');
    }

    public function test_every_response_carries_the_browser_hardening_headers(): void
    {
        $response = $this->get('/')->assertOk();

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->assertStringContainsString('microphone=()', $response->headers->get('Permissions-Policy'));
        // Plain HTTP in a test: HSTS must not be sent.
        $response->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_a_header_a_controller_set_itself_is_not_overwritten(): void
    {
        $this->app['router']->get('/__x', fn () => response('x')->header('X-Frame-Options', 'DENY'))
            ->middleware('web');

        $this->get('/__x')->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_production_refuses_to_seed_a_weak_super_admin_password(): void
    {
        $this->app['env'] = 'production';
        config(['admin.default_email' => 'owner@example.com', 'admin.default_password' => 'password']);

        $this->expectException(RuntimeException::class);
        (new AdminUserSeeder)->run();
    }

    public function test_production_accepts_a_strong_super_admin_password(): void
    {
        $this->app['env'] = 'production';
        config(['admin.default_email' => 'owner@example.com', 'admin.default_password' => 'Xk9#mQ2$vLp7wZ']);

        (new AdminUserSeeder)->run();

        $user = User::query()->withoutGlobalScopes()->where('email', 'owner@example.com')->firstOrFail();
        $this->assertTrue(Hash::check('Xk9#mQ2$vLp7wZ', $user->password));
    }

    public function test_the_production_password_policy_is_stricter(): void
    {
        $this->app['env'] = 'production';

        $rule = Password::defaults();
        $fails = fn (string $p) => validator(['p' => $p], ['p' => [$rule]])->fails();

        $this->assertTrue($fails('abcdefgh'));      // too short, one case, no number
        $this->assertTrue($fails('abcdefghij1'));   // no upper case
        $this->assertFalse($fails('Abcdefghij1'));
    }

    public function test_the_audit_command_flags_a_well_known_password(): void
    {
        User::factory()->create(['email' => 'weak@example.com', 'password' => 'password']);

        $this->artisan('security:audit')
            ->expectsOutputToContain('weak@example.com')
            ->assertExitCode(1);
    }
}
