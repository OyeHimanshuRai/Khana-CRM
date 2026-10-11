<?php

namespace Tests\Concerns;

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * CRM's API and Accounts, as far as ERP can tell when it reads them.
 *
 * A test says what CRM holds - the menu it lists, the sales it made - and
 * the fake answers the three calls of contracts/api/crm.yaml from that, the
 * way CRM itself would: the digest is worked out from the sales, not typed
 * in beside them. Accounts answers with a snapshot that agrees with ERP's
 * own copy unless the test says otherwise.
 *
 * Nothing leaves the machine. A request to any address not faked here fails
 * the test rather than going out.
 */
trait FakesOtherServices
{
    protected string $crmUrl = 'https://crm.example.test';

    protected string $accountsUrl = 'https://accounts.example.test';

    /** @var array<string, list<array<string, mixed>>> outlet => the menu CRM lists for it */
    protected array $crmMenus = [];

    /** @var array<string, array{outlet: string, tenant: string, status: string, data: array<string, mixed>}> sale id => the sale as CRM holds it */
    protected array $crmSales = [];

    protected bool $crmDown = false;

    /** CRM answers every call with this status instead: 503, say. */
    protected ?int $crmError = null;

    /** @var list<string> sale ids CRM lists but fails on when asked for */
    protected array $crmChokesOn = [];

    protected bool $accountsDown = false;

    /** Accounts answers, but will not issue a machine token. */
    protected bool $tokensRefused = false;

    /** @var array<string, array<string, mixed>> business => the snapshot Accounts answers with, when not ERP's own copy */
    protected array $snapshots = [];

    /** @var list<int> how deep in database transactions ERP was at each request it made */
    protected array $transactionDepths = [];

    /** @var list<string> */
    private array $askedOfCrm = [];

    /** @var list<string> */
    private array $askedOfAccounts = [];

