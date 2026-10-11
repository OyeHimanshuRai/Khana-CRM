<?php

namespace Khana\Kit\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Khana\Kit\Events\Envelope;
use Khana\Kit\Events\Inbox\Handlers;
use Khana\Kit\Events\Inbox\InboxEvent;
use Khana\Kit\Events\Outbox;
use Khana\Kit\Events\Signature;
use Khana\Kit\Support\Ulid;
use Khana\Kit\Tenancy\Context;
use Khana\Kit\Tenancy\Entitlements;
use Khana\Kit\Tenancy\OnlyEntitledOutlets;
use Khana\Kit\Tests\Concerns\SeedsReference;
use Khana\Kit\Tests\Fixtures\Staff;
use Khana\Kit\Tests\TestCase;

/**
 * How a restaurant bought Khana decides what each product shows and sends,
 * and one business's data never reaches another's.
 *
 * Four outlets cover the ways to buy:
 *
 *   SUITE      CRM and ERP
 *   CRM_ONLY   CRM
 *   ERP_ONLY   ERP
 *   LAPSED     CRM, and ERP it has since ended
 */
class PlansAndIsolationTest extends TestCase
{
    use RefreshDatabase;
    use SeedsReference;

    private const TENANT = '01M3D669MZPZP9EP7ND2XFY1W2';

    private const OTHER_TENANT = '01M3D669VCFXVZXBX0T9JTXSCC';

    private const SUITE = '01M3D669NC4JCJSRMWG4WWDRRT';

    private const CRM_ONLY = '01M3D669NWA5VJ3CD5YEJ3NZZC';

    private const ERP_ONLY = '01M3D669SYM43EBW94WTHNG61K';

    private const LAPSED = '01M3D669T00KD8F8JRQVC8AW0K';

    private const SECRETS = ['crm' => 'crm-to-erp', 'accounts' => 'accounts-to-erp'];

