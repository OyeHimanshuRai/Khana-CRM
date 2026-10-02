<?php

namespace Tests\Feature;

use App\Jobs\PullMenuFromCrm;
use App\Models\Recipe;
use App\Models\SalesAddon;
use App\Models\SalesItem;
use App\Services\Crm\CrmUnavailable;
use App\Services\MenuSync;
use App\Services\Recipes;
use App\Support\Outlets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Khana\Kit\Contracts\ContractValidator;
use Khana\Kit\Events\Inbox\InboxEvent;
use Khana\Kit\Events\Inbox\Processor;
use Khana\Kit\Events\Inbox\Receiver;
use Khana\Kit\Events\Signature;
use Khana\Kit\Support\Ulid;
use Khana\Kit\Tenancy\Entitlements;
use RuntimeException;
use Tests\Concerns\FakesOtherServices;
use Tests\Concerns\HearsFromCrm;
use Tests\TestCase;

/**
 * The menu, pulled whole from CRM: the first time an outlet holds both
 * products, when somebody presses the button, and (in NightlyChecksTest)
 * every night.
 *
 * CRM is faked at its API (FakesOtherServices), so what is held here is
 * ERP's half: that its copy ends up matching, that it says truthfully what
 * differed, and that CRM is never asked from inside a transaction.
 */
class MenuSyncTest extends TestCase
{
    use FakesOtherServices, HearsFromCrm, RefreshDatabase;

    /** @var array{tenant: string, outlet: string} */
    private array $at;

    private int $accountsSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');

