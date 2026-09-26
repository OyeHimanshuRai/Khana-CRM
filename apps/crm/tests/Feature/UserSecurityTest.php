<?php

namespace Tests\Feature;

use App\Models\BlockedIp;
use App\Models\LoginHistory;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserSession;
use App\Support\Agent;
use App\Support\IpLocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class UserSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const CHROME_WINDOWS = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
        .'(KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

    private const SAFARI_IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_1 like Mac OS X) '
        .'AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Mobile/15E148 Safari/604.1';

    protected function setUp(): void
    {
        parent::setUp();

        // APP_URL carries the /er sub-path, which would prefix every test
        // request and miss the routes entirely.
        URL::forceRootUrl('http://localhost');

        /*
         | Geolocation is off unless a test asks for it. Most of this suite
         | has nothing to do with it, and leaving it on would mean a catch-all
         | Http::fake() here - which registers first and therefore shadows the
         | specific fakes the geolocation tests set up.
         |
         | preventStrayRequests is the backstop: any real network call from a
         | test fails loudly instead of silently reaching ip-api.com.
         */
        config(['ip_lookup.enabled' => false]);
        Http::preventStrayRequests();

        $this->seed();
    }

    /** Turn geolocation on for one test, with a canned response. */
    private function fakeLookup(array $body = null): void
    {
        config(['ip_lookup.enabled' => true]);
        Cache::flush();

        Http::fake(['ip-api.com/*' => Http::response($body ?? [
            'status' => 'success',
            'country' => 'India',
            'regionName' => 'Rajasthan',
            'city' => 'Jaipur',
            'isp' => 'Airtel',
        ])]);
    }

    private function admin(): User
    {
        return User::where('is_admin', true)->firstOrFail();
    }

    private function member(array $attributes = []): User
    {
        return User::create([
            'name' => 'Rahul Sharma',
            'email' => 'rahul@example.test',
            'password' => 'member-password-1',
            'is_admin' => true,
            'is_active' => true,
            /*
             | Colleagues, explicitly.
             |
             | The security screen hands back sessions, IP addresses and
             | geolocated login history, so it refuses an account from
             | another company - and an account belonging to no company is
             | "another company" to everybody except a Super Admin. Nobody
             | is signed in while this runs, so the creating hook on User
             | has no company to copy and would leave these orphaned, which
             | is not what any test in this file is about.
             */
            'tenant_id' => Tenant::query()->value('id'),
            ...$attributes,
        ]);
    }

    private function openSession(User $user, array $attributes = []): UserSession
    {
        return UserSession::create([
            'user_id' => $user->id,
            'session_id' => 'sess-'.uniqid(),
            'ip_address' => '203.0.113.10',
            'device_type' => 'desktop',
            'device_name' => 'Windows PC',
            'browser' => 'Chrome',
            'operating_system' => 'Windows',
            'login_at' => now()->subHour(),
            'last_activity_at' => now()->subMinute(),
            ...$attributes,
        ]);
    }

    /* -------------------------------------------------------- user agent */

    public function test_the_agent_parser_reads_common_browsers(): void
    {
        $chrome = Agent::of(self::CHROME_WINDOWS);
        $this->assertSame('desktop', $chrome->deviceType());
        $this->assertSame(['Chrome', '131'], $chrome->browser());
        $this->assertSame(['Windows', '10/11'], $chrome->platform());

        $iphone = Agent::of(self::SAFARI_IPHONE);
        $this->assertSame('mobile', $iphone->deviceType());
        $this->assertSame('iPhone', $iphone->deviceName());
        $this->assertSame(['Safari', '17'], $iphone->browser());
        $this->assertSame('iOS', $iphone->platform()[0]);
    }

    public function test_edge_is_not_mistaken_for_chrome(): void
    {
        // Edge advertises Chrome in its own string, so order matters.
        $agent = Agent::of('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
            .'(KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36 Edg/131.0.2903.86');

        $this->assertSame('Edge', $agent->browser()[0]);
    }

    public function test_an_empty_agent_degrades_rather_than_guessing(): void
    {
        $agent = Agent::of(null);

        $this->assertSame('unknown', $agent->deviceType());
        $this->assertSame([null, null], $agent->browser());
        $this->assertNull($agent->deviceName());
    }

    /* ------------------------------------------------------- login record */

    public function test_a_successful_login_opens_a_session_and_a_history_row(): void
    {
        $user = $this->member();

        $this->withHeader('User-Agent', self::CHROME_WINDOWS)
            ->post('/admin/login', [
                'email' => $user->email,
                'password' => 'member-password-1',
            ])->assertRedirect();

        $session = UserSession::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('Chrome', $session->browser);
        $this->assertSame('Windows', $session->operating_system);
        $this->assertSame('desktop', $session->device_type);
        $this->assertNotNull($session->session_id);
        $this->assertNull($session->logout_at);

        $this->assertDatabaseHas('login_histories', [
            'user_id' => $user->id,
            'status' => LoginHistory::SUCCESS,
        ]);

        $fresh = $user->fresh();
        $this->assertSame(1, $fresh->login_count);
        $this->assertNotNull($fresh->last_login_at);
    }

    public function test_a_wrong_password_is_recorded_as_a_failed_attempt(): void
    {
        $user = $this->member();

        $this->post('/admin/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('password');

        $this->assertDatabaseHas('login_histories', [
            'user_id' => $user->id,
            'status' => LoginHistory::FAILED,
            'reason' => 'bad_password',
        ]);
    }

    public function test_an_unknown_email_is_recorded_without_a_user(): void
    {
        $this->post('/admin/login', [
            'email' => 'nobody@example.test',
            'password' => 'whatever-1234',
        ])->assertSessionHasErrors('email');

        $this->assertDatabaseHas('login_histories', [
            'user_id' => null,
            'email' => 'nobody@example.test',
            'reason' => 'unknown_email',
        ]);
    }

    public function test_a_deactivated_account_cannot_sign_in(): void
    {
        $user = $this->member(['is_active' => false]);

        $this->post('/admin/login', [
            'email' => $user->email,
            'password' => 'member-password-1',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertDatabaseHas('login_histories', [
            'user_id' => $user->id,
            'reason' => 'inactive',
        ]);
    }

    /* ------------------------------------------------------ account status */

    public function test_deactivating_a_user_ends_their_sessions(): void
    {
        $user = $this->member();
        $this->openSession($user);
        $this->openSession($user);

        $this->actingAs($this->admin())
            ->putJson("/admin/users/{$user->id}/status")
            ->assertOk()
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.sessions_ended', 2);

        $this->assertFalse($user->fresh()->is_active);
        $this->assertSame(0, UserSession::where('user_id', $user->id)->whereNull('logout_at')->count());
        $this->assertDatabaseHas('activity_logs', ['event' => 'user.deactivated']);
    }

    public function test_reactivating_a_user_does_not_resurrect_sessions(): void
    {
        $user = $this->member(['is_active' => false]);
        $this->openSession($user, ['logout_at' => now(), 'ended_by' => 'admin']);

        $this->actingAs($this->admin())
            ->putJson("/admin/users/{$user->id}/status")
            ->assertOk()
            ->assertJsonPath('data.is_active', true);

        $this->assertSame(0, UserSession::where('user_id', $user->id)->whereNull('logout_at')->count());
    }

    public function test_an_admin_cannot_deactivate_their_own_account(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->putJson("/admin/users/{$admin->id}/status")
            ->assertStatus(422);

        $this->assertTrue($admin->fresh()->is_active);
    }

    /* ------------------------------------------------------------ sessions */

    public function test_ending_a_session_deletes_the_framework_row(): void
    {
        // phpunit.xml runs on the array driver; the delete is only meaningful
        // on the database one, which is what the app actually uses.
        config(['session.driver' => 'database']);

        $user = $this->member();
        $session = $this->openSession($user, ['session_id' => 'framework-session-1']);

        // Stand in for the row Laravel's database session driver keeps.
        \DB::table('sessions')->insert([
            'id' => 'framework-session-1',
            'user_id' => $user->id,
            'ip_address' => '203.0.113.10',
            'user_agent' => self::CHROME_WINDOWS,
            'payload' => base64_encode('x'),
            'last_activity' => now()->timestamp,
        ]);

        $this->actingAs($this->admin())
            ->deleteJson("/admin/users/{$user->id}/sessions/{$session->id}")
            ->assertOk();

        $this->assertNotNull($session->fresh()->logout_at);
        $this->assertSame('admin', $session->fresh()->ended_by);

        // This is what actually signs the device out.
        $this->assertDatabaseMissing('sessions', ['id' => 'framework-session-1']);
    }

    public function test_a_session_belonging_to_another_user_is_a_404(): void
    {
        $user = $this->member();
        $other = $this->member(['email' => 'other@example.test']);
        $session = $this->openSession($other);

        $this->actingAs($this->admin())
            ->deleteJson("/admin/users/{$user->id}/sessions/{$session->id}")
            ->assertNotFound();

        $this->assertNull($session->fresh()->logout_at);
    }

    public function test_logging_out_all_devices_closes_every_session(): void
    {
        $user = $this->member();
        $this->openSession($user);
        $this->openSession($user);
        $this->openSession($user);

        $this->actingAs($this->admin())
            ->deleteJson("/admin/users/{$user->id}/sessions")
            ->assertOk()
            ->assertJsonPath('data.active', 0);

        $this->assertSame(3, UserSession::where('user_id', $user->id)->whereNotNull('logout_at')->count());
    }

    /* ----------------------------------------------------------- ip blocks */

    public function test_several_ips_can_be_blocked_at_once(): void
    {
        $user = $this->member();

        $this->actingAs($this->admin())
            ->postJson("/admin/users/{$user->id}/blocked-ips", [
                'ip_addresses' => "203.0.113.5, 198.51.100.22\n203.0.113.5",
                'reason' => 'Credential stuffing',
                'scope' => 'user',
                'duration' => 'permanent',
            ])
            ->assertOk();

        // The duplicate is collapsed, not stored twice.
        $this->assertSame(2, BlockedIp::count());
        $this->assertDatabaseHas('blocked_ips', [
            'ip_address' => '203.0.113.5',
            'user_id' => $user->id,
            'reason' => 'Credential stuffing',
            'expires_at' => null,
        ]);
    }

    public function test_a_malformed_ip_is_refused(): void
    {
        $user = $this->member();

        $this->actingAs($this->admin())
            ->postJson("/admin/users/{$user->id}/blocked-ips", [
                'ip_addresses' => '203.0.113.5, not-an-ip',
                'scope' => 'user',
                'duration' => 'permanent',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['ip_addresses']]);

        // Nothing is stored, so the admin is not left with a partial block.
        $this->assertSame(0, BlockedIp::count());
    }

    public function test_a_blocked_ip_stops_that_account_signing_in(): void
    {
        $user = $this->member();

        BlockedIp::create([
            'ip_address' => '127.0.0.1',
            'user_id' => $user->id,
            'blocked_at' => now(),
            'status' => BlockedIp::ACTIVE,
        ]);

        $this->post('/admin/login', [
            'email' => $user->email,
            'password' => 'member-password-1',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertDatabaseHas('login_histories', [
            'user_id' => $user->id,
            'status' => LoginHistory::BLOCKED,
            'reason' => 'ip_blocked',
        ]);
    }

    public function test_a_global_block_stops_the_login_screen_entirely(): void
    {
        BlockedIp::create([
            'ip_address' => '127.0.0.1',
            'user_id' => null,
            'blocked_at' => now(),
            'status' => BlockedIp::ACTIVE,
        ]);

        $this->get('/admin/login')->assertForbidden();
    }

    public function test_an_expired_block_no_longer_bites(): void
    {
        $user = $this->member();

        BlockedIp::create([
            'ip_address' => '127.0.0.1',
            'user_id' => $user->id,
            'blocked_at' => now()->subDays(2),
            'expires_at' => now()->subDay(),
            'status' => BlockedIp::ACTIVE,
        ]);

        $this->assertNull(BlockedIp::blocks('127.0.0.1', $user));

        $this->post('/admin/login', [
            'email' => $user->email,
            'password' => 'member-password-1',
        ])->assertRedirect();

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_block_can_be_lifted(): void
    {
        $user = $this->member();

        $block = BlockedIp::create([
            'ip_address' => '203.0.113.5',
            'user_id' => $user->id,
            'blocked_at' => now(),
            'status' => BlockedIp::ACTIVE,
        ]);

        $this->actingAs($this->admin())
            ->deleteJson("/admin/users/{$user->id}/blocked-ips/{$block->id}")
            ->assertOk();

        $this->assertSame(BlockedIp::LIFTED, $block->fresh()->status);
        $this->assertNull(BlockedIp::blocks('203.0.113.5', $user));
    }

    /* ------------------------------------------------------------ presence */

    public function test_presence_follows_last_seen_not_session_rows(): void
    {
        $stale = $this->member(['email' => 'stale@example.test']);
        $stale->forceFill(['last_seen_at' => now()->subHour()])->save();

        // A session left open by a closed browser must not read as online.
        $this->openSession($stale, ['last_activity_at' => now()->subHour()]);

        $live = $this->member(['email' => 'live@example.test']);
        $live->forceFill(['last_seen_at' => now()->subMinute()])->save();

        $this->assertFalse($stale->fresh()->isOnline());
        $this->assertTrue($live->fresh()->isOnline());
    }

    /* --------------------------------------------------------------- views */

    public function test_the_security_screen_renders(): void
    {
        $user = $this->member();
        $this->openSession($user);
        LoginHistory::record(LoginHistory::SUCCESS, $user->email, $user);

        $this->actingAs($this->admin())
            ->get("/admin/users/{$user->id}/security")
            ->assertOk()
            ->assertSee('Sessions')
            ->assertSee('Login history')
            ->assertSee('IP history')
            ->assertSee('Blocked IPs');
    }

    public function test_the_fragment_carries_no_layout_or_scripts(): void
    {
        $user = $this->member();

        $this->actingAs($this->admin())
            ->withHeader('X-Fragment', '1')
            ->get("/admin/users/{$user->id}/security")
            ->assertOk()
            ->assertDontSee('<body', false)
            ->assertDontSee('<script', false);
    }

    public function test_the_user_list_can_be_filtered_by_status_and_presence(): void
    {
        $this->member(['email' => 'off@example.test', 'name' => 'Offline Person']);

        $online = $this->member(['email' => 'on@example.test', 'name' => 'Online Person']);
        $online->forceFill(['last_seen_at' => now()])->save();

        $this->actingAs($this->admin())
            ->get('/admin/users?presence=online')
            ->assertOk()
            ->assertSee('Online Person')
            ->assertDontSee('Offline Person');

        $this->member(['email' => 'dead@example.test', 'name' => 'Dormant Person', 'is_active' => false]);

        $this->actingAs($this->admin())
            ->get('/admin/users?status=inactive')
            ->assertOk()
            ->assertSee('Dormant Person')
            ->assertDontSee('Online Person');
    }

    /* ------------------------------------------------------ ip geolocation */

    public function test_a_public_ip_is_resolved_and_cached(): void
    {
        $this->fakeLookup();

        $this->assertSame('Jaipur, Rajasthan, India', IpLocator::for('223.181.47.195'));

        // Second call must come from the cache, not the network.
        IpLocator::for('223.181.47.195');
        Http::assertSentCount(1);
    }

    public function test_a_city_that_repeats_the_region_is_not_said_twice(): void
    {
        $this->fakeLookup([
            'status' => 'success',
            'city' => 'Singapore',
            'regionName' => 'Singapore',
            'country' => 'Singapore',
        ]);

        $this->assertSame('Singapore', IpLocator::for('223.181.47.195'));
    }

    public function test_private_addresses_are_never_sent_to_the_api(): void
    {
        $this->fakeLookup();

        $this->assertSame('Local network', IpLocator::for('127.0.0.1'));
        $this->assertSame('Local network', IpLocator::for('192.168.1.20'));
        $this->assertSame('Local network', IpLocator::for('10.0.0.4'));

        Http::assertNothingSent();
    }

    public function test_a_lookup_failure_degrades_to_no_location(): void
    {
        // The API answers 200 with status:fail for a range it cannot place.
        $this->fakeLookup(['status' => 'fail', 'message' => 'reserved range']);
        $this->assertNull(IpLocator::for('223.181.47.195'));

        Cache::flush();
        Http::fake(['ip-api.com/*' => Http::response('', 500)]);
        $this->assertNull(IpLocator::for('223.181.47.196'));

        Cache::flush();
        Http::fake(fn () => throw new \RuntimeException('network down'));
        $this->assertNull(IpLocator::for('223.181.47.197'));
    }

    public function test_lookups_can_be_switched_off(): void
    {
        $this->fakeLookup();
        config(['ip_lookup.enabled' => false]);

        $this->assertNull(IpLocator::for('223.181.47.195'));
        Http::assertNothingSent();
    }

    public function test_the_security_screen_shows_a_resolved_location(): void
    {
        $this->fakeLookup();

        $user = $this->member();
        $session = $this->openSession($user, ['ip_address' => '223.181.47.195']);

        $this->actingAs($this->admin())
            ->get("/admin/users/{$user->id}/security")
            ->assertOk()
            ->assertSee('Jaipur, Rajasthan, India');

        // Stored on the row, so it survives the cache expiring.
        $this->assertSame('Jaipur, Rajasthan, India', $session->fresh()->location);
    }

    public function test_signing_in_never_calls_the_geolocation_api(): void
    {
        $this->fakeLookup();

        $user = $this->member();

        $this->post('/admin/login', [
            'email' => $user->email,
            'password' => 'member-password-1',
        ])->assertRedirect();

        // A third-party outage must not slow down or break signing in.
        Http::assertNothingSent();
    }

    /* --------------------------------------------------------- permissions */

    public function test_the_security_actions_need_their_permissions(): void
    {
        $viewer = $this->member(['email' => 'viewer@example.test']);
        $viewer->givePermissionTo('settings.sessions.view');

        $target = $this->member(['email' => 'target@example.test']);
        $session = $this->openSession($target);

        // Viewing is allowed...
        $this->actingAs($viewer)->get("/admin/users/{$target->id}/security")->assertOk();

        // ...but nothing destructive is.
        $this->actingAs($viewer)->putJson("/admin/users/{$target->id}/status")->assertForbidden();
        $this->actingAs($viewer)->deleteJson("/admin/users/{$target->id}/sessions")->assertForbidden();
        $this->actingAs($viewer)
            ->deleteJson("/admin/users/{$target->id}/sessions/{$session->id}")
            ->assertForbidden();
        $this->actingAs($viewer)
            ->postJson("/admin/users/{$target->id}/blocked-ips", [
                'ip_addresses' => '203.0.113.5',
                'scope' => 'user',
                'duration' => 'permanent',
            ])
            ->assertForbidden();
    }
}