    /** @var list<string> */
    private array $applied = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'khana.service' => 'erp',
            'khana.events.senders' => self::SECRETS,
        ]);

        $this->app->make(Handlers::class)->on('crm.sale.recorded', 1, function (Envelope $event) {
            $this->applied[] = $event->data['sale_id'];
        });
    }

    private function seedTheFourWaysToBuy(): void
    {
        $this->seedOutlet(self::TENANT, self::SUITE, ['crm' => 'active', 'erp' => 'active'], 'Suite');
        $this->seedOutlet(self::TENANT, self::CRM_ONLY, ['crm' => 'active'], 'CRM only');
        $this->seedOutlet(self::TENANT, self::ERP_ONLY, ['erp' => 'trial'], 'ERP only');
        $this->seedOutlet(self::TENANT, self::LAPSED, ['crm' => 'active', 'erp' => 'ended'], 'Lapsed');
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    private function deliver(string $source, array $events): TestResponse
    {
        $body = json_encode(['events' => $events], JSON_UNESCAPED_SLASHES);

        return $this->call('POST', '/internal/events', [], [], [], [
            'HTTP_X_KHANA_SOURCE' => $source,
            'HTTP_X_KHANA_SIGNATURE' => Signature::sign($body, self::SECRETS[$source]),
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $body);
    }

    /**
     * @return array<string, mixed>
     */
    private function sale(string $outletId, string $tenantId = self::TENANT): array
    {
        $event = self::fixture('crm.sale.recorded');
        $event['id'] = Ulid::generate();
        $event['tenant_id'] = $tenantId;
        $event['outlet_id'] = $outletId;
        $event['data']['sale_id'] = Ulid::generate();

        return $event;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function accounts(string $type, array $overrides = [], array $data = []): array
    {
        $event = self::fixture($type);
        $event['id'] = Ulid::generate();

        return array_replace($event, $overrides, ['data' => array_replace($event['data'], $data)]);
    }

    /* ----------------------------------------------- the copy of Accounts */

    public function test_accounts_events_build_the_local_copy(): void
    {
        $this->deliver('accounts', [
            $this->accounts('accounts.tenant.upserted'),
            $this->accounts('accounts.outlet.upserted'),
            $this->accounts('accounts.entitlement.changed'),
        ])->assertOk();

        $this->assertSame('Spice Route', DB::table('khana_tenants')->where('id', self::TENANT)->value('name'));
        $this->assertSame('Asia/Kolkata', DB::table('khana_outlets')->where('id', self::SUITE)->value('timezone'));

        $entitlements = $this->app->make(Entitlements::class);
        $this->assertSame('trial', $entitlements->state(self::SUITE, 'erp'));
        $this->assertTrue($entitlements->allows(self::SUITE, 'erp'));
        $this->assertFalse($entitlements->allows(self::SUITE, 'crm'));
    }

    public function test_an_older_change_never_overwrites_a_newer_one(): void
    {
        $this->deliver('accounts', [
            $this->accounts('accounts.outlet.upserted', ['occurred_at' => '2026-09-26T06:00:00Z'], ['name' => 'Renamed']),
            $this->accounts('accounts.outlet.upserted', ['occurred_at' => '2026-09-26T05:00:00Z'], ['name' => 'Stale']),
        ])->assertOk();

        $this->assertSame('Renamed', DB::table('khana_outlets')->where('id', self::SUITE)->value('name'));
    }

    public function test_an_outlet_can_never_move_to_another_business(): void
    {
        $this->deliver('accounts', [$this->accounts('accounts.outlet.upserted')])->assertOk();

        $this->deliver('accounts', [
            $this->accounts('accounts.outlet.upserted', ['tenant_id' => self::OTHER_TENANT], ['name' => 'Hijacked']),
        ])->assertOk();

        $this->assertSame(self::TENANT, DB::table('khana_outlets')->where('id', self::SUITE)->value('tenant_id'));
        $this->assertSame(InboxEvent::FAILED, InboxEvent::query()->latest('id')->value('status'));
    }

    /* ------------------------------------------ what a receiver accepts */

    public function test_a_sale_for_an_outlet_not_known_yet_waits_then_applies(): void
    {
        $sale = $this->sale(self::SUITE);

        $this->deliver('crm', [$sale])->assertOk();

        $waiting = InboxEvent::query()->sole();
        $this->assertSame(InboxEvent::PENDING, $waiting->status);
        $this->assertStringContainsString('not known here yet', (string) $waiting->error);
        $this->assertSame([], $this->applied);

        // Accounts catches up; the held sale goes straight through after it.
        $this->deliver('accounts', [
            $this->accounts('accounts.tenant.upserted'),
            $this->accounts('accounts.outlet.upserted'),
            $this->accounts('accounts.entitlement.changed'),
        ])->assertOk();

        $this->assertSame(InboxEvent::APPLIED, $waiting->fresh()->status);
        $this->assertSame([$sale['data']['sale_id']], $this->applied);
    }

    public function test_a_sale_that_names_the_wrong_business_is_refused(): void
    {
        $this->seedTheFourWaysToBuy();
        $this->seedOutlet(self::OTHER_TENANT, '01M3D669TA85CPED1T244XJN2Q', ['erp' => 'active']);

        // A sender - buggy or hostile - claims the Suite outlet for a stranger.
        $this->deliver('crm', [$this->sale(self::SUITE, self::OTHER_TENANT)])->assertOk();

        $event = InboxEvent::query()->sole();
        $this->assertSame(InboxEvent::FAILED, $event->status);
        $this->assertStringContainsString('belongs to '.self::TENANT, (string) $event->error);
        $this->assertSame([], $this->applied);
    }

    public function test_a_sale_for_an_outlet_that_ended_erp_is_ignored(): void
    {
        $this->seedTheFourWaysToBuy();

        $this->deliver('crm', [$this->sale(self::LAPSED)])->assertOk();

        $event = InboxEvent::query()->sole();
        $this->assertSame(InboxEvent::IGNORED, $event->status);
        $this->assertStringContainsString('no longer has erp', (string) $event->skip_reason);
        $this->assertSame([], $this->applied);
    }

    public function test_a_suspended_erp_still_keeps_its_books(): void
    {
        $this->seedOutlet(self::TENANT, self::SUITE, ['crm' => 'active', 'erp' => 'suspended']);

        $this->deliver('crm', [$this->sale(self::SUITE)])->assertOk();

        $this->assertCount(1, $this->applied);
    }

    /* ------------------------------------------------ what a sender sends */

    public function test_crm_sends_erp_only_the_outlets_that_own_erp(): void
    {
        $this->seedTheFourWaysToBuy();
        $this->seedOutlet(self::TENANT, '01M3D669TCNVW32QSMS0FB7YT9', ['crm' => 'active', 'erp' => 'suspended'], 'Suspended');

        config([
            'khana.service' => 'crm',
            'khana.events.subscribers' => [
                'erp' => ['url' => 'https://erp.test/internal/events', 'secret' => 's', 'types' => ['crm.*'], 'filter' => OnlyEntitledOutlets::class],
            ],
        ]);

        Http::fake(['https://erp.test/*' => Http::response(['stored' => 1])]);

        $outbox = $this->app->make(Outbox::class);

        DB::transaction(function () use ($outbox) {
            foreach ([self::SUITE, self::CRM_ONLY, self::LAPSED, '01M3D669TCNVW32QSMS0FB7YT9'] as $outlet) {
                $outbox->record('crm.sale.cancelled', ['sale_id' => Ulid::generate(), 'reason' => 'test'], self::TENANT, $outlet);
            }
        });

        $sentTo = [];

        Http::assertSent(function (Request $request) use (&$sentTo) {
            foreach (json_decode($request->body(), true)['events'] as $event) {
                $sentTo[] = $event['outlet_id'];
            }

            return true;
        });

        // The CRM-only outlet's sales go nowhere; the lapsed one's stopped.
        $this->assertSame([self::SUITE, '01M3D669TCNVW32QSMS0FB7YT9'], $sentTo);
    }

    /* -------------------------------------------- the four ways to buy */

    public function test_each_way_to_buy_opens_exactly_its_own_products(): void
    {
        $this->seedTheFourWaysToBuy();
        $entitlements = $this->app->make(Entitlements::class);

        $this->assertSame(['crm', 'erp'], $entitlements->products(self::SUITE));
        $this->assertTrue($entitlements->isSuite(self::SUITE));

        $this->assertSame(['crm'], $entitlements->products(self::CRM_ONLY));
        $this->assertFalse($entitlements->isSuite(self::CRM_ONLY));

        $this->assertSame(['erp'], $entitlements->products(self::ERP_ONLY));
        $this->assertFalse($entitlements->isSuite(self::ERP_ONLY));

        $this->assertSame(['crm'], $entitlements->products(self::LAPSED));
        $this->assertFalse($entitlements->owns(self::LAPSED, 'erp'));

        $this->assertSame([], $entitlements->products('01M3D669TCNVW32QSMS0FB7YT9'));
    }

    private function productRoutes(): void
    {
        Route::middleware('entitled:crm')->match(['GET', 'POST'], '/crm-screen', fn () => 'crm');
        Route::middleware('entitled:erp')->match(['GET', 'POST'], '/erp-screen', fn () => 'erp');
    }

    private function signIn(Staff $staff): void
    {
        $this->actingAs($staff);
        $this->app->make(Context::class)->forget();
    }

    public function test_a_crm_only_restaurant_uses_crm_and_is_told_it_has_not_bought_erp(): void
    {
        $this->seedTheFourWaysToBuy();
        $this->productRoutes();
        $this->signIn(Staff::at(self::TENANT, [self::CRM_ONLY]));

        $this->get('/crm-screen')->assertOk();
        $this->post('/crm-screen')->assertOk();
        $this->get('/erp-screen')->assertForbidden()->assertSee('has not bought Khana ERP');
    }

    public function test_an_erp_only_restaurant_uses_erp_and_not_crm(): void
    {
        $this->seedTheFourWaysToBuy();
        $this->productRoutes();
        $this->signIn(Staff::at(self::TENANT, [self::ERP_ONLY]));

        $this->get('/erp-screen')->assertOk();
        $this->getJson('/crm-screen')->assertForbidden()->assertJson(['message' => 'This outlet has not bought Khana CRM.']);
    }

    public function test_a_suspended_product_can_be_viewed_but_not_changed(): void
    {
        $this->seedOutlet(self::TENANT, self::SUITE, ['crm' => 'suspended']);
        $this->productRoutes();
        $this->signIn(Staff::at(self::TENANT, [self::SUITE]));

        $this->get('/crm-screen')->assertOk();
        $this->post('/crm-screen')->assertForbidden()->assertSee('suspended');
    }

    public function test_the_consolidated_view_opens_a_product_any_reachable_outlet_owns(): void
    {
        $this->seedTheFourWaysToBuy();
        $this->productRoutes();

        $staff = Staff::at(self::TENANT, [self::CRM_ONLY, self::ERP_ONLY]);
        $staff->all = true;
        $this->signIn($staff);

        $this->get('/crm-screen')->assertOk();
        $this->get('/erp-screen')->assertOk();
    }

    public function test_platform_staff_are_never_locked_out_of_a_lapsed_outlet(): void
    {
        $this->productRoutes();
        $this->signIn(Staff::platformAdmin());

        $this->post('/crm-screen')->assertOk();
        $this->post('/erp-screen')->assertOk();
    }

    /* ------------------------------------------------ signing out everywhere */

    public function test_signing_out_everywhere_ends_every_session_and_device_token(): void
    {
        if (! Schema::hasTable('sessions')) {
            Schema::create('sessions', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('user_id')->nullable();
                $table->longText('payload');
                $table->integer('last_activity');
            });
        }

        if (! Schema::hasTable('personal_access_tokens')) {
            Schema::create('personal_access_tokens', function (Blueprint $table) {
                $table->id();
                $table->string('tokenable_type');
                $table->string('tokenable_id');
                $table->string('name');
                $table->string('token', 64);
            });
        }

        $revoked = self::fixture('accounts.sessions.revoked')['data']['user_id'];
        $kept = '01M3D669Y1PKV4YT9X510Y63QM';

        foreach ([$revoked, $kept] as $user) {
            DB::table('sessions')->insert(['id' => 'sess-'.$user, 'user_id' => $user, 'payload' => '', 'last_activity' => time()]);
            DB::table('personal_access_tokens')->insert(['tokenable_type' => 'user', 'tokenable_id' => $user, 'name' => 'kitchen screen', 'token' => substr(hash('sha256', $user), 0, 64)]);
        }

        $this->seedOutlet(self::TENANT, self::SUITE);
        $this->deliver('accounts', [$this->accounts('accounts.sessions.revoked')])->assertOk();

        $this->assertSame([$kept], DB::table('sessions')->pluck('user_id')->all());
        $this->assertSame([$kept], DB::table('personal_access_tokens')->pluck('tokenable_id')->all());
    }
}
