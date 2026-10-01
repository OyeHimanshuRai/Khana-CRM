<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use App\Services\OwnMachineToken;
use App\Services\RoleCatalog;
use App\Services\Signup;
use App\Services\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Khana\Kit\Events\OutboxEvent;
use Khana\Kit\Sso\InsufficientScope;
use Khana\Kit\Sso\MachineTokenVerifier;
use Tests\TestCase;

/**
 * The team screen offers each product's presets and, after them, the roles
 * the business made for itself in that product - which only the product
 * knows, so it is asked. A product that does not answer costs the screen
 * nothing but those extra roles.
 */
final class TeamRolesTest extends TestCase
{
    use RefreshDatabase;

    private const CRM = 'https://crm.example.test';

    private const ERP = 'https://erp.example.test';

    private User $owner;

    private string $publicKey;

    /** What each product answers its roles with right now; null is "it is down". */
    private ?array $erpRoles;

    private ?array $crmRoles;

    protected function setUp(): void
    {
        parent::setUp();

        [$private, $this->publicKey] = self::keyPair();

        config([
            'khana.urls.crm' => self::CRM,
            'khana.urls.erp' => self::ERP,
            // The key Passport signs with; Accounts signs its own token with it too.
            'passport.private_key' => $private,
        ]);

        $preset = fn (string $key, string $name) => ['key' => $key, 'name' => $name, 'preset' => true];

        $this->erpRoles = [
            $preset('owner', 'Owner'), $preset('storekeeper', 'Storekeeper'),
            ['key' => 'head_chef', 'name' => 'Head Chef', 'preset' => false],
        ];
        $this->crmRoles = [$preset('owner', 'Owner'), $preset('cashier', 'Cashier')];

        // Faked once: a second Http::fake() adds stubs behind the first.
        Http::fake([
            self::ERP.'/api/v1/roles*' => fn () => $this->erpRoles === null ? Http::response(null, 503) : Http::response(['product' => 'erp', 'roles' => $this->erpRoles]),
            self::CRM.'/api/v1/roles*' => fn () => $this->crmRoles === null ? Http::response(null, 503) : Http::response(['product' => 'crm', 'roles' => $this->crmRoles]),
        ]);

        $plan = Plan::create([
            'code' => 'suite', 'name' => 'Suite', 'products' => ['crm', 'erp'],
            'monthly_price_paise' => 149900, 'yearly_price_paise' => 1499000,
            'trial_days' => 14, 'is_active' => true, 'sort_order' => 0,
        ]);

        $this->owner = app(Signup::class)->register(
            business: ['name' => 'Spice Route'],
            outlet: ['name' => 'Ground Floor', 'code' => 'GF', 'city' => 'Delhi', 'state_code' => '07'],
            owner: ['name' => 'Asha', 'email' => 'asha@spice.test', 'password' => 'secret12'],
            plan: $plan,
        );
    }

    /**
     * A signing key made for the run, as Passport's would be. Generated, not
     * committed: a private key in the repository is a private key in the
     * repository.
     *
     * @return array{0: string, 1: string} private, public
     */
    private static function keyPair(): array
    {
        static $pair = null;

        if ($pair === null) {
            // Windows PHP ships its openssl.cnf beside the binary.
            $config = dirname(PHP_BINARY).'/extras/ssl/openssl.cnf';
            $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]
                + (is_file($config) ? ['config' => $config] : []);

            $key = openssl_pkey_new($options);
            openssl_pkey_export($key, $private, null, $options);

            $pair = [$private, openssl_pkey_get_details($key)['key']];
        }

