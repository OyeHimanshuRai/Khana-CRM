<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\CashRegister;
use App\Models\Shop;
use App\Models\User;
use App\Services\AlertService;
use App\Support\CurrentShop;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The scheduled half of SRS 15.
 *
 * Two properties matter more than the individual alerts, and both are things
 * that only show up under repetition:
 *
 *   **Idempotence.** The sweep runs twice a day and may be run by hand at any
 *   time. A second run must update, never duplicate - a shop whose bell shows
 *   the same overdue loan six times stops reading the bell.
 *
 *   **Read state survives.** Somebody who has seen "this loan is overdue"
 *   must not have it march back into their unread count every morning until
 *   it is paid. The alert is still true and still listed; it has simply
 *   stopped being news.
 */
class AlertSweepTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');

        $this->seed();

        CurrentShop::forget();
        CurrentTenant::forget();
    }

    protected function tearDown(): void
    {
        CurrentShop::forget();
        CurrentTenant::forget();

        parent::tearDown();
    }

    private function shop(): Shop
    {
        return Shop::query()->orderBy('id')->firstOrFail();
    }

    private function admin(): User
    {
        return User::where('is_admin', true)->firstOrFail();
    }

    /** A till left open on a day that has already finished. */
    private function staleRegister(): CashRegister
    {
        $register = new CashRegister();

        $register->forceFill([
            'shop_id' => $this->shop()->id,
            'business_date' => today()->subDays(3),
            'status' => CashRegister::OPEN,
            'opening_float' => 1000,
            'opened_at' => now()->subDays(3),
            'opened_by_name' => 'Yesterday Cashier',
        ])->save();

        return $register;
    }

    /**
     * A till open on its own business date is a shop that is trading, not a
     * shop that forgot. Flagging it would put an alert on the screen every
     * afternoon and teach everyone to ignore the bell.
     */
    public function test_only_a_till_left_open_past_its_own_day_is_flagged(): void
    {
        $stale = $this->staleRegister();

        $today = new CashRegister();
        $today->forceFill([
            'shop_id' => $this->shop()->id,
            'business_date' => today(),
            'status' => CashRegister::OPEN,
            'opening_float' => 1000,
            'opened_at' => now(),
        ])->save();

        $raised = app(AlertService::class)->dayClosePending($this->shop());

        $this->assertSame(1, $raised);

        $this->assertDatabaseHas('alerts', [
            'type' => Alert::DAY_CLOSE_PENDING,
            'reference_id' => $stale->id,
        ]);

        $this->assertDatabaseMissing('alerts', [
            'type' => Alert::DAY_CLOSE_PENDING,
            'reference_id' => $today->id,
        ]);
    }

    /**
     * The property the dedupe key exists for.
     *
     * A "have I done this already" query would race with itself; a unique
     * index cannot. Running the sweep twice has to update, not duplicate.
     */
    public function test_running_the_sweep_twice_raises_nothing_new(): void
    {
        $this->staleRegister();

        $alerts = app(AlertService::class);

        $alerts->sweep($this->shop());
        $first = Alert::allShops()->count();

        $this->assertGreaterThan(0, $first);

        $alerts->sweep($this->shop());

        $this->assertSame($first, Alert::allShops()->count());
    }

    public function test_a_dismissed_alert_stays_dismissed_across_a_sweep(): void
    {
        $this->staleRegister();

        $alerts = app(AlertService::class);
        $alerts->sweep($this->shop());

        /** @var Alert $alert */
        $alert = Alert::allShops()->where('type', Alert::DAY_CLOSE_PENDING)->firstOrFail();
        $alerts->markRead($alert);

        $alerts->sweep($this->shop());

        // Still true, still listed, no longer news.
        $this->assertNotNull($alert->fresh()->read_at);
    }

    /* ----------------------------------------------------------- command */

    public function test_the_command_sweeps_every_active_branch(): void
    {
        $this->staleRegister();

        $this->artisan('alerts:sweep')
            ->assertExitCode(0);

        $this->assertGreaterThan(0, Alert::allShops()->count());
    }

    public function test_the_command_can_be_pointed_at_one_branch(): void
    {
        $this->staleRegister();

        $this->artisan('alerts:sweep', ['--shop' => $this->shop()->id])
            ->assertExitCode(0);

        $this->assertGreaterThan(0, Alert::allShops()->count());
    }

    public function test_an_unknown_branch_fails_rather_than_sweeping_everything(): void
    {
        $this->artisan('alerts:sweep', ['--shop' => 999999])
            ->assertExitCode(1);
    }

    /**
     * Pruning takes out what has expired and leaves what has not.
     */
    public function test_pruning_removes_only_expired_alerts(): void
    {
        $service = app(AlertService::class);

        $service->raise(
            shop: $this->shop(),
            type: Alert::LOW_STOCK,
            title: 'Gone stale',
            dedupeKey: 'probe:expired',
            expiresAt: now()->subDay(),
        );

        $service->raise(
            shop: $this->shop(),
            type: Alert::LOW_STOCK,
            title: 'Still relevant',
            dedupeKey: 'probe:live',
        );

        $this->assertSame(1, $service->prune());

        $this->assertDatabaseMissing('alerts', ['dedupe_key' => 'probe:expired']);
        $this->assertDatabaseHas('alerts', ['dedupe_key' => 'probe:live']);
    }

    /* --------------------------------------------------------------- bell */

    /**
     * The bell is a fragment, injected into the header by layout.js. Anything
     * interactive in it has to be delegated from a globally loaded file, so
     * there must be no <script> inside it.
     */
    public function test_the_bell_fragment_carries_no_script(): void
    {
        $this->staleRegister();
        app(AlertService::class)->sweep($this->shop());

        $this->actingAs($this->admin())
            ->get('/admin/alerts/bell')
            ->assertOk()
            ->assertSee('has not been closed')
            ->assertDontSee('<body', false)
            ->assertDontSee('<script', false);
    }

    /**
     * SRS 15 addresses by role. An alert gated on a permission must not
     * appear for somebody who does not hold it, and the badge count has to
     * agree with the list that opens beneath it.
     */
    public function test_an_alert_is_hidden_from_someone_without_the_right(): void
    {
        $this->staleRegister();
        app(AlertService::class)->sweep($this->shop());

        $cashier = User::create([
            'tenant_id' => $this->shop()->tenant_id,
            'name' => 'Counter Only',
            'email' => 'bellcashier@example.test',
            'password' => 'counter-password-1',
            'is_admin' => true,
        ]);

        $cashier->givePermissionTo('pos.terminal.view');
        $cashier->shops()->attach($this->shop()->id);

        $this->actingAs($cashier)
            ->get('/admin/alerts/bell')
            ->assertOk()
            ->assertDontSee('has not been closed');

        $this->assertSame(0, Alert::badgeCount($cashier->fresh()));
    }
}