    protected function fakeOtherServices(): void
    {
        config([
            'khana.urls.crm' => $this->crmUrl,
            'khana.urls.accounts' => $this->accountsUrl,
            'khana.sso.accounts_url' => $this->accountsUrl,
            'khana.sso.machine_client_id' => 'erp-machine',
            'khana.sso.machine_client_secret' => 'machine-secret',
        ]);

        Http::preventStrayRequests();

        // Faked once: a second Http::fake() adds stubs behind the first.
        Http::fake([
            $this->accountsUrl.'/oauth/token' => fn () => match (true) {
                $this->accountsDown => throw new ConnectionException('Connection refused'),
                $this->tokensRefused => Http::response(['error' => 'invalid_client'], 401),
                default => Http::response(['access_token' => 'machine-token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
            },
            $this->crmUrl.'/api/v1/*' => fn (Request $request) => $this->crmAnswers($request),
            $this->accountsUrl.'/api/v1/tenants/*' => fn (Request $request) => $this->accountsAnswers($request),
        ]);
    }

    /* ------------------------------------------------------ what CRM holds */

    /**
     * CRM has this item on the outlet's menu (or has it archived).
     *
     * @param  array{tenant: string, outlet: string}  $at
     * @param  array<string, mixed>  $menuItem  as HearsFromCrm::menuItem() builds it
     */
    protected function crmLists(array $at, array $menuItem, bool $archived = false): void
    {
        $this->crmMenus[$at['outlet']] = array_values(array_filter(
            $this->crmMenus[$at['outlet']] ?? [],
            fn (array $listed) => $listed['item_id'] !== $menuItem['item_id'],
        ));

        $this->crmMenus[$at['outlet']][] = $menuItem + ['archived' => $archived];
    }

    /** CRM no longer lists the item at all. */
    protected function crmForgets(array $at, array $menuItem): void
    {
        $this->crmMenus[$at['outlet']] = array_values(array_filter(
            $this->crmMenus[$at['outlet']] ?? [],
            fn (array $listed) => $listed['item_id'] !== $menuItem['item_id'],
        ));
    }

    /**
     * CRM made this sale, whether or not ERP ever hears the event.
     *
     * @param  array{tenant: string, outlet: string}  $at
     * @param  array<string, mixed>  $sale  the data of crm.sale.recorded, as HearsFromCrm::saleOf() builds it
     * @return array<string, mixed> the same sale
     */
    protected function crmSold(array $at, array $sale, string $status = 'issued'): array
    {
        $this->crmSales[$sale['sale_id']] = ['outlet' => $at['outlet'], 'tenant' => $at['tenant'], 'status' => $status, 'data' => $sale];

        return $sale;
    }

    /** @param  array<string, mixed>  $sale */
    protected function crmCancelled(array $sale): void
    {
        $this->crmSales[$sale['sale_id']]['status'] = 'cancelled';
    }

    /* ------------------------------------------------ what was asked of them */

    /**
     * The addresses ERP asked CRM's API for, answered or not. (Kept here
     * because Http::recorded() leaves out a request that got no answer.)
     *
     * @return list<string>
     */
    protected function askedOfCrm(): array
    {
        return $this->askedOfCrm;
    }

    /** @return list<string> the snapshots ERP asked Accounts for, answered or not */
    protected function askedOfAccounts(): array
    {
        return $this->askedOfAccounts;
    }

    /**
     * The snapshot Accounts would give if it agreed with ERP's copy in every
     * particular - the starting point for a test that then changes one.
     *
     * @return array<string, mixed>
     */
    protected function snapshotOf(string $tenantId): array
    {
        $tenant = DB::table('khana_tenants')->where('id', $tenantId)->first();
        $users = User::query()->where('tenant_id', $tenantId)->orderBy('id')->get();

        $time = fn (?string $stored) => $stored === null ? null : gmdate('Y-m-d\TH:i:s\Z', strtotime($stored.' UTC'));

        return [
            'tenant_id' => $tenantId,
            'tenant' => [
                'name' => $tenant->name, 'legal_name' => $tenant->legal_name, 'gstin' => $tenant->gstin,
                'pan' => $tenant->pan, 'status' => $tenant->status,
            ],
            'outlets' => DB::table('khana_outlets')->where('tenant_id', $tenantId)->orderBy('id')->get()->map(fn ($outlet) => [
                'outlet_id' => $outlet->id, 'name' => $outlet->name, 'code' => $outlet->code, 'gstin' => $outlet->gstin,
                'fssai' => $outlet->fssai, 'address' => json_decode($outlet->address, true), 'state_code' => $outlet->state_code,
                'timezone' => $outlet->timezone, 'day_cutoff' => $outlet->day_cutoff, 'status' => $outlet->status,
            ])->all(),
            'users' => $users->map(fn (User $user) => [
                'user_id' => $user->ulid, 'name' => $user->name, 'email' => $user->email, 'mobile' => $user->mobile,
                'status' => $user->is_active ? 'active' : 'deactivated',
            ])->all(),
            'access' => $users->whereNotNull('role_key')->map(fn (User $user) => [
                'user_id' => $user->ulid, 'product' => 'erp', 'role_key' => $user->role_key,
                'outlet_ids' => array_values((array) $user->outlet_ids),
            ])->values()->all(),
            'entitlements' => DB::table('khana_entitlements')->where('tenant_id', $tenantId)->orderBy('id')->get()->map(fn ($row) => [
                'outlet_id' => $row->outlet_id, 'product' => $row->product, 'state' => $row->state, 'plan' => $row->plan,
                'limits' => json_decode($row->limits, true), 'valid_until' => $time($row->valid_until),
            ])->all(),
            'taken_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /* ---------------------------------------------------------- answers */

    private function crmAnswers(Request $request): mixed
    {
        $this->transactionDepths[] = DB::transactionLevel();
        $this->askedOfCrm[] = $request->url();

        if ($this->crmDown) {
            throw new ConnectionException('Connection refused');
        }

        if ($this->crmError !== null) {
            return Http::response(['message' => 'Something went wrong.'], $this->crmError);
        }

        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        if (preg_match('#^/api/v1/outlets/([^/]+)/menu$#', $path, $m) === 1) {
            return Http::response([
                'outlet_id' => $m[1],
                'items' => $this->crmMenus[$m[1]] ?? [],
                'taken_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            ]);
        }

        if (preg_match('#^/api/v1/outlets/([^/]+)/sales-digest$#', $path, $m) === 1) {
            $day = collect($this->crmSales)->filter(
                fn (array $sale) => $sale['outlet'] === $m[1] && $sale['data']['business_date'] === ($query['date'] ?? null),
            );
            $standing = $day->where('status', 'issued');

            return Http::response([
                'outlet_id' => $m[1],
                'business_date' => $query['date'] ?? null,
                'count' => $standing->count(),
                'grand_paise' => (int) $standing->sum(fn (array $sale) => $sale['data']['totals']['grand_paise']),
                'sale_ids' => $standing->keys()->values()->all(),
                'cancelled_sale_ids' => $day->where('status', 'cancelled')->keys()->values()->all(),
            ]);
        }

        if (preg_match('#^/api/v1/sales/([^/]+)$#', $path, $m) === 1) {
            if (in_array($m[1], $this->crmChokesOn, true)) {
                return Http::response(['message' => 'Server error.'], 500);
            }

            $sale = $this->crmSales[$m[1]] ?? null;

            return $sale === null
                ? Http::response(['message' => 'No such sale.'], 404)
                : Http::response($sale['data'] + ['tenant_id' => $sale['tenant'], 'outlet_id' => $sale['outlet'], 'status' => $sale['status']]);
        }

        return Http::response(['message' => 'Not found.'], 404);
    }

    private function accountsAnswers(Request $request): mixed
    {
        $this->transactionDepths[] = DB::transactionLevel();
        $this->askedOfAccounts[] = $request->url();

        if ($this->accountsDown) {
            throw new ConnectionException('Connection refused');
        }

        if (preg_match('#/api/v1/tenants/([^/]+)/snapshot$#', (string) parse_url($request->url(), PHP_URL_PATH), $m) !== 1) {
            return Http::response(['message' => 'Not found.'], 404);
        }

        if (! isset($this->snapshots[$m[1]]) && ! DB::table('khana_tenants')->where('id', $m[1])->exists()) {
            return Http::response(['message' => 'No such business.'], 404);
        }

        return Http::response($this->snapshots[$m[1]] ?? $this->snapshotOf($m[1]));
    }
}
