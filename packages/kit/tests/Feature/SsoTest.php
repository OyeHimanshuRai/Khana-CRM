<?php

namespace Khana\Kit\Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Khana\Kit\Sso\InsufficientScope;
use Khana\Kit\Sso\InvalidMachineToken;
use Khana\Kit\Sso\LocalSignIn;
use Khana\Kit\Sso\MachineToken;
use Khana\Kit\Sso\MachineTokens;
use Khana\Kit\Sso\MachineTokenVerifier;
use Khana\Kit\Sso\SignedInPerson;
use Khana\Kit\Sso\SignInClient;
use Khana\Kit\Sso\SignInFailed;
use Khana\Kit\Tests\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signing in through Accounts, and the tokens services call each other with.
 */
class SsoTest extends TestCase
{
    private const ACCOUNTS = 'https://accounts.khana.test';

    public static ?SignedInPerson $signedIn = null;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('khana.sso', [
            'accounts_url' => self::ACCOUNTS,
            'client_id' => 'crm-browser',
            'redirect' => null,
            'scopes' => [],
            'machine_client_id' => 'crm-machine',
            'machine_client_secret' => 'machine-secret',
            'public_key' => self::key('accounts-public'),
            'leeway' => 30,
        ]);

        // The product's half: what to do once Accounts has said who it is.
        $app->bind(LocalSignIn::class, fn () => new class implements LocalSignIn
        {
            public function signIn(SignedInPerson $person, Request $request): Response
            {
                SsoTest::$signedIn = $person;

                return response('signed in as '.$person->name);
            }

            public function failed(SignInFailed $reason, Request $request): Response
            {
                return response('failed: '.$reason->getMessage(), 401);
            }
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        self::$signedIn = null;
    }

    /** @var array<string, string> Throwaway key pairs, made once per run. */
    private static array $keys = [];

    /**
     * "accounts-private", "accounts-public" or "stranger-private".
     *
     * Generated rather than committed: a private key in the repository is
     * a private key in the repository, whatever the file says it is for.
     */
    private static function key(string $name): string
    {
        if (self::$keys === []) {
            // Windows PHP ships its openssl.cnf beside the binary and does
            // not find it on its own; elsewhere the system default is used.
            $config = dirname(PHP_BINARY).'/extras/ssl/openssl.cnf';
            $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]
                + (is_file($config) ? ['config' => $config] : []);

            foreach (['accounts', 'stranger'] as $who) {
                $pair = openssl_pkey_new($options);
                openssl_pkey_export($pair, $private, null, $options);

                self::$keys[$who.'-private'] = $private;
                self::$keys[$who.'-public'] = openssl_pkey_get_details($pair)['key'];
            }
        }

        return self::$keys[$name];
    }

    /**
     * @return array{state: string, verifier: string}
     */
    private function startSignIn(): array
    {
        $this->get('/sso/login')->assertRedirect();

        return session(SignInClient::SESSION_KEY);
    }

    private function profile(): array
    {
        return [
            'user_id' => '01M3D669PBZ0DTQJG367XRS5EM',
            'name' => 'Asha Verma',
            'email' => 'asha@spiceroute.example',
            'mobile' => null,
            'tenant_id' => '01M3D669MZPZP9EP7ND2XFY1W2',
            'platform_admin' => false,
            'outlets' => [['outlet_id' => '01M3D669NC4JCJSRMWG4WWDRRT', 'name' => 'Hauz Khas', 'code' => 'HK']],
            'products' => ['crm' => ['role_key' => 'cashier', 'outlet_ids' => ['01M3D669NC4JCJSRMWG4WWDRRT']]],
        ];
    }

    /* ----------------------------------------------------------- sign-in */

