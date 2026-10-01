<?php

namespace Tests\Feature;

use App\Models\Outlet;
use App\Models\Passport\Client;
use App\Models\Plan;
use App\Models\User;
use App\Services\SalesKeys;
use App\Services\Signup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Khana\Kit\Sso\MachineTokenVerifier;
use Tests\TestCase;

/**
 * Keys for other billing software: made by the owner for one outlet, good
 * for sending that outlet's sales to ERP and for nothing else. What ERP will
 * see is checked the way ERP checks it - with Accounts' public key and the
 * kit's verifier.
 */
final class SalesKeysTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();

        [$private, $public] = self::keyPair();

        config([
            'khana.urls.crm' => 'https://crm.example.test',
            'khana.urls.erp' => 'https://erp.example.test',
            'passport.private_key' => $private,
            'passport.public_key' => $public,
            'khana.sso.public_key' => $public,
        ]);

        $this->owner = $this->business('Spice Route', 'asha@spice.test', ['crm', 'erp']);
        $this->outlet = $this->owner->tenant->outlets->first();
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

    /** @param  list<string>  $products */
    private function business(string $name, string $email, array $products): User
    {
        $plan = Plan::firstOrCreate(['code' => implode('_', $products)], [
            'name' => ucfirst(implode(' + ', $products)), 'products' => $products,
            'monthly_price_paise' => 99900, 'yearly_price_paise' => 999000,
            'trial_days' => 14, 'is_active' => true, 'sort_order' => 0,
        ]);

        return app(Signup::class)->register(
            business: ['name' => $name],
            outlet: ['name' => 'Ground Floor', 'code' => 'GF', 'city' => 'Delhi', 'state_code' => '07'],
            owner: ['name' => 'Owner of '.$name, 'email' => $email, 'password' => 'secret12'],
            plan: $plan,
        );
    }

    /** @return array{id: string, secret: string} */
    private function makeKey(string $software = 'Petpooja'): array
    {
        $this->actingAs($this->owner)
            ->post(route('outlets.keys.store', $this->outlet), ['software' => $software])
            ->assertRedirect(route('outlets.edit', $this->outlet));

        return ['id' => session('new_sales_key')['id'], 'secret' => session('new_sales_key')['secret']];
    }

    private function token(array $key, string $scopes): string
    {
        return $this->post('/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $key['id'],
            'client_secret' => $key['secret'],
            'scope' => $scopes,
        ])->assertOk()->json('access_token');
    }

    public function test_the_owner_makes_a_key_and_is_shown_its_secret_once(): void
    {
        $this->actingAs($this->owner)
            ->get(route('outlets.edit', $this->outlet))
            ->assertOk()
            ->assertSee('Other billing software')
            ->assertDontSee('copy it now');

        $this->post(route('outlets.keys.store', $this->outlet), ['software' => 'Petpooja'])
            ->assertRedirect(route('outlets.edit', $this->outlet));

        $secret = session('new_sales_key')['secret'];

        $this->get(route('outlets.edit', $this->outlet))
            ->assertOk()
            ->assertSee('Key for Petpooja: copy it now')
            ->assertSee($secret)
            ->assertSee($this->outlet->ulid)
            ->assertSee('https://erp.example.test/api/v1/sales');

        // The next time the page is opened the key is listed and its secret is gone.
        $this->get(route('outlets.edit', $this->outlet))
            ->assertOk()
            ->assertSee('Petpooja')
            ->assertDontSee($secret);
    }

    public function test_a_keys_token_is_for_sales_at_its_own_outlet_and_nothing_else(): void
    {
        $key = $this->makeKey();

        // Asked for more than it may have; what comes back is what ERP will trust.
        $token = app(MachineTokenVerifier::class)->verify(
            $this->token($key, 'sales:write tenants:read stock:read'),
            ['sales:write'],
        );

        $this->assertSame(['sales:write'], $token->scopes);
        $this->assertSame($this->outlet->ulid, $token->outletId);
    }

    public function test_a_products_own_token_names_no_outlet(): void
    {
        Artisan::call('khana:clients');

        $erp = Client::query()->where('name', 'Khana ERP machine')->sole();
        $erp->forceFill(['secret' => 'known-for-the-test'])->save();

        $token = app(MachineTokenVerifier::class)->verify(
            $this->token(['id' => (string) $erp->getKey(), 'secret' => 'known-for-the-test'], 'menu:read'),
            ['menu:read'],
        );

        $this->assertNull($token->outletId);
    }

    public function test_a_revoked_key_gets_no_more_tokens(): void
    {
        $key = $this->makeKey();

        $this->delete(route('outlets.keys.destroy', [$this->outlet, $key['id']]))
            ->assertRedirect(route('outlets.edit', $this->outlet));

        $this->post('/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $key['id'],
            'client_secret' => $key['secret'],
            'scope' => 'sales:write',
        ])->assertStatus(401);

        $this->get(route('outlets.edit', $this->outlet))->assertOk()->assertDontSee($key['id']);
    }

    public function test_another_business_cannot_make_or_revoke_this_outlets_keys(): void
    {
        $key = $this->makeKey();
        $stranger = $this->business('Other Place', 'owner@other.test', ['crm', 'erp']);

        $this->actingAs($stranger)
            ->post(route('outlets.keys.store', $this->outlet), ['software' => 'Sneaky'])
            ->assertNotFound();

        $this->delete(route('outlets.keys.destroy', [$this->outlet, $key['id']]))->assertNotFound();

        // Nor by naming their own outlet with somebody else's key.
        $theirs = $stranger->tenant->outlets->first();
        $this->delete(route('outlets.keys.destroy', [$theirs, $key['id']]))->assertNotFound();

        $this->assertCount(1, app(SalesKeys::class)->of($this->outlet));
    }

    public function test_an_outlet_without_erp_has_nowhere_to_send_sales_so_has_no_keys(): void
    {
        $crmOnly = $this->business('Just Billing', 'owner@billing.test', ['crm']);
        $outlet = $crmOnly->tenant->outlets->first();

        $this->actingAs($crmOnly)
            ->get(route('outlets.edit', $outlet))
            ->assertOk()
            ->assertDontSee('Other billing software');

        $this->post(route('outlets.keys.store', $outlet), ['software' => 'Petpooja'])->assertNotFound();
    }

    public function test_a_key_is_never_mistaken_for_a_products_own_client_whatever_it_is_called(): void
    {
        // An owner may type anything, including the name the command looks for.
        $key = $this->makeKey('Khana ERP machine');

        // Run as an install would to pick up new scopes: the key must not be
        // taken for ERP's client and handed ERP's scopes.
        Artisan::call('khana:clients');

        $this->assertSame(['sales:write'], Client::query()->findOrFail($key['id'])->scopes);
        $this->assertSame(
            ['sales:write'],
            app(MachineTokenVerifier::class)->verify($this->token($key, 'sales:write tenants:read menu:read'))->scopes,
        );

        // Nor is it revoked when the products' clients are rotated.
        Artisan::call('khana:clients', ['--fresh' => true]);

        $this->assertFalse((bool) Client::query()->findOrFail($key['id'])->revoked);
    }
}
