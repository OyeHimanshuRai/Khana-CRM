<?php

namespace Tests\Feature;

use App\Console\Commands\SeedClients;
use App\Models\Passport\Client;
use App\Models\Plan;
use App\Models\User;
use App\Services\Signup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Khana\Kit\Sso\InsufficientScope;
use Khana\Kit\Sso\MachineTokenVerifier;
use Khana\Kit\Sso\SignInClient;
use Laravel\Passport\ClientRepository;
use Tests\TestCase;

/**
 * The tokens CRM and ERP read each other with.
 *
 * Each product's machine client may ask for its own reads of the other two
 * services and nothing else, and what Accounts then issues is checked here
 * the way a product checks it: with Accounts' public key and the kit's
 * verifier. A person signing in through a browser gets a token with no
 * scopes at all, whatever the request asked for - or anybody with a login
 * could read another business.
 */
final class MachineClientsTest extends TestCase
{
    use RefreshDatabase;

    private const CRM = 'https://crm.example.test';

    private const ERP = 'https://erp.example.test';

    protected function setUp(): void
    {
        parent::setUp();

        [$private, $public] = self::keyPair();

        config([
            'khana.urls.crm' => self::CRM,
            'khana.urls.erp' => self::ERP,
            // What Passport signs with, and what a product checks against.
            'passport.private_key' => $private,
            'passport.public_key' => $public,
            'khana.sso.public_key' => $public,
        ]);
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

    /**
     * Runs the command an operator runs, and reads what it printed for one
     * product - the only place a secret is ever shown.
     *
     * @return array{sign_in: string, id: string, secret: string}
     */
    private function clientsFor(string $product): array
    {
        Artisan::call('khana:clients');

        $section = Str::after(Artisan::output(), '── '.strtoupper($product).' ');

        $line = fn (string $key) => trim(Str::before(Str::after($section, $key.'='), "\n"));

        return [
            'sign_in' => $line('KHANA_SSO_CLIENT_ID'),
            'id' => $line('KHANA_MACHINE_CLIENT_ID'),
            'secret' => $line('KHANA_MACHINE_CLIENT_SECRET'),
        ];
    }

    public function test_the_partner_app_gets_one_public_client_with_its_own_redirect(): void
    {
        Artisan::call('khana:clients');
        Artisan::call('khana:clients');

        $clients = Client::query()->where('name', 'Khana Partner app sign-in')->where('revoked', false)->get();

        $this->assertCount(1, $clients, 'Running the command again keeps the same client.');
        $this->assertFalse($clients->first()->confidential());
        $this->assertSame([SeedClients::PARTNER_APP_REDIRECT], $clients->first()->redirect_uris);
        $this->assertStringContainsString((string) $clients->first()->getKey(), Artisan::output());
    }

    /** What Accounts hands a product that presents its client and asks for these scopes. */
    private function machineToken(array $client, string $scopes): string
    {
        return $this->post('/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $client['id'],
            'client_secret' => $client['secret'],
            'scope' => $scopes,
        ])->assertOk()->json('access_token');
    }

    private function owner(): User
    {
        $plan = Plan::create([
            'code' => 'suite', 'name' => 'Suite', 'products' => ['crm', 'erp'],
            'monthly_price_paise' => 149900, 'yearly_price_paise' => 1499000,
            'trial_days' => 14, 'is_active' => true, 'sort_order' => 0,
        ]);

        return app(Signup::class)->register(
            business: ['name' => 'Spice Route'],
            outlet: ['name' => 'Ground Floor', 'code' => 'GF', 'city' => 'Delhi', 'state_code' => '07'],
            owner: ['name' => 'Asha', 'email' => 'asha@spice.test', 'password' => 'secret12'],
            plan: $plan,
        );
    }