    public function test_sign_in_goes_to_accounts_with_a_pkce_challenge(): void
    {
        $location = $this->get('/sso/login')->assertRedirect()->headers->get('Location');
        $pending = session(SignInClient::SESSION_KEY);

        $this->assertStringStartsWith(self::ACCOUNTS.'/oauth/authorize?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $this->assertSame('code', $query['response_type']);
        $this->assertSame('crm-browser', $query['client_id']);
        $this->assertSame(route('khana.sso.callback'), $query['redirect_uri']);
        $this->assertSame($pending['state'], $query['state']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame(SignInClient::challenge($pending['verifier']), $query['code_challenge']);
        $this->assertArrayNotHasKey('code_verifier', $query);
    }

    public function test_the_callback_swaps_the_code_and_asks_accounts_who_it_is(): void
    {
        $pending = $this->startSignIn();

        Http::fake([
            self::ACCOUNTS.'/oauth/token' => Http::response(['token_type' => 'Bearer', 'access_token' => 'person-token', 'expires_in' => 3600]),
            self::ACCOUNTS.'/api/v1/me' => Http::response($this->profile()),
        ]);

        $this->get('/sso/callback?code=the-code&state='.$pending['state'])
            ->assertOk()
            ->assertSee('signed in as Asha Verma');

        Http::assertSent(fn (ClientRequest $request) => $request->url() === self::ACCOUNTS.'/oauth/token'
            && $request['grant_type'] === 'authorization_code'
            && $request['code'] === 'the-code'
            && $request['code_verifier'] === $pending['verifier']
            && ! isset($request['client_secret']));

        Http::assertSent(fn (ClientRequest $request) => $request->url() === self::ACCOUNTS.'/api/v1/me'
            && $request->hasHeader('Authorization', 'Bearer person-token'));

        $person = self::$signedIn;
        $this->assertSame('01M3D669PBZ0DTQJG367XRS5EM', $person->userId);
        $this->assertTrue($person->canUse('crm'));
        $this->assertFalse($person->canUse('erp'));
        $this->assertSame('cashier', $person->roleIn('crm'));
        $this->assertSame(['01M3D669NC4JCJSRMWG4WWDRRT'], $person->outletIdsIn('crm'));
    }

    public function test_a_state_is_good_for_one_attempt_only(): void
    {
        $pending = $this->startSignIn();

        Http::fake([
            self::ACCOUNTS.'/oauth/token' => Http::response(['access_token' => 'person-token']),
            self::ACCOUNTS.'/api/v1/me' => Http::response($this->profile()),
        ]);

        $this->get('/sso/callback?code=c&state='.$pending['state'])->assertOk();
        $this->get('/sso/callback?code=c&state='.$pending['state'])->assertStatus(401);
    }

    public function test_a_callback_this_browser_did_not_start_is_refused_without_calling_accounts(): void
    {
        $this->startSignIn();
        Http::fake();

        $this->get('/sso/callback?code=stolen&state=forged')
            ->assertStatus(401)
            ->assertSee('expired or was not started here');

        Http::assertNothingSent();
        $this->assertNull(self::$signedIn);
    }

    public function test_accounts_saying_no_is_reported(): void
    {
        $pending = $this->startSignIn();

        $this->get('/sso/callback?error=access_denied&error_description=Account+deactivated&state='.$pending['state'])
            ->assertStatus(401)
            ->assertSee('Account deactivated');
    }

    public function test_a_refused_code_does_not_sign_anybody_in(): void
    {
        $pending = $this->startSignIn();
        Http::fake([self::ACCOUNTS.'/oauth/token' => Http::response(['error' => 'invalid_grant'], 400)]);

        $this->get('/sso/callback?code=old&state='.$pending['state'])->assertStatus(401)->assertSee('refused');
        $this->assertNull(self::$signedIn);
    }

    public function test_accounts_being_down_is_said_plainly(): void
    {
        $pending = $this->startSignIn();
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        $this->get('/sso/callback?code=c&state='.$pending['state'])->assertStatus(401)->assertSee('could not be reached');
    }

    /* --------------------------------------------------- machine tokens out */

    public function test_a_machine_token_is_fetched_once_and_reused(): void
    {
        Http::fake([self::ACCOUNTS.'/oauth/token' => Http::response(['access_token' => 'machine-token', 'expires_in' => 3600])]);

        $tokens = $this->app->make(MachineTokens::class);

        $this->assertSame('machine-token', $tokens->token(['stock:read']));
        $this->assertSame('machine-token', $tokens->token(['stock:read']));

        Http::assertSentCount(1);
        Http::assertSent(fn (ClientRequest $request) => $request['grant_type'] === 'client_credentials'
            && $request['client_id'] === 'crm-machine'
            && $request['client_secret'] === 'machine-secret'
            && $request['scope'] === 'stock:read');
    }

    public function test_a_refused_machine_token_is_an_error_not_an_empty_string(): void
    {
        Http::fake([self::ACCOUNTS.'/oauth/token' => Http::response(['error' => 'invalid_client'], 401)]);

        $this->expectException(RuntimeException::class);

        $this->app->make(MachineTokens::class)->token(['stock:read']);
    }

    /* ---------------------------------------------------- machine tokens in */

    /**
     * @param  array<string, mixed>  $claims
     */
    private function jwt(array $claims, string $signer = 'accounts-private', string $alg = 'RS256'): string
    {
        $encode = fn (string $raw) => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
        $input = $encode(json_encode(['typ' => 'JWT', 'alg' => $alg])).'.'.$encode(json_encode($claims));

        $signature = match ($alg) {
            'RS256' => (function () use ($input, $signer) {
                openssl_sign($input, $signature, self::key($signer), OPENSSL_ALGO_SHA256);

                return $signature;
            })(),
            // The classic forgery: HMAC keyed with the public key.
            'HS256' => hash_hmac('sha256', $input, self::key('accounts-public'), true),
            default => '',
        };

        return $input.'.'.$encode($signature);
    }

    /**
     * Claims shaped the way Passport issues them.
     *
     * @return array<string, mixed>
     */
    private function claims(array $overrides = []): array
    {
        return array_replace([
            'aud' => 'erp-machine',
            'jti' => 'token-1',
            'iat' => time() - 10 + 0.123,
            'nbf' => time() - 10 + 0.123,
            'exp' => time() + 3600 + 0.123,
            'sub' => '',
            'scopes' => ['menu:read', 'sales:read'],
        ], $overrides);
    }

    private function verifier(): MachineTokenVerifier
    {
        return $this->app->make(MachineTokenVerifier::class);
    }

    public function test_a_token_signed_by_accounts_is_accepted_locally(): void
    {
        $token = $this->verifier()->verify($this->jwt($this->claims(['outlet_id' => '01M3D669NC4JCJSRMWG4WWDRRT'])), ['menu:read']);

        $this->assertSame('erp-machine', $token->clientId);
        $this->assertSame(['menu:read', 'sales:read'], $token->scopes);
        $this->assertNull($token->subject);
        $this->assertSame('01M3D669NC4JCJSRMWG4WWDRRT', $token->outletId);
    }

    public function test_a_space_separated_scope_claim_is_understood(): void
    {
        $claims = $this->claims();
        unset($claims['scopes']);
        $claims['scope'] = 'bills:read stock:read';

        $this->assertTrue($this->verifier()->verify($this->jwt($claims), ['stock:read'])->can('bills:read'));
    }

    public function test_an_expired_token_is_refused(): void
    {
        $this->expectException(InvalidMachineToken::class);
        $this->expectExceptionMessage('expired');

        $this->verifier()->verify($this->jwt($this->claims(['exp' => time() - 120])));
    }

    public function test_a_token_not_valid_yet_is_refused(): void
    {
        $this->expectException(InvalidMachineToken::class);

        $this->verifier()->verify($this->jwt($this->claims(['nbf' => time() + 600])));
    }

    public function test_a_token_signed_by_anyone_else_is_refused(): void
    {
        $this->expectException(InvalidMachineToken::class);
        $this->expectExceptionMessage('not signed by Accounts');

        $this->verifier()->verify($this->jwt($this->claims(), 'stranger-private'));
    }

    public function test_an_unsigned_token_is_refused(): void
    {
        $this->expectException(InvalidMachineToken::class);
        $this->expectExceptionMessage('Only RS256');

        $this->verifier()->verify($this->jwt($this->claims(), alg: 'none'));
    }

    public function test_hmac_keyed_with_the_public_key_is_refused(): void
    {
        $this->expectException(InvalidMachineToken::class);

        $this->verifier()->verify($this->jwt($this->claims(), alg: 'HS256'));
    }

    public function test_a_token_without_the_scope_is_refused(): void
    {
        $this->expectException(InsufficientScope::class);

        $this->verifier()->verify($this->jwt($this->claims()), ['sales:write']);
    }

    public function test_a_tampered_claim_breaks_the_signature(): void
    {
        [$header, , $signature] = explode('.', $this->jwt($this->claims()));
        $forged = rtrim(strtr(base64_encode(json_encode($this->claims(['scopes' => ['*']]))), '+/', '-_'), '=');

        $this->expectException(InvalidMachineToken::class);

        $this->verifier()->verify($header.'.'.$forged.'.'.$signature);
    }

    public function test_a_guarded_route_answers_401_403_or_lets_the_token_in(): void
    {
        Route::middleware('machine:menu:read')->get('/api/v1/outlets/x/menu', function (Request $request) {
            /** @var MachineToken $token */
            $token = $request->attributes->get('khana.machine');

            return response()->json(['client' => $token->clientId]);
        });

        $this->getJson('/api/v1/outlets/x/menu')->assertStatus(401);
        $this->getJson('/api/v1/outlets/x/menu', ['Authorization' => 'Bearer not-a-jwt'])->assertStatus(401);
        $this->getJson('/api/v1/outlets/x/menu', ['Authorization' => 'Bearer '.$this->jwt($this->claims(['scopes' => ['stock:read']]))])->assertStatus(403);
        $this->getJson('/api/v1/outlets/x/menu', ['Authorization' => 'Bearer '.$this->jwt($this->claims())])
            ->assertOk()
            ->assertJson(['client' => 'erp-machine']);
    }
}