        $this->at = $this->business('Spice Garden', ['erp' => 'active', 'crm' => 'active']);
        $this->fakeOtherServices();
    }

    private function pull(?array $at = null): array
    {
        return app(MenuSync::class)->pull(($at ?? $this->at)['outlet']);
    }

    /** @return array<string, int> name => 1 on the menu, 0 switched off or put away */
    private function dishes(): array
    {
        return SalesItem::allOutlets()->where('outlet_id', $this->at['outlet'])->orderBy('name')
            ->pluck('is_active', 'name')->map(fn ($on) => (int) $on)->all();
    }

    /**
     * An accounts.entitlement.changed, through the real inbox.
     *
     * @param  array{tenant: string, outlet: string}  $at
     */
    private function entitled(array $at, string $product, string $state = 'active'): void
    {
        $event = [
            'id' => Ulid::generate(), 'type' => 'accounts.entitlement.changed', 'version' => 1, 'source' => 'accounts',
            'seq' => ++$this->accountsSeq, 'occurred_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'tenant_id' => $at['tenant'], 'outlet_id' => $at['outlet'],
            'data' => ['product' => $product, 'state' => $state, 'plan' => 'standard', 'limits' => ['users' => 5], 'valid_until' => null],
        ];

        $this->assertSame([], app(ContractValidator::class)->problems($event));

        config(['khana.events.senders.accounts' => 'test-secret-accounts-to-erp']);
        $body = json_encode(['events' => [$event]], JSON_THROW_ON_ERROR);

        app(Receiver::class)->receive('accounts', Signature::sign($body, 'test-secret-accounts-to-erp'), $body);
        app(Processor::class)->run();

        $this->assertSame(InboxEvent::APPLIED, InboxEvent::query()->where('event_id', $event['id'])->value('status'));
    }

    /* ------------------------------------------------- what a pull does */

    public function test_a_pull_takes_in_the_whole_menu_and_says_what_it_added(): void
    {
        $tikka = $this->menuItem('Paneer Tikka', 320, [['Full', 320], ['Half', 180]], ['addons' => [[
            'option_id' => Ulid::generate(), 'modifier_id' => Ulid::generate(), 'modifier_name' => 'Extras',
            'name' => 'Extra cheese', 'price_paise' => 4_000, 'active' => true,
        ]]]);
        $this->crmLists($this->at, $tikka);
        $this->crmLists($this->at, $this->menuItem('Cola', 60, [], ['barcode' => '8901764012273']));
        // Put away in CRM before ERP ever heard of it: not worth making here.
        $this->crmLists($this->at, $this->menuItem('Last Year\'s Special', 500), archived: true);

        $this->assertNull(Outlets::settings($this->at['outlet'])->menu_synced_at);

        $this->assertSame(['added' => 2, 'changed' => 0, 'archived' => 0], $this->pull());

        $this->assertSame(['Cola' => 1, 'Paneer Tikka' => 1], $this->dishes());
        $item = SalesItem::allOutlets()->where('ref', $tikka['item_id'])->sole();
        $this->assertSame([$this->at['tenant'], 'Starters'], [$item->tenant_id, $item->category]);
        $this->assertSame(['Full', 'Half'], $item->sizes()->orderBy('id')->pluck('name')->all());
        $this->assertSame('Extra cheese', SalesAddon::allOutlets()->sole()->name);

        // Taken in through the same door as the event: it waits for a recipe.
        $this->assertSame(['Cola', 'Paneer Tikka', 'Paneer Tikka'], app(Recipes::class)->needsRecipe($this->at['outlet'])->pluck('item.name')->all());
        $this->assertNotNull(Outlets::settings($this->at['outlet'])->menu_synced_at);

        // Nothing has changed in CRM since: an honest pull says so.
        $this->assertSame(['added' => 0, 'changed' => 0, 'archived' => 0], $this->pull());
        $this->assertSame('ERP already had the menu as CRM has it.', MenuSync::describe($this->pull()));
    }

    public function test_a_pull_changes_what_differs_and_puts_away_what_crm_no_longer_sells(): void
    {
        $tikka = $this->menuItem('Paneer Tikka', 320, [['Full', 320], ['Half', 180]]);
        $soup = $this->menuItem('Tomato Soup', 120);
        $lassi = $this->menuItem('Lassi', 90);
        $naan = $this->menuItem('Naan', 40);

        foreach ([$tikka, $soup, $lassi, $naan] as $dish) {
            $this->fromCrm($this->at, 'crm.menu.item_upserted', $dish);
            $this->crmLists($this->at, $dish);
        }

        $this->assertSame(['added' => 0, 'changed' => 0, 'archived' => 0], $this->pull());

        // Since then, in CRM, and with every event lost on the way:
        $tikka['name'] = 'Paneer Tikka (Tandoor)';          // renamed, and Half dropped
        $tikka['sizes'] = [$tikka['sizes'][0]];
        $this->crmLists($this->at, $tikka);
        $this->crmLists($this->at, $soup, archived: true);  // archived
        $this->crmForgets($this->at, $lassi);               // gone altogether
        $naan['price_paise'] = 4_500;                       // a price rise
        $this->crmLists($this->at, $naan);
        $this->crmLists($this->at, $this->menuItem('Kulfi', 110));

        $counts = $this->pull();

        $this->assertSame(['added' => 1, 'changed' => 2, 'archived' => 2], $counts);
        $this->assertSame('Menu brought in line with CRM: 1 dish added, 2 dishes changed, 2 dishes put away.', MenuSync::describe($counts));

        $this->assertSame(['Kulfi' => 1, 'Lassi' => 0, 'Naan' => 1, 'Paneer Tikka (Tandoor)' => 1, 'Tomato Soup' => 0], $this->dishes());
        $this->assertSame(4_500, SalesItem::allOutlets()->where('ref', $naan['item_id'])->value('price_paise'));

        $item = SalesItem::allOutlets()->where('ref', $tikka['item_id'])->sole();
        $this->assertSame(['Full' => 1, 'Half' => 0], $item->sizes()->pluck('is_active', 'name')->map(fn ($on) => (int) $on)->all());

        // Put away, not deleted - and the day it was put away is not moved by the next pull.
        $putAway = fn () => SalesItem::allOutlets()->where('ref', $soup['item_id'])->sole()->archived_at?->toDateTimeString();
        $when = $putAway();
        $this->assertNotNull($when);

        $this->travel(1)->days();
        $this->assertSame(['added' => 0, 'changed' => 0, 'archived' => 0], $this->pull());
        $this->assertSame($when, $putAway());

        // Back on CRM's menu: back on ERP's, recipe and all.
        $this->crmLists($this->at, $soup);
        $this->assertSame(['added' => 0, 'changed' => 1, 'archived' => 0], $this->pull());
        $this->assertSame(1, $this->dishes()['Tomato Soup']);
    }

    public function test_only_what_came_from_crm_is_put_away(): void
    {
        // A dish ERP learnt from a sales file, back when the outlet billed with something else.
        SalesItem::allOutlets()->create(['tenant_id' => $this->at['tenant'], 'outlet_id' => $this->at['outlet'], 'source' => SalesItem::CSV, 'ref' => 'masala dosa', 'name' => 'Masala Dosa']);

        $this->assertSame(['added' => 0, 'changed' => 0, 'archived' => 0], $this->pull());
        $this->assertSame(['Masala Dosa' => 1], $this->dishes());
    }

    public function test_every_dish_is_looked_at_again_after_a_pull(): void
    {
        $paneer = $this->stockItem($this->at['tenant'], 'Paneer', 'g', 400);
        $tikka = $this->menuItem('Paneer Tikka', 320);
        $this->fromCrm($this->at, 'crm.menu.item_upserted', $tikka);
        $this->crmLists($this->at, $tikka);

        // No paneer: writing the recipe tells CRM the dish is sold out.
        $this->recipe($this->at, $tikka, null, [[$paneer, 250, 'g']]);

        // ERP loses track of having said so.
        DB::table('sales_item_states')->delete();
        $mark = $this->outboxMark();

        $this->pull();

        $signals = $this->sent('erp.availability.changed', $mark);
        $this->assertCount(1, $signals);
        $this->assertSame([$tikka['item_id'], false], [$signals[0]->data['item_id'], $signals[0]->data['available']]);
    }

    public function test_crm_is_asked_before_anything_is_written_and_never_inside_a_transaction(): void
    {
        $this->crmLists($this->at, $this->menuItem('Cola', 60));
        $this->pull();

        // 1 is the transaction every test here runs inside; ERP opened none of its own.
        $this->assertSame([1], $this->transactionDepths);

        // CRM down: nothing changes, and the outlet is not marked as synced again.
        $before = Outlets::settings($this->at['outlet'])->menu_synced_at;
        $this->crmDown = true;
        $this->travel(5)->minutes();

        try {
            $this->pull();
            $this->fail('CRM was down and the pull said nothing.');
        } catch (CrmUnavailable) {
            $this->assertSame(['Cola' => 1], $this->dishes());
            $this->assertEquals($before, Outlets::settings($this->at['outlet'])->menu_synced_at);
        }
    }

    public function test_an_outlet_without_crm_has_no_menu_to_pull_and_crm_is_not_asked(): void
    {
        $alone = $this->business('Dosa Corner', ['erp' => 'active']);

        try {
            $this->pull($alone);
            $this->fail('An outlet with no CRM was pulled for.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('both Khana CRM and Khana ERP', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    /* ---------------------------------------------------- the first sync */

    public function test_the_menu_is_pulled_once_when_an_outlet_comes_to_hold_both_products(): void
    {
        $at = $this->business('Dosa Corner', ['erp' => 'active']);
        $this->crmLists($at, $this->menuItem('Masala Dosa', 120));
        $this->crmLists($at, $this->menuItem('Filter Coffee', 30));

        // ERP renewed: still no CRM, so nothing to pull.
        $this->entitled($at, 'erp');
        $this->assertSame([], $this->askedOfCrm());

        // The outlet buys CRM.
        $this->entitled($at, 'crm', 'trial');

        $this->assertTrue(app(Entitlements::class)->isSuite($at['outlet']));
        $this->assertSame(['Filter Coffee', 'Masala Dosa'], SalesItem::allOutlets()->where('outlet_id', $at['outlet'])->orderBy('name')->pluck('name')->all());
        $this->assertCount(1, $this->askedOfCrm());
        $this->assertNotNull(Outlets::settings($at['outlet'])->menu_synced_at);

        // The event was applied and its transaction closed before CRM was asked.
        $this->assertSame([1], $this->transactionDepths);

        // Once: the trial turning into a paid plan pulls nothing more.
        $this->entitled($at, 'crm', 'active');
        $this->assertCount(1, $this->askedOfCrm());
    }

    public function test_the_first_sync_is_a_queued_job_and_only_for_an_outlet_never_synced(): void
    {
        Queue::fake([PullMenuFromCrm::class]);

        $at = $this->business('Dosa Corner', ['erp' => 'active']);

        $this->entitled($at, 'erp');
        Queue::assertNothingPushed();

        $this->entitled($at, 'crm');
        Queue::assertPushed(PullMenuFromCrm::class, fn (PullMenuFromCrm $job) => $job->outletId === $at['outlet']);
        $this->assertSame([], $this->askedOfCrm(), 'Nothing is asked of CRM while the event is being applied.');

        // An outlet whose menu has been pulled before is left to the events.
        $this->pull();
        $this->entitled($this->at, 'crm');
        Queue::assertPushed(PullMenuFromCrm::class, 1);

        // And one that gives CRM up has nothing to pull.
        $this->entitled($at, 'crm', 'ended');
        Queue::assertPushed(PullMenuFromCrm::class, 1);
    }

    public function test_crm_being_down_at_the_first_sync_fails_nothing_and_is_left_for_the_night(): void
    {
        $at = $this->business('Dosa Corner', ['erp' => 'active']);
        $this->crmDown = true;

        // entitled() itself asserts the event was applied, not failed.
        $this->entitled($at, 'crm');

        $this->assertCount(1, $this->askedOfCrm());
        $this->assertNull(Outlets::settings($at['outlet'])->menu_synced_at);
        $this->assertSame(0, InboxEvent::query()->where('status', InboxEvent::FAILED)->count());

        // The job, run again once CRM is back, does the work; run a third time, it does nothing.
        $this->crmDown = false;
        $this->crmLists($at, $this->menuItem('Masala Dosa', 120));

        PullMenuFromCrm::dispatchSync($at['outlet']);
        PullMenuFromCrm::dispatchSync($at['outlet']);

        $this->assertCount(2, $this->askedOfCrm());
        $this->assertSame(1, SalesItem::allOutlets()->where('outlet_id', $at['outlet'])->count());
    }

    /* -------------------------------------------------------- the button */

    public function test_the_button_is_offered_only_where_there_is_a_menu_in_crm_to_pull(): void
    {
        $this->signInAs('store_manager', $this->at);
        $this->get(route('recipes.index'))->assertOk()->assertSee('Pull the menu from CRM')->assertSee(route('recipes.pull-menu'), false);

        // Somebody who may read recipes but not write them.
        $this->signInAs('auditor', $this->at);
        $this->get(route('recipes.index'))->assertOk()->assertDontSee('Pull the menu from CRM');
        $this->post(route('recipes.pull-menu'))->assertForbidden();

        // An outlet that bills with something else.
        $alone = $this->business('Dosa Corner', ['erp' => 'active']);
        $this->signInAs('owner', $alone);
        $this->get(route('recipes.index'))->assertOk()->assertDontSee('Pull the menu from CRM');
        $this->post(route('recipes.pull-menu'))->assertNotFound();

        $this->assertSame([], $this->askedOfCrm());
    }

    public function test_pressing_the_button_pulls_the_menu_and_says_what_changed(): void
    {
        $this->signInAs('store_manager', $this->at);
        $this->crmLists($this->at, $this->menuItem('Paneer Tikka', 320));
        $this->crmLists($this->at, $this->menuItem('Cola', 60));

        $this->post(route('recipes.pull-menu'))
            ->assertRedirect(route('recipes.index'))
            ->assertSessionHas('status', 'Menu brought in line with CRM: 2 dishes added.');

        $this->get(route('recipes.index'))->assertOk()->assertSee('Paneer Tikka')->assertSee('Needs a recipe');

        $this->post(route('recipes.pull-menu'))
            ->assertRedirect(route('recipes.index'))
            ->assertSessionHas('status', 'ERP already had the menu as CRM has it.');

        $this->crmDown = true;

        $this->post(route('recipes.pull-menu'))
            ->assertRedirect(route('recipes.index'))
            ->assertSessionHas('error', 'CRM is not reachable right now.');
    }

    public function test_a_packaged_item_pulled_in_is_linked_to_its_stock_item_by_barcode(): void
    {
        $cola = $this->stockItem($this->at['tenant'], 'Cola 300 ml', 'pc', 28, ['barcode' => '8901764012273']);
        $this->crmLists($this->at, $this->menuItem('Cola', 60, [], ['barcode' => '8901764012273']));

        $this->pull();

        $this->assertCount(0, app(Recipes::class)->needsRecipe($this->at['outlet']));
        $this->assertSame($cola->id, Recipe::allOutlets()->sole()->lines()->sole()->stock_item_id);
    }
}
