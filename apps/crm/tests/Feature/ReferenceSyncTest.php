<?php

namespace Tests\Feature;

use App\Models\NightlyCheck;
use App\Services\Nightly\ReferenceCheck;
use App\Services\ReferenceSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Khana\Kit\Tenancy\Entitlements;
use Tests\TestCase;

/**
 * The nightly reference check: CRM's copy of a business, its outlets and what
 * each has bought, against Accounts - and how a lost accounts.* event is
 * found and put right.
 *
 * Accounts is always faked at the HTTP client. The starting state is seeded by
 * applying a first snapshot, so the only thing that can differ on the next
 * pull is the thing a test deliberately changes.
 */
class ReferenceSyncTest extends TestCase
{
    use RefreshDatabase;

    private const ACCOUNTS = 'https://accounts.example.test';

    private string $tenant = '01M3WHCEVH4J5Q840AP0AW20TEN';

    private string $outlet = '01M3WHCEVH4J5Q840AP0AW20EJ';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'khana.urls.accounts' => self::ACCOUNTS,
            'khana.sso.accounts_url' => self::ACCOUNTS,
            'khana.sso.machine_client_id' => 'crm-machine',
            'khana.sso.machine_client_secret' => 'machine-secret',
        ]);

        // The copy as it stood last night: CRM holds crm + erp, both active.
        app(ReferenceSync::class)->apply($this->tenant, $this->snapshot('2026-09-01T00:00:00Z'));
        app(Entitlements::class)->forget();
    }

    /**
     * A whole-business snapshot, in the shape of GET /tenants/{id}/snapshot.
     *
     * @param  array<string, string>  $entitlementStates  product => state (crm+erp active by default)
     * @return array<string, mixed>
     */
    private function snapshot(string $takenAt, array $entitlementStates = ['crm' => 'active', 'erp' => 'active'], bool $extraOutlet = false): array
    {
        $outlets = [[
            'outlet_id' => $this->outlet, 'name' => 'MG Road', 'code' => 'MG',
            'address' => ['line1' => '1 Road', 'city' => 'Delhi'], 'state_code' => '07',
            'timezone' => 'Asia/Kolkata', 'day_cutoff' => '04:00', 'status' => 'active',
        ]];

        $entitlements = [];

        foreach ($entitlementStates as $product => $state) {
            $entitlements[] = ['outlet_id' => $this->outlet, 'product' => $product, 'state' => $state, 'plan' => 'dev', 'limits' => []];
        }

        return [
            'tenant_id' => $this->tenant,
            'tenant' => ['name' => 'Spice Route', 'status' => 'active'],
            'outlets' => $outlets,
            'entitlements' => $entitlements,
            'users' => [],
            'access' => [],
            'taken_at' => $takenAt,
        ];
    }

    /** Accounts issues a machine token, then answers the snapshot for this business. */
    private function accountsAnswers(array $snapshot): void
    {
        Http::preventStrayRequests();

        Http::fake([
            self::ACCOUNTS.'/oauth/token' => Http::response(['access_token' => 'machine-token', 'expires_in' => 3600]),
            self::ACCOUNTS.'/api/v1/tenants/'.$this->tenant.'/snapshot' => Http::response($snapshot),
        ]);
    }

    private function crmState(): ?string
    {
        return DB::table('khana_entitlements')
            ->where('outlet_id', $this->outlet)->where('product', 'crm')->value('state');
    }

    public function test_a_lost_entitlement_change_is_found_and_put_right(): void
    {
        // CRM still thinks this outlet has CRM; Accounts ended it, and the event
        // was lost. Until the check runs, a lapsed outlet stays open.
        $this->assertSame('active', $this->crmState());

        $this->accountsAnswers($this->snapshot('2026-10-02T00:00:00Z', ['crm' => 'ended', 'erp' => 'active']));

        $finding = app(ReferenceCheck::class)->run($this->outlet);

        $this->assertSame(NightlyCheck::REPAIRED, $finding->status);
        $this->assertSame(1, $finding->repaired);
        $this->assertSame('ended', $this->crmState(), 'the lapse is now in CRM\'s copy');
    }

    public function test_a_copy_already_in_line_is_all_in_order(): void
    {
        $this->accountsAnswers($this->snapshot('2026-10-02T00:00:00Z'));

        $finding = app(ReferenceCheck::class)->run($this->outlet);

        $this->assertSame(NightlyCheck::OK, $finding->status);
        $this->assertSame(0, $finding->found);
    }

    public function test_an_outlet_crm_holds_that_accounts_does_not_list_is_a_named_problem(): void
    {
        // CRM carries a second outlet Accounts no longer lists (a lost delete,
        // or a bad merge). It is named and left, never removed.
        DB::table('khana_outlets')->insert([
            'id' => '01M3WHCEVH4J5Q840AP0AW20ST', 'tenant_id' => $this->tenant,
            'name' => 'Ghost Branch', 'code' => 'GH', 'address' => '{}', 'state_code' => '07',
            'timezone' => 'Asia/Kolkata', 'day_cutoff' => '04:00', 'status' => 'active',
            'changed_at' => '2026-09-01 00:00:00',
        ]);

        $this->accountsAnswers($this->snapshot('2026-10-02T00:00:00Z'));

        $finding = app(ReferenceCheck::class)->run($this->outlet);

        $this->assertSame(NightlyCheck::PROBLEM, $finding->status);
        $this->assertStringContainsString('Ghost Branch', $finding->detail);
        $this->assertSame(1, DB::table('khana_outlets')->where('name', 'Ghost Branch')->count(), 'named, not removed');
    }

    public function test_with_accounts_unreachable_it_is_a_problem_to_try_again(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::ACCOUNTS.'/oauth/token' => Http::response(['access_token' => 'machine-token', 'expires_in' => 3600]),
            self::ACCOUNTS.'/api/v1/tenants/*' => Http::response('', 503),
        ]);

        $finding = app(ReferenceCheck::class)->run($this->outlet);

        $this->assertSame(NightlyCheck::PROBLEM, $finding->status);
        $this->assertSame('active', $this->crmState(), 'nothing changed when Accounts could not be read');
    }

    public function test_a_standalone_install_with_no_accounts_is_skipped(): void
    {
        config(['khana.urls.accounts' => null]);

        $finding = app(ReferenceCheck::class)->run($this->outlet);

        $this->assertSame(NightlyCheck::SKIPPED, $finding->status);
    }
}