        return $pair;
    }

    private function invited(string $erpRole): User
    {
        [$member] = app(Team::class)->invite(
            $this->owner->tenant,
            ['name' => 'Sunita', 'email' => 'sunita@spice.test'],
            ['erp' => ['role_key' => $erpRole, 'all_outlets' => true]],
            $this->owner,
        );

        return $member;
    }

    public function test_a_role_the_business_made_in_erp_is_offered_beside_the_presets(): void
    {
        $this->actingAs($this->owner)
            ->get(route('team.create'))
            ->assertOk()
            ->assertSee('Head Chef — A role you made in ERP')
            // The presets are still there, in Accounts' own words.
            ->assertSee('Storekeeper — Goods receipts, issues to kitchen, counts, wastage')
            ->assertSee('Cashier — POS, table bills, payments, day close, customers');

        // The product was asked about this business...
        $asked = Http::recorded(fn (Request $request) => str_starts_with($request->url(), self::ERP.'/api/v1/roles'))->sole()[0];
        $this->assertSame($this->owner->tenant->ulid, $asked['tenant_id']);

        // ...with a token the product will accept: checked here the way the
        // product checks it, with Accounts' public key and the kit's verifier.
        config(['khana.sso.public_key' => $this->publicKey]);

        $token = app(MachineTokenVerifier::class)->verify(substr($asked->header('Authorization')[0], 7), ['roles:read']);

        $this->assertSame(['roles:read'], $token->scopes);
        $this->assertLessThanOrEqual(time() + 300, $token->expiresAt, 'good for minutes, not hours');
    }

    public function test_the_token_accounts_signs_for_itself_carries_only_what_was_asked_for(): void
    {
        config(['khana.sso.public_key' => $this->publicKey]);

        $jwt = app(OwnMachineToken::class)->for(['roles:read']);

        $this->expectException(InsufficientScope::class);

        app(MachineTokenVerifier::class)->verify($jwt, ['tenants:read']);
    }

    public function test_the_made_role_can_be_given_and_reaches_the_product_by_event(): void
    {
        $this->actingAs($this->owner)->post(route('team.store'), [
            'name' => 'Sunita', 'email' => 'sunita@spice.test',
            'access' => ['erp' => ['role_key' => 'head_chef', 'all_outlets' => 1]],
        ])->assertRedirect(route('team.index'));

        $member = User::query()->where('email', 'sunita@spice.test')->sole();
        $this->assertSame('head_chef', $member->access->firstWhere('product', 'erp')->role_key);

        // Recorded for ERP - and valid against the contract, or recording it would have thrown.
        $event = OutboxEvent::query()->where('type', 'accounts.access.changed')->get()
            ->first(fn (OutboxEvent $e) => $e->data['user_id'] === $member->ulid && $e->data['product'] === 'erp');
        $this->assertSame('head_chef', $event->data['role_key']);

        // The team list calls it by its name, not its key.
        $this->get(route('team.index'))->assertOk()->assertSee('Head Chef')->assertDontSee('head_chef');
    }

    public function test_a_role_no_product_has_is_refused(): void
    {
        $this->actingAs($this->owner)->post(route('team.store'), [
            'name' => 'Sunita', 'email' => 'sunita@spice.test',
            'access' => ['erp' => ['role_key' => 'sommelier', 'all_outlets' => 1]],
        ])->assertSessionHasErrors('access.erp.role_key');

        // A role ERP has is not one CRM has.
        $this->post(route('team.store'), [
            'name' => 'Sunita', 'email' => 'sunita@spice.test',
            'access' => ['crm' => ['role_key' => 'head_chef', 'all_outlets' => 1]],
        ])->assertSessionHasErrors('access.crm.role_key');

        $this->assertSame(0, User::query()->where('email', 'sunita@spice.test')->count());
    }

    public function test_a_product_that_is_down_costs_the_screen_nothing_but_its_own_roles(): void
    {
        $this->erpRoles = null;
        $this->crmRoles = null;

        $this->actingAs($this->owner)
            ->get(route('team.create'))
            ->assertOk()
            ->assertSee('Storekeeper — Goods receipts')
            ->assertDontSee('Head Chef');

        // A preset can still be given.
        $this->post(route('team.store'), [
            'name' => 'Ravi', 'email' => 'ravi@spice.test',
            'access' => ['erp' => ['role_key' => 'storekeeper', 'all_outlets' => 1]],
        ])->assertRedirect(route('team.index'));

        // And it is asked again next time, not written off: back up, the role is back.
        $this->erpRoles = [['key' => 'head_chef', 'name' => 'Head Chef', 'preset' => false]];
        $this->get(route('team.create'))->assertOk()->assertSee('Head Chef');
    }

    public function test_saving_somebody_who_holds_a_made_role_keeps_it_while_the_product_is_down(): void
    {
        $member = $this->invited('head_chef');
        app(RoleCatalog::class)->forget($this->owner->tenant);
        $this->erpRoles = null;

        // The form still shows what they hold, so it is not lost on saving.
        $this->actingAs($this->owner)
            ->get(route('team.edit', $member))
            ->assertOk()
            ->assertSee('value="head_chef" selected', false);

        $this->put(route('team.update', $member), [
            'access' => ['erp' => ['role_key' => 'head_chef', 'all_outlets' => 1]],
        ])->assertRedirect(route('team.index'));

        $this->assertSame('head_chef', $member->fresh()->access->firstWhere('product', 'erp')->role_key);
    }

    public function test_a_product_is_asked_once_a_minute_and_only_what_the_contract_allows_gets_through(): void
    {
        $this->erpRoles = [
            ['key' => 'head_chef', 'name' => 'Head Chef', 'preset' => false],
            // Not a contract `code`: Accounts could not store it.
            ['key' => 'Bad Key!', 'name' => 'Broken', 'preset' => false],
            // A product cannot rename a preset out from under the owner.
            ['key' => 'storekeeper', 'name' => 'Renamed', 'preset' => false],
        ];

        $catalog = app(RoleCatalog::class);
        $tenant = $this->owner->tenant;

        $erp = $catalog->for($tenant)['erp'];
        $catalog->for($tenant);
        $catalog->for($tenant);

        $this->assertSame('Head Chef', $erp['head_chef']['name']);
        $this->assertArrayNotHasKey('Bad Key!', $erp);
        $this->assertSame('Storekeeper', $erp['storekeeper']['name']);

        Http::assertSentCount(2);   // ERP and CRM, once each

        $this->assertTrue($catalog->offers($tenant, 'erp', 'head_chef'));
        $this->assertFalse($catalog->offers($tenant, 'crm', 'head_chef'));
        $this->assertFalse($catalog->offers($tenant, 'erp', 'sommelier'));
    }

    public function test_with_no_products_set_up_the_presets_are_offered_and_nobody_is_asked(): void
    {
        config(['khana.urls.crm' => null, 'khana.urls.erp' => null]);

        $this->actingAs($this->owner)->get(route('team.create'))->assertOk()->assertSee('Storekeeper');

        Http::assertNothingSent();
    }
}
