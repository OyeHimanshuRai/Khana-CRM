<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Services\Erp\ErpClient;
use App\Services\Erp\ErpLink;
use App\Services\Erp\ErpUnavailable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Khana\Kit\Support\Ulid;
use Tests\Concerns\ReadsErp;
use Tests\Concerns\TalksToErp;
use Tests\TestCase;

/**
 * How CRM reads ERP (contracts/api/erp.yaml), and when it does not.
 *
 * What is held here: each read goes to the right address with a token asked
 * for the right scope and comes back in ERP's own shape; a screen never
 * waits more than three seconds; every way of failing is the one exception
 * the panels catch; and an outlet that is not linked to ERP is never the
 * subject of a request at all.
 */
class ErpClientTest extends TestCase
{
    use ReadsErp, RefreshDatabase, TalksToErp;

    private Shop $shop;

    private string $outlet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::query()->orderBy('id')->firstOrFail();
        $this->outlet = $this->linkToAccounts($this->shop)['outlet'];
    }

    private function client(): ErpClient
    {
        return app(ErpClient::class);
    }

    /** One open bill, as the contract shapes it. */
    private function bill(array $with = []): array
    {
        return array_replace([
            'bill_id' => Ulid::generate(),
            'supplier_name' => 'Fresh Farms',
            'bill_no' => 'FF/102',
            'billed_on' => '2026-09-20',
            'due_on' => '2026-09-27',
            'total_paise' => 250000,
            'due_paise' => 125050,
        ], $with);
    }

    /* ------------------------------------------------------------- reads */

    public function test_open_bills_are_asked_for_with_the_bills_scope_and_returned_as_erp_sent_them(): void
    {
        $bills = [$this->bill(), $this->bill(['supplier_name' => 'City Dairy', 'bill_no' => 'CD-9', 'due_on' => null])];
        $this->erpAnswers($this->outlet, ['supplier-bills' => ['bills' => $bills]]);

        $this->assertSame($bills, $this->client()->openBills($this->outlet));

        Http::assertSent(fn (Request $request) => $request->url() === self::ACCOUNTS_URL.'/oauth/token'
            && $request['grant_type'] === 'client_credentials'
            && $request['scope'] === 'bills:read');

        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && $request->url() === $this->erpEndpoint($this->outlet, 'supplier-bills').'?status=open'
            && $request->hasHeader('Authorization', 'Bearer machine-token'));
    }

    public function test_item_stock_is_read_with_the_stock_scope(): void
    {
        $item = Ulid::generate();
        $stock = [
            'item_id' => $item,
            'has_recipe' => true,
            'sizes' => [[
                'variant_id' => null,
                'portions_available' => '12.0000',
                'cost_paise' => 8125,
                'food_cost_percent' => '32.50',
                'available' => true,
            ]],
        ];
        $this->erpAnswers($this->outlet, ["items/{$item}/stock" => $stock]);

        $this->assertSame($stock, $this->client()->itemStock($this->outlet, $item));

        Http::assertSent(fn (Request $request) => $request->url() === self::ACCOUNTS_URL.'/oauth/token'
            && $request['scope'] === 'stock:read');
        Http::assertSent(fn (Request $request) => $request->url() === $this->erpEndpoint($this->outlet, "items/{$item}/stock"));
    }

    public function test_a_dish_erp_has_not_heard_of_is_null_not_a_failure(): void
    {
        $item = Ulid::generate();
        $this->erpAnswers($this->outlet, [
            "items/{$item}/stock" => Http::response(['message' => 'ERP has not heard of this item.'], 404),
        ]);

        $this->assertNull($this->client()->itemStock($this->outlet, $item));
    }

    public function test_food_cost_is_the_current_day_unless_a_date_is_named(): void
    {
        $day = [
            'outlet_id' => $this->outlet,
            'business_date' => '2026-10-01',
            'sales_paise' => 1000000,
            'cost_paise' => 325000,
            'food_cost_percent' => '32.50',
            'uncosted' => 3,
        ];
        $this->erpAnswers($this->outlet, ['food-cost' => $day]);

        $this->assertSame($day, $this->client()->foodCost($this->outlet));
        Http::assertSent(fn (Request $request) => $request->url() === $this->erpEndpoint($this->outlet, 'food-cost'));

        $this->client()->foodCost($this->outlet, '2026-09-30');
        Http::assertSent(fn (Request $request) => $request->url() === $this->erpEndpoint($this->outlet, 'food-cost').'?date=2026-09-30');
    }

    public function test_signals_are_the_items_list(): void
    {
        $items = [
            ['item_id' => Ulid::generate(), 'variant_id' => null, 'available' => false, 'cost_paise' => 8125],
            ['item_id' => Ulid::generate(), 'variant_id' => Ulid::generate(), 'available' => true, 'cost_paise' => null],
        ];
        $this->erpAnswers($this->outlet, [
            'signals' => ['outlet_id' => $this->outlet, 'items' => $items, 'taken_at' => '2026-10-01T20:30:00Z'],
        ]);

        $this->assertSame($items, $this->client()->signals($this->outlet));

        Http::assertSent(fn (Request $request) => $request->url() === self::ACCOUNTS_URL.'/oauth/token'
            && $request['scope'] === 'stock:read');
    }

    public function test_a_screen_waits_three_seconds_and_the_nightly_check_ten(): void
    {
        $this->erpIsConfigured();

        $waited = [];
        $item = Ulid::generate();

        Http::fake([
            self::ACCOUNTS_URL.'/oauth/token' => Http::response(['access_token' => 'machine-token', 'expires_in' => 3600]),
            self::ERP_URL.'/*' => function (Request $request, array $options) use (&$waited) {
                $path = basename(parse_url($request->url(), PHP_URL_PATH));
                $waited[$path] = [$options['timeout'] ?? null, $options['connect_timeout'] ?? null];

                return Http::response(['bills' => [], 'items' => [], 'sizes' => []]);
            },
        ]);

        $this->client()->openBills($this->outlet);
        $this->client()->itemStock($this->outlet, $item);
        $this->client()->foodCost($this->outlet);
        $this->client()->signals($this->outlet);

        $this->assertSame([3, 3], $waited['supplier-bills']);
        $this->assertSame([3, 3], $waited['stock']);
        $this->assertSame([3, 3], $waited['food-cost']);
        $this->assertSame([10, 10], $waited['signals']);
    }

    /* ---------------------------------------------------------- failures */

    public function test_erp_being_down_is_erp_unavailable(): void
    {
        $this->erpIsDown();

        $this->expectException(ErpUnavailable::class);

        $this->client()->openBills($this->outlet);
    }

    public function test_accounts_refusing_a_token_is_erp_unavailable_and_erp_is_never_asked(): void
    {
        $this->erpIsConfigured();
        Http::fake([self::ACCOUNTS_URL.'/oauth/token' => Http::response(['error' => 'invalid_client'], 401)]);

        try {
            $this->client()->foodCost($this->outlet);
            $this->fail('A refused token was not reported.');
        } catch (ErpUnavailable) {
            // expected
        }

        $this->assertErpAsked(0);
    }

    public function test_an_error_from_erp_is_erp_unavailable(): void
    {
        $item = Ulid::generate();

        $this->erpAnswers($this->outlet, [
            'supplier-bills' => Http::response(['message' => 'ERP has not heard of that outlet.'], 404),
            'food-cost' => Http::response(['message' => 'Server Error'], 500),
            "items/{$item}/stock" => Http::response(['message' => 'That outlet does not have Khana ERP.'], 403),
            'signals' => Http::response('<html>Bad gateway</html>', 200),
        ]);

        $reads = [
            'a 404 that is not a dish' => fn () => $this->client()->openBills($this->outlet),
            'a 500' => fn () => $this->client()->foodCost($this->outlet),
            'a 403' => fn () => $this->client()->itemStock($this->outlet, $item),
            'a body that is not JSON' => fn () => $this->client()->signals($this->outlet),
        ];

        $refused = [];

        foreach ($reads as $what => $read) {
            try {
                $read();
            } catch (ErpUnavailable) {
                $refused[] = $what;
            }
        }

        $this->assertSame(array_keys($reads), $refused, 'something that was not an answer was taken for one');
    }

    public function test_an_answer_without_its_list_is_erp_unavailable(): void
    {
        $this->erpAnswers($this->outlet, ['supplier-bills' => ['message' => 'ok']]);

        $this->expectException(ErpUnavailable::class);

        $this->client()->openBills($this->outlet);
    }

    /* --------------------------------------------------- who is not asked */

    public function test_an_outlet_with_crm_alone_is_never_the_subject_of_a_request(): void
    {
        $kiosk = $this->secondOutlet(['crm' => 'active']);
        $this->erpWouldAnswerAnything();

        try {
            $this->client()->openBills($kiosk->ulid);
            $this->fail('ERP was asked about an outlet that does not have it.');
        } catch (ErpUnavailable) {
            // expected
        }

        Http::assertNothingSent();
    }

    public function test_an_install_with_no_address_for_erp_asks_nobody(): void
    {
        $this->erpWouldAnswerAnything();
        config(['khana.urls.erp' => null]);

        try {
            $this->client()->foodCost($this->outlet);
            $this->fail('ERP was asked with no address to ask at.');
        } catch (ErpUnavailable) {
            // expected
        }

        Http::assertNothingSent();
    }

    /* ----------------------------------------------------------- the link */

    public function test_an_outlet_is_linked_when_it_holds_erp_and_erp_has_an_address(): void
    {
        $this->erpIsConfigured();
        $link = app(ErpLink::class);

        $this->assertTrue($link->linked($this->shop));
        $this->assertTrue($link->owns($this->shop));
        $this->assertTrue($link->linkedOutlet($this->outlet));

        $kiosk = $this->secondOutlet(['crm' => 'active']);
        $this->assertFalse($link->linked($kiosk), 'an outlet with CRM alone');
        $this->assertFalse($link->owns($kiosk));

        // Suspended still holds it: ERP answers reads for a suspended outlet.
        $suspended = $this->secondOutlet(['crm' => 'active', 'erp' => 'suspended'], 'SUS');
        $this->assertTrue($link->linked($suspended));

        $ended = $this->secondOutlet(['crm' => 'active', 'erp' => 'ended'], 'END');
        $this->assertFalse($link->linked($ended));
        $this->assertFalse($link->owns($ended));

        $this->assertFalse($link->linked(null), 'the All-shops view has no outlet to ask about');
        $this->assertFalse($link->linkedOutlet('not-an-outlet'));
    }

    public function test_an_outlet_that_holds_erp_is_not_linked_while_erp_has_no_address(): void
    {
        config(['khana.urls.erp' => '']);
        $link = app(ErpLink::class);

        $this->assertFalse($link->linked($this->shop));
        $this->assertTrue($link->owns($this->shop), 'still not an outlet with CRM alone');
        $this->assertNull(ErpLink::url());

        config(['khana.urls.erp' => 'https://erp.example.test/']);
        $this->assertSame('https://erp.example.test', ErpLink::url());
        $this->assertTrue($link->linked($this->shop));
    }

    public function test_a_shop_accounts_never_named_is_not_linked(): void
    {
        $this->erpIsConfigured();
        $this->shop->forceFill(['ulid' => null])->saveQuietly();

        $this->assertFalse(app(ErpLink::class)->linked($this->shop->fresh()));
    }

    /**
     * @param  array<string, string>  $products  product => entitlement state
     */
    private function secondOutlet(array $products, string $code = 'KSK'): Shop
    {
        $shop = Shop::create([
            'tenant_id' => $this->shop->tenant_id,
            'name' => 'Outlet '.$code, 'code' => $code, 'slug' => 'outlet-'.strtolower($code),
            'invoice_prefix' => 'INV', 'pos_prefix' => 'POS',
            'currency' => 'INR', 'timezone' => 'Asia/Kolkata', 'is_active' => true,
        ]);

        $this->linkToAccounts($shop, $products);

        return $shop->fresh();
    }
}
