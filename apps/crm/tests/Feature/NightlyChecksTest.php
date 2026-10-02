<?php

namespace Tests\Feature;

use App\Models\NightlyCheck;
use App\Models\Shop;
use App\Services\NightlyChecks;
use App\Support\CurrentShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\Concerns\ReadsErp;
use Tests\Concerns\RunsTwoOutlets;
use Tests\Concerns\TalksToErp;
use Tests\TestCase;

/**
 * The nightly check is the net under the event bus: what a dropped
 * erp.availability.changed left out of step, found and put right by morning.
 *
 * This is CRM's half of Gate G4. ERP tells CRM a dish ran out; the event is
 * lost; CRM goes on showing the dish. The nightly check asks ERP for the whole
 * picture (the signals endpoint) and reconciles CRM's own sold-out rows to it,
 * so the dish is off the menu by the time the shop opens - without anyone
 * noticing the gap by hand.
 */
class NightlyChecksTest extends TestCase
{
    use ReadsErp, RefreshDatabase, RunsTwoOutlets, TalksToErp;

    private Shop $riverside;

    private string $outlet;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');
        CurrentShop::forget();

        $main = Shop::query()->orderBy('id')->firstOrFail();
        $this->riverside = $this->anotherOutlet($main)->fresh();
        $this->outlet = $this->linkToAccounts($this->riverside)['outlet'];
    }

    protected function tearDown(): void
    {
        CurrentShop::forget();

        parent::tearDown();
    }

    private function row(): NightlyCheck
    {
        return NightlyCheck::query()
            ->where('outlet_id', $this->outlet)
            ->where('check', NightlyCheck::AVAILABILITY)
            ->sole();
    }

    /** @param  list<array<string, mixed>>  $items  what ERP's signals returns */
    private function erpSignals(array $items): void
    {
        $this->erpAnswers($this->outlet, ['signals' => [
            'outlet_id' => $this->outlet,
            'items' => $items,
            'taken_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        ]]);
    }

    public function test_a_lost_sold_out_event_is_found_and_fixed_by_the_nightly_check(): void
    {
        $dish = $this->dishNamed('Paneer Tikka', 280);

        // ERP has the dish off; CRM never heard - the event was dropped.
        $this->erpSignals([
            ['item_id' => $dish->ulid, 'variant_id' => null, 'available' => false, 'cost_paise' => 8125],
        ]);

        $this->assertTrue($dish->fresh()->isOrderable(null, $this->riverside->id), 'CRM still shows it before the check');

        $this->artisan('crm:nightly', ['outlet' => $this->outlet])->assertExitCode(0);

        $this->assertFalse($dish->fresh()->isOrderable(null, $this->riverside->id), 'and takes it off after');

        $row = $this->row();
        $this->assertSame(NightlyCheck::REPAIRED, $row->status);
        $this->assertSame(1, $row->repaired);
        $this->assertSame($this->outlet, $row->outlet_id);
    }

    public function test_a_lost_restock_event_is_also_put_right(): void
    {
        $dish = $this->dishNamed('Dal Makhani', 220);

        // CRM has it off from an earlier event; ERP says it is back, and the
        // "back" event is the one that was lost.
        $this->runsOutAt($this->riverside, $dish);
        $this->assertFalse($dish->fresh()->isOrderable(null, $this->riverside->id));

        $this->erpSignals([
            ['item_id' => $dish->ulid, 'variant_id' => null, 'available' => true, 'cost_paise' => 6000],
        ]);

        $this->artisan('crm:nightly', ['outlet' => $this->outlet])->assertExitCode(0);

        $this->assertTrue($dish->fresh()->isOrderable(null, $this->riverside->id));
        $this->assertSame(NightlyCheck::REPAIRED, $this->row()->status);
    }

    public function test_when_nothing_is_out_of_step_the_night_is_quiet(): void
    {
        $dish = $this->dishNamed('Biryani', 300);

        // ERP has it off and CRM already knows: nothing to put right.
        $this->runsOutAt($this->riverside, $dish);
        $this->erpSignals([
            ['item_id' => $dish->ulid, 'variant_id' => null, 'available' => false, 'cost_paise' => 9000],
        ]);

        $this->artisan('crm:nightly', ['outlet' => $this->outlet])->assertExitCode(0);

        $this->assertSame(NightlyCheck::OK, $this->row()->status);
        $this->assertSame(0, $this->row()->repaired);
    }

    public function test_with_erp_unreachable_the_check_is_a_problem_to_try_again(): void
    {
        $this->erpIsDown();

        $this->artisan('crm:nightly', ['outlet' => $this->outlet])->assertExitCode(1);

        $this->assertSame(NightlyCheck::PROBLEM, $this->row()->status);
    }

    public function test_an_outlet_without_erp_is_skipped(): void
    {
        // The command only reaches outlets that hold ERP, so the skipped ending
        // is asked of the service directly: an outlet with CRM alone has no ERP
        // to compare with, and the check says so without calling out.
        config(['khana.urls.erp' => self::ERP_URL]);

        $crmOnly = $this->anotherOutlet($this->riverside, 'Lakeside', 'LAKE')->fresh();
        $outlet = $this->linkToAccounts($crmOnly, ['crm' => 'active'])['outlet'];

        $results = app(NightlyChecks::class)->run($outlet, now()->toDateString());

        $this->assertSame(NightlyCheck::SKIPPED, $results[NightlyCheck::AVAILABILITY]['row']->status);
    }
}
