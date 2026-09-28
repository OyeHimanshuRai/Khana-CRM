<?php

namespace Tests\Feature;

use App\Events\Outbound\OutletClock;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\User;
use App\Services\InvoiceService;
use App\Support\CurrentShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Khana\Kit\Events\OutboxEvent;
use Khana\Kit\Support\Ulid;
use Spatie\Permission\Models\Role;
use Tests\Concerns\TalksToErp;
use Tests\TestCase;

/**
 * CRM's read API for the other services (contracts/api/crm.yaml), called the
 * way ERP and Accounts call it: with a machine token Accounts signed,
 * checked here with Accounts' public key.
 *
 * Each endpoint is tried with a good token, with a token missing its scope,
 * and - for the outlet ones - on an outlet the caller does not serve. Where
 * the contract says a response is "in the same shape as" an event, it is
 * checked against that event's schema.
 */
class MachineApiTest extends TestCase
{
    use RefreshDatabase, TalksToErp;

    /** @var array<string, string> Throwaway key pairs, made once per run. */
    private static array $keys = [];

    private Shop $shop;

    /** @var array{tenant: string, outlet: string} */
    private array $ids;

    protected function setUp(): void
    {
        parent::setUp();

        CurrentShop::forget();
        config(['khana.sso.public_key' => self::key('accounts-public')]);

        $this->shop = Shop::query()->orderBy('id')->firstOrFail();
        $this->ids = $this->linkToAccounts($this->shop, ['crm' => 'active', 'erp' => 'active']);
    }

    /* ------------------------------------------------------------ tokens */

