<?php

namespace Tests\Feature;

use App\Services\Accounts\AccountsClient;
use App\Services\Accounts\AccountsUnavailable;
use App\Services\Crm\CrmClient;
use App\Services\Crm\CrmUnavailable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Khana\Kit\Support\Ulid;
use Tests\Concerns\FakesOtherServices;
use Tests\Concerns\HearsFromCrm;
use Tests\TestCase;

/**
 * The two services ERP reads, and every way they can fail to answer.
 *
 * ERP only ever reads them, and only for a first sync, a button or the
 * nightly check - so what matters here is that an answer is taken as the
 * contract gives it, and that no answer is one plain exception whoever asked
 * can carry on from.
 */
class OtherServicesTest extends TestCase
{
    use FakesOtherServices, HearsFromCrm, RefreshDatabase;

    /** @var array{tenant: string, outlet: string} */
    private array $at;

    protected function setUp(): void
    {
        parent::setUp();

        $this->at = $this->business('Spice Garden', ['erp' => 'active', 'crm' => 'active']);
        $this->fakeOtherServices();
    }

    private function crm(): CrmClient
    {
        return app(CrmClient::class);
    }

    /* -------------------------------------------------------------- CRM */

    public function test_the_menu_is_read_with_a_token_for_reading_menus(): void
    {
        $tikka = $this->menuItem('Paneer Tikka', 320, [['Full', 320], ['Half', 180]]);
        $soup = $this->menuItem('Tomato Soup', 120);
        $this->crmLists($this->at, $tikka);
        $this->crmLists($this->at, $soup, archived: true);

        $items = $this->crm()->menu($this->at['outlet']);

        $this->assertSame(['Paneer Tikka', 'Tomato Soup'], array_column($items, 'name'));
        $this->assertSame([false, true], array_column($items, 'archived'));
        $this->assertSame($tikka['sizes'], $items[0]['sizes']);

        Http::assertSent(fn (Request $request) => $request->url() === $this->accountsUrl.'/oauth/token'
            && $request['grant_type'] === 'client_credentials' && $request['scope'] === 'menu:read');

        Http::assertSent(fn (Request $request) => $request->url() === $this->crmUrl.'/api/v1/outlets/'.$this->at['outlet'].'/menu'
            && $request->hasHeader('Authorization', 'Bearer machine-token'));
    }

    public function test_a_days_sales_are_read_in_brief_and_one_sale_in_full(): void
    {
        $cola = $this->menuItem('Cola', 60);
        $first = $this->crmSold($this->at, $this->saleOf([[$cola, null, 2, 120]]));
        $second = $this->crmSold($this->at, $this->saleOf([[$cola, null, 1, 60]]), 'cancelled');
        $this->crmSold($this->at, $this->saleOf([[$cola, null, 5, 300]], '2026-10-02'));

        $digest = $this->crm()->salesDigest($this->at['outlet'], '2026-10-01');

        $this->assertSame([
            'count' => 1, 'grand_paise' => 12_000,
            'sale_ids' => [$first['sale_id']], 'cancelled_sale_ids' => [$second['sale_id']],
        ], $digest);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/sales-digest?date=2026-10-01'));

        $sale = $this->crm()->sale($first['sale_id']);

        $this->assertSame($first['invoice_no'], $sale['invoice_no']);
        $this->assertSame([$this->at['outlet'], 'issued'], [$sale['outlet_id'], $sale['status']]);
        $this->assertSame($first['lines'], $sale['lines']);

        // A sale CRM has never heard of is no sale, not a failure.
        $this->assertNull($this->crm()->sale(Ulid::generate()));
    }

    public function test_crm_being_down_is_one_plain_exception(): void
    {
        $this->crmDown = true;

        try {
            $this->crm()->menu($this->at['outlet']);
            $this->fail('CRM was down and nothing said so.');
        } catch (CrmUnavailable $e) {
            $this->assertSame('Khana CRM did not answer.', $e->getMessage());
        }

        $this->expectException(CrmUnavailable::class);
        $this->crm()->sale(Ulid::generate());
    }

