<?php

namespace Tests;

use App\Models\Shop;
use App\Support\CurrentShop;
use App\Support\CurrentTenant;
use App\Support\Modules;
use Database\Seeders\CrmEntitlementSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    /**
     * Seed the baseline once per process, when RefreshDatabase migrates.
     *
     * Almost every feature test starts from DatabaseSeeder - roles and their
     * permissions, the first company, its shop and its CRM entitlement, its
     * catalogue - and
     * used to run it in setUp(), inside the test's transaction, so it was
     * rolled back and rebuilt for every single test. That was most of the
     * five seconds each one took. Seeded here instead, the rows are already
     * there when each test's transaction opens and are exactly what the
     * rollback returns to - the same starting point, built once.
     *
     * Read by RefreshDatabase through property_exists(), which is why it is
     * a property and not a shouldSeed() override: the trait's own method
     * would win over one inherited from here.
     */
    protected $seed = true;

    /**
     * For the few tests that are about a brand-new install - the landing
     * page with nothing configured, say.
     *
     * The baseline above is shared by the whole process, so a class cannot
     * simply not seed. It empties every table instead, inside the test's own
     * transaction, and the rollback brings the baseline back for whoever runs
     * next. Foreign keys are deferred rather than switched off, because
     * SQLite ignores `foreign_keys = OFF` inside a transaction.
     */
    protected bool $startsEmpty = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->forgetContext();

        /*
         | Every shop a test makes has bought CRM.
         |
         | In a running system a shop only exists in CRM because Accounts made
         | the outlet and sold it the product, and the entitlement arrives by
         | event. There is no Accounts here, so without this every test that
         | opens a second branch would meet EnsureShopIsEntitled before the
         | thing it is testing - and a 403 from the wrong gate would pass
         | every "is this refused?" assertion for the wrong reason.
         |
         | A test about the gate itself sets another state with entitle().
         */
        Shop::created(fn (Shop $shop) => CrmEntitlementSeeder::entitle($shop));

        if ($this->startsEmpty) {
            $this->emptyDatabase();
        }
    }

    /**
     * Put a shop's CRM entitlement in the state Accounts would have sent:
     * trial, active, grace, suspended or ended.
     */
    protected function entitle(Shop $shop, string $state = 'active'): void
    {
        CrmEntitlementSeeder::entitle($shop->fresh() ?? $shop, $state);
    }

    private function emptyDatabase(): void
    {
        $connection = DB::connection();

        $connection->statement('PRAGMA defer_foreign_keys = ON');

        foreach (Schema::getTables() as $table) {
            if ($table['name'] !== 'migrations') {
                $connection->table($table['name'])->delete();
            }
        }
    }

    protected function tearDown(): void
    {
        $this->forgetContext();

        parent::tearDown();
    }

    /**
     * `$this->seed()` with no argument, in a RefreshDatabase test, is
     * already done - see `$seed` above. Any other seeder still runs.
     */
    public function seed($class = DatabaseSeeder::class)
    {
        $refreshes = in_array(RefreshDatabase::class, class_uses_recursive(static::class), true);

        if ($refreshes && $class === DatabaseSeeder::class) {
            return $this;
        }

        return parent::seed($class);
    }

    /**
     * Whose company and branch this process thinks it is in.
     *
     * These three memoise for the life of the PHP process, not the request,
     * which is right in a web app - one request is one company - and wrong
     * in a suite, where one process is hundreds of databases. A test that
     * resolved a tenant handed its id to every class that ran after it,
     * against rows that had been rolled back underneath it, and the only
     * evidence was a foreign-key SQLSTATE in a class that had nothing to do
     * with tenancy. Whole files passed alone and failed in the suite.
     *
     * Cleared on both sides on purpose: tearDown covers the ordinary case,
     * setUp covers the test that died before it got there.
     */
    private function forgetContext(): void
    {
        CurrentTenant::forget();
        // Cascades into Modules, which is cleared again below so that this
        // list reads as what it guarantees rather than as something that
        // depends on another class keeping its habit.
        CurrentShop::forget();
        Modules::forget();
    }
}