    /** Signs a person in through a browser client, asking for scopes, and returns the token they end up holding. */
    private function personToken(User $person, string $clientId, string $redirect, string $scopes): string
    {
        $verifier = Str::random(96);

        $back = $this->actingAs($person)->get('/oauth/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirect,
            'scope' => $scopes,
            'state' => Str::random(40),
            'code_challenge' => SignInClient::challenge($verifier),
            'code_challenge_method' => 'S256',
        ]))->assertRedirect()->headers->get('Location');

        parse_str((string) parse_url((string) $back, PHP_URL_QUERY), $query);

        $this->assertArrayHasKey('code', $query, 'Accounts sent the person back without a code: '.$back);

        return $this->post('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'redirect_uri' => $redirect,
            'code_verifier' => $verifier,
            'code' => $query['code'],
        ])->assertOk()->json('access_token');
    }

    public function test_each_product_may_ask_for_its_own_reads_and_no_others(): void
    {
        $erp = $this->clientsFor('erp');

        // ERP reads the snapshot from Accounts, and the menu and sales from CRM.
        $token = app(MachineTokenVerifier::class)->verify(
            $this->machineToken($erp, 'tenants:read menu:read sales:read'),
            ['tenants:read', 'menu:read', 'sales:read'],
        );

        // Issued to ERP's client, and to nobody: there is no person in it.
        $this->assertSame($erp['id'], $token->clientId);
        $this->assertSame($erp['id'], $token->subject);

        // It is CRM that reads ERP's bills and stock. Asked for by ERP's own
        // client, those scopes are simply not on the token.
        $wide = $this->machineToken($erp, 'tenants:read bills:read stock:read sales:write roles:read');

        $this->assertSame(['tenants:read'], app(MachineTokenVerifier::class)->verify($wide)->scopes);
    }

    public function test_crm_may_read_erps_bills_and_stock(): void
    {
        $crm = $this->clientsFor('crm');

        $token = app(MachineTokenVerifier::class)->verify(
            $this->machineToken($crm, 'bills:read stock:read'),
            ['bills:read', 'stock:read'],
        );

        $this->assertEqualsCanonicalizing(['bills:read', 'stock:read'], $token->scopes);

        $this->expectException(InsufficientScope::class);

        app(MachineTokenVerifier::class)->verify($this->machineToken($crm, 'menu:read sales:read'), ['menu:read']);
    }

    public function test_running_the_command_again_keeps_the_clients_and_brings_their_scopes_up_to_date(): void
    {
        $first = $this->clientsFor('erp');

        // An install made before the products read each other.
        Client::query()->whereKey($first['id'])->update(['scopes' => json_encode(['tenants:read', 'roles:read'])]);

        $again = $this->clientsFor('erp');

        $this->assertSame($first['id'], $again['id'], 'the same client, so the product\'s .env still works');
        $this->assertSame($first['sign_in'], $again['sign_in']);
        $this->assertStringContainsString('unchanged', $again['secret']);
        $this->assertSame(5, Client::query()->count(), 'two per product and one for the Partner app, however often it is run');

        // The secret the product already holds still works, and now reaches CRM's menu.
        app(MachineTokenVerifier::class)->verify($this->machineToken($first, 'menu:read'), ['menu:read']);

        $this->assertTrue(true);
    }

    public function test_fresh_revokes_the_old_clients_and_mints_new_ones(): void
    {
        $first = $this->clientsFor('crm');

        Artisan::call('khana:clients', ['--fresh' => true]);

        $this->assertTrue((bool) Client::query()->findOrFail($first['id'])->revoked);
        $this->assertSame(5, Client::query()->where('revoked', false)->count());

        $this->post('/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $first['id'],
            'client_secret' => $first['secret'],
            'scope' => 'tenants:read',
        ])->assertStatus(401);
    }

    public function test_a_person_signing_in_cannot_get_a_machines_scopes(): void
    {
        $owner = $this->owner();
        $crm = $this->clientsFor('crm');

        // Signed in properly, but asking for what only a product may hold.
        $token = $this->personToken($owner, $crm['sign_in'], self::CRM.'/sso/callback', 'tenants:read sales:read bills:read');

        $this->assertSame([], app(MachineTokenVerifier::class)->verify($token)->scopes);

        // So it opens nothing a machine token opens - here, the whole of a business.
        $this->getJson('/api/v1/tenants/'.$owner->tenant->ulid.'/snapshot', ['Authorization' => 'Bearer '.$token])
            ->assertForbidden();

        // It is still a good token for what a person's token is for.
        $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('user_id', $owner->ulid);
    }

    public function test_the_consent_screen_is_there_for_a_request_that_asks_for_it(): void
    {
        $owner = $this->owner();
        $crm = $this->clientsFor('crm');
        $verifier = Str::random(96);

        // Our own products never ask; this is what answers one that does.
        $this->actingAs($owner)->get('/oauth/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $crm['sign_in'],
            'redirect_uri' => self::CRM.'/sso/callback',
            'state' => $state = Str::random(40),
            'code_challenge' => SignInClient::challenge($verifier),
            'code_challenge_method' => 'S256',
            'prompt' => 'consent',
        ]))->assertOk()->assertSee('Continue to Khana CRM?')->assertSee('Asha');

        $back = $this->post(route('passport.authorizations.approve'), [
            'state' => $state,
            'client_id' => $crm['sign_in'],
            'auth_token' => session('authToken'),
        ])->assertRedirect()->headers->get('Location');

        $this->assertStringStartsWith(self::CRM.'/sso/callback?code=', (string) $back);
    }

    public function test_a_client_given_no_list_of_scopes_may_ask_for_none(): void
    {
        $owner = $this->owner();

        // Made some other way than `khana:clients` - Passport's own command,
        // say - and so with no list. Passport reads that as "anything".
        $redirect = 'https://somewhere.example.test/callback';
        $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
            name: 'Made by hand',
            redirectUris: [$redirect],
            confidential: false,
        );

        $this->assertNull($client->getAttributes()['scopes'] ?? null);

        $token = $this->personToken($owner, (string) $client->getKey(), $redirect, 'tenants:read');

        $this->assertSame([], app(MachineTokenVerifier::class)->verify($token)->scopes);
    }
}