    public function test_an_error_from_crm_is_not_taken_for_an_answer(): void
    {
        // A day with nothing sold is still an answer.
        $this->assertSame(0, $this->crm()->salesDigest($this->at['outlet'], '2026-10-01')['count']);

        $this->crmError = 503;

        foreach ([fn () => $this->crm()->menu($this->at['outlet']), fn () => $this->crm()->sale(Ulid::generate())] as $ask) {
            try {
                $ask();
                $this->fail('An error was taken for an answer.');
            } catch (CrmUnavailable $e) {
                $this->assertSame('Khana CRM answered with an error (503).', $e->getMessage());
            }
        }

        // And a 404 means "no such sale" only where a sale was asked for.
        $this->crmError = 404;
        $this->assertNull($this->crm()->sale(Ulid::generate()));

        $this->expectException(CrmUnavailable::class);
        $this->crm()->menu($this->at['outlet']);
    }

    public function test_a_digest_that_is_not_shaped_like_one_is_refused(): void
    {
        // "bad" is not an id any service mints, so this is not a list ERP will act on.
        $this->crmSales['bad'] = [
            'outlet' => $this->at['outlet'], 'tenant' => $this->at['tenant'], 'status' => 'issued',
            'data' => ['sale_id' => 'bad', 'business_date' => '2026-10-01', 'totals' => ['grand_paise' => 100]],
        ];

        $this->expectException(CrmUnavailable::class);
        $this->expectExceptionMessage('could not read');

        $this->crm()->salesDigest($this->at['outlet'], '2026-10-01');
    }

    public function test_a_sale_that_is_not_shaped_like_one_is_refused(): void
    {
        $id = Ulid::generate();
        $this->crmSales[$id] = ['outlet' => $this->at['outlet'], 'tenant' => $this->at['tenant'], 'status' => 'issued', 'data' => ['sale_id' => $id, 'invoice_no' => 'POS/1']];

        $this->expectException(CrmUnavailable::class);
        $this->expectExceptionMessage('sent a sale ERP could not read');

        $this->crm()->sale($id);
    }

    public function test_accounts_refusing_the_token_is_crm_unavailable_and_crm_is_never_asked(): void
    {
        $this->tokensRefused = true;

        try {
            $this->crm()->menu($this->at['outlet']);
            $this->fail('No token, and nothing said so.');
        } catch (CrmUnavailable $e) {
            $this->assertStringContainsString('did not give ERP a pass', $e->getMessage());
        }

        $this->assertSame([], $this->askedOfCrm());
    }

    public function test_with_no_address_for_crm_nothing_is_asked_of_anybody(): void
    {
        config(['khana.urls.crm' => null]);

        try {
            $this->crm()->salesDigest($this->at['outlet'], '2026-10-01');
            $this->fail('No address, and nothing said so.');
        } catch (CrmUnavailable $e) {
            $this->assertStringContainsString('No address', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    /* --------------------------------------------------------- Accounts */

    public function test_the_snapshot_of_a_business_is_read_with_a_token_for_reading_businesses(): void
    {
        $snapshot = app(AccountsClient::class)->snapshot($this->at['tenant']);

        $this->assertSame($this->at['tenant'], $snapshot['tenant_id']);
        $this->assertSame([$this->at['outlet']], array_column($snapshot['outlets'], 'outlet_id'));
        $this->assertSame(['erp', 'crm'], array_column($snapshot['entitlements'], 'product'));

        Http::assertSent(fn (Request $request) => $request->url() === $this->accountsUrl.'/oauth/token' && $request['scope'] === 'tenants:read');
        Http::assertSent(fn (Request $request) => $request->url() === $this->accountsUrl.'/api/v1/tenants/'.$this->at['tenant'].'/snapshot'
            && $request->hasHeader('Authorization', 'Bearer machine-token'));
    }

    public function test_accounts_not_answering_is_one_plain_exception(): void
    {
        // A business Accounts has never heard of.
        try {
            app(AccountsClient::class)->snapshot(Ulid::generate());
            $this->fail('A 404 was taken for a snapshot.');
        } catch (AccountsUnavailable $e) {
            $this->assertStringContainsString('error (404)', $e->getMessage());
        }

        // A snapshot of some other business than the one asked for.
        $this->snapshots[$this->at['tenant']] = ['tenant_id' => Ulid::generate()] + $this->snapshotOf($this->at['tenant']);

        try {
            app(AccountsClient::class)->snapshot($this->at['tenant']);
            $this->fail('Another business\'s snapshot was accepted.');
        } catch (AccountsUnavailable $e) {
            $this->assertStringContainsString('could not read', $e->getMessage());
        }

        $this->accountsDown = true;

        $this->expectException(AccountsUnavailable::class);
        app(AccountsClient::class)->snapshot($this->at['tenant']);
    }
}