    /**
     * "accounts-private", "accounts-public" or "stranger-private".
     *
     * Generated rather than committed, as the kit's own SSO tests do: a
     * private key in the repository is a private key in the repository.
     */
    private static function key(string $name): string
    {
        if (self::$keys === []) {
            // Windows PHP ships its openssl.cnf beside the binary.
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
     * A client-credentials token shaped the way Passport issues one.
     *
     * @param  list<string>  $scopes
     */
    private function token(array $scopes, array $claims = [], string $signer = 'accounts-private'): string
    {
        $encode = fn (string $raw) => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
        $input = $encode(json_encode(['typ' => 'JWT', 'alg' => 'RS256'])).'.'.$encode(json_encode(array_replace([
            'aud' => 'erp-machine',
            'jti' => 'token-'.Ulid::generate(),
            'iat' => time() - 10,
            'nbf' => time() - 10,
            'exp' => time() + 3600,
            'sub' => '',
            'scopes' => $scopes,
        ], $claims)));

        openssl_sign($input, $signature, self::key($signer), OPENSSL_ALGO_SHA256);

        return $input.'.'.$encode($signature);
    }

    private function getAs(string $uri, array $scopes, array $claims = []): TestResponse
    {
        return $this->getJson($uri, ['Authorization' => 'Bearer '.$this->token($scopes, $claims)]);
    }

    /* ---------------------------------------------------------- fixtures */

    private function dish(string $name, float $price = 150): Product
    {
        return Product::create([
            'name' => $name,
            'slug' => Product::uniqueSlug($name),
            'sku' => Product::generateSku($name),
            'selling_price' => $price,
            'is_active' => true,
        ]);
    }

    private function sell(float $price, ?\DateTimeInterface $at = null, ?Shop $shop = null): Invoice
    {
        return app(InvoiceService::class)->create([
            'shop' => $shop ?? $this->shop,
            'channel' => Invoice::POS,
            'invoiced_at' => $at,
            'items' => [['product_id' => $this->dish('Thali '.Ulid::generate(), $price)->id, 'quantity' => 1]],
            'payments' => [['amount' => $price, 'method' => 'cash']],
        ]);
    }

    private function crmOnlyOutlet(): Shop
    {
        $shop = Shop::create([
            'tenant_id' => $this->shop->tenant_id,
            'name' => 'Kiosk', 'code' => 'KSK', 'slug' => 'kiosk',
            'invoice_prefix' => 'INV', 'pos_prefix' => 'POS',
            'currency' => 'INR', 'timezone' => 'Asia/Kolkata', 'is_active' => true,
        ]);

        $this->linkToAccounts($shop, ['crm' => 'active']);

        return $shop;
    }

    /* -------------------------------------------------------------- menu */

    public function test_the_menu_is_every_item_in_the_shape_of_the_menu_event(): void
    {
        $curry = $this->dish('Kadai Paneer', 260);
        ProductVariant::create(['product_id' => $curry->id, 'name' => 'Half', 'price' => 160]);
        $gone = $this->dish('Old Special', 99);
        $gone->delete();

        $response = $this->getAs("/api/v1/outlets/{$this->ids['outlet']}/menu", ['menu:read'])->assertOk();

        $this->assertSame($this->ids['outlet'], $response->json('outlet_id'));
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $response->json('taken_at'));

        $items = collect($response->json('items'))->keyBy('item_id');
        $this->assertTrue($items->has($curry->fresh()->ulid));
        $this->assertTrue($items->has($gone->fresh()->ulid), 'archived items are included');
        $this->assertTrue($items[$gone->fresh()->ulid]['archived']);
        $this->assertFalse($items[$curry->fresh()->ulid]['archived']);
        $this->assertSame('Half', $items[$curry->fresh()->ulid]['sizes'][0]['name']);

        foreach ($items as $item) {
            unset($item['archived']);
            $this->assertDataHonours('crm.menu.item_upserted', $item, $this->ids['tenant'], $this->ids['outlet']);
        }
    }

    public function test_the_menu_needs_a_token_with_its_scope(): void
    {
        $uri = "/api/v1/outlets/{$this->ids['outlet']}/menu";

        $this->getJson($uri)->assertStatus(401);
        $this->getJson($uri, ['Authorization' => 'Bearer '.$this->token(['menu:read'], [], 'stranger-private')])->assertStatus(401);
        $this->getAs($uri, ['sales:read'])->assertStatus(403);
    }

    public function test_an_outlet_the_caller_does_not_serve_is_refused(): void
    {
        $kiosk = $this->crmOnlyOutlet();

        $this->getAs("/api/v1/outlets/{$kiosk->ulid}/menu", ['menu:read'])
            ->assertStatus(403)
            ->assertJsonPath('message', 'This outlet does not use Khana ERP, so CRM serves nothing about it here.');

        $this->getAs('/api/v1/outlets/'.Ulid::generate().'/menu', ['menu:read'])->assertStatus(404);
    }

    public function test_a_token_bound_to_one_outlet_reads_that_outlet_only(): void
    {
        $this->getAs("/api/v1/outlets/{$this->ids['outlet']}/menu", ['menu:read'], ['outlet_id' => Ulid::generate()])
            ->assertStatus(403);

        $this->getAs("/api/v1/outlets/{$this->ids['outlet']}/menu", ['menu:read'], ['outlet_id' => $this->ids['outlet']])
            ->assertOk();
    }

    /* ------------------------------------------------------------- sales */

    public function test_the_digest_counts_the_days_standing_sales_and_lists_the_cancelled(): void
    {
        $kept = $this->sell(250);
        $alsoKept = $this->sell(120);
        $cancelled = $this->sell(80);
        app(InvoiceService::class)->cancel($cancelled->fresh(), 'Wrong table');
        $this->sell(999, now()->subDays(3)); // another day entirely

        $date = OutletClock::dateOf($this->shop, $kept->invoiced_at);

        $response = $this->getAs("/api/v1/outlets/{$this->ids['outlet']}/sales-digest?date={$date}", ['sales:read'])
            ->assertOk()
            ->assertJson([
                'outlet_id' => $this->ids['outlet'],
                'business_date' => $date,
                'count' => 2,
                'grand_paise' => 37000,
                'cancelled_sale_ids' => [$cancelled->fresh()->ulid],
            ]);

        $this->assertEqualsCanonicalizing(
            [$kept->fresh()->ulid, $alsoKept->fresh()->ulid],
            $response->json('sale_ids'),
        );
    }

    public function test_the_digest_needs_the_sales_scope_a_date_and_a_served_outlet(): void
    {
        $uri = "/api/v1/outlets/{$this->ids['outlet']}/sales-digest";

        $this->getAs($uri.'?date=2026-09-28', ['menu:read'])->assertStatus(403);
        $this->getAs($uri, ['sales:read'])->assertStatus(422);
        $this->getAs($uri.'?date=2026-02-31', ['sales:read'])->assertStatus(422);
        $this->getAs("/api/v1/outlets/{$this->crmOnlyOutlet()->ulid}/sales-digest?date=2026-09-28", ['sales:read'])->assertStatus(403);
    }

    public function test_a_sale_is_served_as_it_was_recorded_and_where_it_stands_now(): void
    {
        $invoice = $this->sell(450);
        $recorded = OutboxEvent::query()->where('type', 'crm.sale.recorded')->latest('id')->firstOrFail();

        $response = $this->getAs('/api/v1/sales/'.$invoice->fresh()->ulid, ['sales:read'])->assertOk();

        $body = $response->json();
        $this->assertSame('issued', $body['status']);
        $this->assertSame($this->ids['tenant'], $body['tenant_id']);
        $this->assertSame($this->ids['outlet'], $body['outlet_id']);

        $data = array_diff_key($body, array_flip(['tenant_id', 'outlet_id', 'status']));
        $this->assertSame($recorded->data, $data, 'the same sale the event carried');
        $this->assertDataHonours('crm.sale.recorded', $data, $this->ids['tenant'], $this->ids['outlet']);

        app(InvoiceService::class)->cancel($invoice->fresh(), 'Refused at the door');

        $this->getAs('/api/v1/sales/'.$invoice->fresh()->ulid, ['sales:read'])->assertOk()->assertJsonPath('status', 'cancelled');
    }

    public function test_a_sale_needs_the_sales_scope_exists_and_is_at_a_served_outlet(): void
    {
        $invoice = $this->sell(300);

        $this->getAs('/api/v1/sales/'.$invoice->fresh()->ulid, ['menu:read'])->assertStatus(403);
        $this->getAs('/api/v1/sales/'.Ulid::generate(), ['sales:read'])->assertStatus(404);

        $kiosk = $this->crmOnlyOutlet();
        $theirs = $this->sell(90, null, $kiosk);
        $this->getAs('/api/v1/sales/'.$theirs->fresh()->ulid, ['sales:read'])->assertStatus(403);
    }

    /* ------------------------------------------------------------- roles */

    public function test_roles_are_listed_by_key_with_the_presets_marked(): void
    {
        Role::create(['name' => 'Night Manager', 'key' => 'night_manager', 'guard_name' => 'web']);

        $response = $this->getAs('/api/v1/roles?tenant_id='.$this->ids['tenant'], ['roles:read'], ['aud' => 'accounts-machine'])
            ->assertOk()
            ->assertJsonPath('product', 'crm');

        $roles = collect($response->json('roles'))->keyBy('key');

        $this->assertSame(['key' => 'cashier', 'name' => 'Cashier', 'preset' => true], $roles['cashier']);
        $this->assertSame(['key' => 'owner', 'name' => 'Tenant Owner', 'preset' => true], $roles['owner']);
        $this->assertSame(['key' => 'night_manager', 'name' => 'Night Manager', 'preset' => false], $roles['night_manager']);

        // Platform roles are not a business's to hand out.
        $names = $roles->pluck('name');
        $this->assertNotContains(User::SUPER_ADMIN, $names);
        $this->assertNotContains('Admin', $names);

        foreach ($roles as $role) {
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]{0,39}$/', $role['key']);
        }
    }

    public function test_roles_need_the_roles_scope_and_a_business(): void
    {
        $this->getAs('/api/v1/roles?tenant_id='.$this->ids['tenant'], ['menu:read'])->assertStatus(403);
        $this->getAs('/api/v1/roles', ['roles:read'])->assertStatus(422);
        $this->getJson('/api/v1/roles?tenant_id='.$this->ids['tenant'])->assertStatus(401);
    }

    public function test_the_integration_api_beside_these_still_wants_its_own_tokens(): void
    {
        // The Sanctum routes are untouched: a machine token is not a Sanctum
        // token, and does not open them.
        $this->getAs('/api/v1/menu', ['menu:read'])->assertStatus(401);
    }
}
