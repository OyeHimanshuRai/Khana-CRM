<?php

namespace Tests\Feature;

use App\Events\Outbound\OutletClock;
use App\Models\CashPaidOut;
use App\Models\CashRegister;
use App\Models\Shop;
use App\Models\User;
use App\Services\CashRegisterService;
use App\Support\CurrentShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Khana\Kit\Support\Ulid;
use Tests\Concerns\ReadsErp;
use Tests\Concerns\TalksToErp;
use Tests\TestCase;

/**
 * Paying cash out of the open drawer, from the register's own page.
 *
 * What has to hold:
 *
 *   - whoever may open the register may pay out of it, and that includes
 *     the Cashier preset
 *   - the expected figure falls by what was paid out, and the list under it
 *     says why
 *   - an outlet linked to ERP is offered ERP's unpaid supplier bills, read
 *     after the page has drawn; a paid-out that names one tells ERP which
 *   - paying out never calls ERP, and works with ERP down
 *   - an outlet with CRM alone sees no bill list, and nothing is asked of
 *     ERP on its behalf
 */
class PaidOutTest extends TestCase
{
    use ReadsErp, RefreshDatabase, TalksToErp;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');
        CurrentShop::forget();

        $this->shop = Shop::query()->orderBy('id')->firstOrFail();
        $this->shop->forceFill(['timezone' => 'Asia/Kolkata'])->saveQuietly();
    }

    protected function tearDown(): void
    {
        CurrentShop::forget();

        parent::tearDown();
    }

    /* ---------------------------------------------------------- fixtures */

    private function user(string $name = 'Till Staff'): User
    {
        static $counter = 0;
        $counter++;

        $user = User::create([
            'name' => $name,
            'email' => "paidout{$counter}@example.test",
            'password' => 'staff-password-1',
            'is_admin' => true,
        ]);

        $user->shops()->attach($this->shop->id, ['is_default' => true]);
        $user->forceFill(['current_shop_id' => $this->shop->id])->save();

        return $user;
    }

    private function staff(array $permissions = ['pos.registers.view', 'pos.registers.create']): User
    {
        $user = $this->user();
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function openRegister(float $float = 1000): CashRegister
    {
        return (new CashRegisterService)->open($this->shop, $float);
    }

    /** One open bill, as contracts/api/erp.yaml shapes it. */
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

    /** The paid-out card alone, so "is not mentioned" is about the card and not the page's header. */
    private function card(string $html): string
    {
        $from = strpos($html, 'Pay cash out of the drawer');
        $this->assertNotFalse($from, 'the paid-out card is not on the page');

        return substr($html, $from, strpos($html, 'Paid out of the drawer', $from) - $from);
    }

    /* ------------------------------------------------------- paying out */

    public function test_a_paid_out_is_recorded_and_the_expected_figure_falls_by_it(): void
    {
        $staff = $this->staff();
        $register = $this->openRegister(1000);

        $this->actingAs($staff)->get(route('admin.registers.show', $register))
            ->assertOk()
            ->assertViewHas('expectedSoFar', 1000.0)
            ->assertSee('Pay cash out of the drawer')
            ->assertSee('No cash has been paid out of the drawer.');

        $this->actingAs($staff)->postJson(route('admin.registers.paid-outs.store', $register), [
            'amount' => '150.50',
            'category' => 'gas',
            'note' => 'Two cylinders',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', '₹150.50 paid out of the drawer.')
            ->assertJsonPath('redirect', route('admin.registers.show', $register));

        $paidOut = CashPaidOut::allShops()->sole();
        $this->assertSame('150.50', $paidOut->amount);
        $this->assertSame('gas', $paidOut->category);
        $this->assertSame('Two cylinders', $paidOut->reason);
        $this->assertSame($staff->id, $paidOut->paid_by);
        $this->assertNull($paidOut->supplier_bill_ulid);

        $this->actingAs($staff)->get(route('admin.registers.show', $register))
            ->assertOk()
            ->assertViewHas('expectedSoFar', 849.5)
            ->assertViewHas('paidOutTotal', 150.5)
            ->assertSee('₹849.50')
            ->assertSeeInOrder(['Paid out of the drawer', 'Gas &amp; fuel', 'Two cylinders', $staff->name, '₹150.50', 'Total paid out', '₹150.50'], false);
    }

    public function test_the_list_reads_the_time_on_the_outlets_own_clock(): void
    {
        // 08:35 UTC is five past two in the afternoon in Jaipur.
        Carbon::setTestNow('2026-10-01 08:35:00');

        $staff = $this->staff();
        $register = $this->openRegister();

        $this->actingAs($staff)->postJson(route('admin.registers.paid-outs.store', $register), [
            'amount' => 80, 'category' => 'transport', 'note' => 'Auto to the market',
        ])->assertOk();

        $this->actingAs($staff)->get(route('admin.registers.show', $register))
            ->assertOk()
            ->assertSee('14:05')
            ->assertDontSee('08:35');
    }

    public function test_a_paid_out_tells_erp_with_no_bill_named(): void
    {
        $ids = $this->linkToAccounts($this->shop);
        $this->erpWouldAnswerAnything();

        $register = $this->openRegister();
        $mark = $this->outboxMark();

        $this->actingAs($this->staff())->postJson(route('admin.registers.paid-outs.store', $register), [
            'amount' => 1850,
            'category' => 'gas',
            'note' => 'Two commercial cylinders',
        ])->assertOk()->assertJsonPath('success', true);

        $paidOut = CashPaidOut::allShops()->sole();
        $event = $this->outboxSince($mark, 'crm.cash.paid_out')->sole();

        $this->assertHonoursContract($event);
        $this->assertSame($ids['outlet'], $event->outlet_id);
        $this->assertSame([
            'paid_out_id' => $paidOut->ulid,
            'business_date' => OutletClock::dateOf($this->shop, $paidOut->paid_at),
            'amount_paise' => 185000,
            'category' => 'gas',
            'supplier_bill_id' => null,
            'note' => 'Two commercial cylinders',
        ], $event->data);

        Http::assertNothingSent();
    }

    public function test_a_paid_out_that_names_a_bill_tells_erp_which_and_never_calls_it(): void
    {
        $ids = $this->linkToAccounts($this->shop);
        $this->erpWouldAnswerAnything();

        $staff = $this->staff();
        $register = $this->openRegister(5000);
        $bill = Ulid::generate();
        $mark = $this->outboxMark();

        // A part payment, and somebody left "Gas" selected: the bill wins.
        $this->actingAs($staff)->postJson(route('admin.registers.paid-outs.store', $register), [
            'amount' => '1000',
            'category' => 'gas',
            'note' => '',
            'supplier_bill_id' => $bill,
            'supplier_name' => 'Fresh Farms',
            'supplier_bill_no' => 'FF/102',
        ])->assertOk()->assertJsonPath('success', true);

        $paidOut = CashPaidOut::allShops()->sole();
        $this->assertSame($bill, $paidOut->supplier_bill_ulid);
        $this->assertSame('Fresh Farms', $paidOut->supplier_name);
        $this->assertSame('FF/102', $paidOut->supplier_bill_no);
        $this->assertSame(CashPaidOut::SUPPLIER_PAYMENT, $paidOut->category);
        $this->assertSame('Fresh Farms, bill FF/102', $paidOut->reason, 'the bill is what the cash was for');

        $event = $this->outboxSince($mark, 'crm.cash.paid_out')->sole();
        $this->assertHonoursContract($event);
        $this->assertSame($ids['outlet'], $event->outlet_id);
        $this->assertSame([
            'paid_out_id' => $paidOut->ulid,
            'business_date' => OutletClock::dateOf($this->shop, $paidOut->paid_at),
            'amount_paise' => 100000,
            'category' => 'supplier_payment',
            'supplier_bill_id' => $bill,
            'note' => 'Fresh Farms, bill FF/102',
        ], $event->data);

        // Saved from what the form carried. ERP was not asked about the bill.
        Http::assertNothingSent();

        $this->actingAs($staff)->get(route('admin.registers.show', $register))
            ->assertOk()
            ->assertViewHas('expectedSoFar', 4000.0)
            ->assertSeeInOrder(['Paid out of the drawer', 'Supplier (stock bought)', 'Fresh Farms, bill FF/102', '₹1,000.00']);
    }

    public function test_a_note_typed_beside_a_bill_is_kept_and_sent(): void
    {
        $this->linkToAccounts($this->shop);
        $this->erpWouldAnswerAnything();
        $register = $this->openRegister();
        $mark = $this->outboxMark();

        $this->actingAs($this->staff())->postJson(route('admin.registers.paid-outs.store', $register), [
            'amount' => 500,
            'category' => 'supplier_payment',
            'note' => 'Half now, the rest on Friday',
            'supplier_bill_id' => Ulid::generate(),
            'supplier_name' => 'City Dairy',
            'supplier_bill_no' => 'CD-9',
        ])->assertOk();

        $paidOut = CashPaidOut::allShops()->sole();
        $this->assertSame('Half now, the rest on Friday', $paidOut->reason);
        $this->assertSame('City Dairy, bill CD-9', $paidOut->billLabel());
        $this->assertSame('Half now, the rest on Friday', $this->outboxSince($mark, 'crm.cash.paid_out')->sole()->data['note']);
    }

    public function test_a_paid_out_needs_an_amount_a_reason_and_a_listed_purpose(): void
    {
        $staff = $this->staff();
        $register = $this->openRegister();
        $mark = $this->outboxMark();
        $post = fn (array $data) => $this->actingAs($staff)->postJson(route('admin.registers.paid-outs.store', $register), $data);

        $post(['amount' => 100, 'category' => 'gas', 'note' => ''])
            ->assertStatus(422)->assertJsonValidationErrors('note');
        $post(['amount' => 0, 'category' => 'gas', 'note' => 'Nothing'])
            ->assertStatus(422)->assertJsonValidationErrors('amount');
        $post(['amount' => 100, 'category' => 'bribes', 'note' => 'No'])
            ->assertStatus(422)->assertJsonValidationErrors('category');
        $post(['amount' => 100, 'category' => 'supplier_payment', 'supplier_bill_id' => 'not-a-bill'])
            ->assertStatus(422)->assertJsonValidationErrors('supplier_bill_id');

        $this->assertSame(0, CashPaidOut::allShops()->count());
        $this->assertCount(0, $this->outboxSince($mark));
    }

    public function test_a_caller_that_names_no_category_still_gets_the_default(): void
    {
        $paidOut = app(CashRegisterService::class)->payOut($this->shop, 40, 'Milk');

        $this->assertSame('petty_cash', $paidOut->category);
        $this->assertSame('Petty Cash', $paidOut->categoryLabel());
    }

    /* ---------------------------------------------------- who may pay out */

    public function test_the_cashier_preset_can_pay_out(): void
    {
        $cashier = $this->user('Asha Cashier');
        $cashier->assignRole('Cashier');

        $this->actingAs($cashier)->postJson(route('admin.registers.store'), ['opening_float' => 2000])
            ->assertOk();
        $register = CashRegister::allShops()->sole();

        $this->actingAs($cashier)->get(route('admin.registers.show', $register))
            ->assertOk()
            ->assertSee('Pay cash out of the drawer');

        $this->actingAs($cashier)->postJson(route('admin.registers.paid-outs.store', $register), [
            'amount' => 120,
            'category' => 'other',
            'note' => 'Flowers for the counter',
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertSame('Asha Cashier', CashPaidOut::allShops()->sole()->paid_by_name);
        $this->assertSame(1880.0, (new CashRegisterService)->expectedCash($register));
    }

    public function test_somebody_who_may_only_look_at_the_register_cannot_pay_out(): void
    {
        $viewer = $this->staff(['pos.registers.view']);
        $register = $this->openRegister();

        $this->actingAs($viewer)->get(route('admin.registers.show', $register))
            ->assertOk()
            ->assertDontSee('Pay cash out of the drawer');

        $this->actingAs($viewer)->postJson(route('admin.registers.paid-outs.store', $register), [
            'amount' => 100, 'category' => 'gas', 'note' => 'No',
        ])->assertForbidden();

        $this->actingAs($viewer)->get(route('admin.registers.supplier-bills', $register))->assertForbidden();

        $this->assertSame(0, CashPaidOut::allShops()->count());
    }

    public function test_cash_is_paid_out_of_todays_open_register_only(): void
    {
        $staff = $this->staff(['pos.registers.view', 'pos.registers.create', 'pos.registers.edit']);
        $post = fn (CashRegister $register) => $this->actingAs($staff)->postJson(
            route('admin.registers.paid-outs.store', $register),
            ['amount' => 100, 'category' => 'gas', 'note' => 'Late'],
        );

        // Left open from yesterday: a paid-out now would come off today's drawer.
        $stale = (new CashRegisterService)->open($this->shop, 500, now('Asia/Kolkata')->subDay());
        $post($stale)->assertStatus(422)->assertJsonPath('success', false);
        $this->actingAs($staff)->get(route('admin.registers.show', $stale))
            ->assertOk()
            ->assertDontSee('Pay cash out of the drawer');

        (new CashRegisterService)->close($stale, 500);
        $post($stale->fresh())->assertStatus(422);

        $this->assertSame(0, CashPaidOut::allShops()->count());
    }

    /* ------------------------------------------------ the bills from ERP */

    public function test_a_linked_outlet_is_offered_the_bill_list_without_the_page_waiting_for_erp(): void
    {
        $this->linkToAccounts($this->shop);
        $this->erpWouldAnswerAnything();

        $staff = $this->staff();
        $register = $this->openRegister();

        $this->actingAs($staff)->get(route('admin.registers.show', $register))
            ->assertOk()
            ->assertSee('data-erp-panel="'.route('admin.registers.supplier-bills', $register).'"', false);

        // The page drew without a word to ERP; the list is a second request.
        Http::assertNothingSent();
    }

    public function test_the_bill_list_is_erps_open_bills_in_erps_order(): void
    {
        $ids = $this->linkToAccounts($this->shop);
        $first = $this->bill();
        $second = $this->bill(['supplier_name' => 'City Dairy & Sons', 'bill_no' => 'CD-9', 'due_on' => null, 'due_paise' => 40000]);
        $this->erpAnswers($ids['outlet'], ['supplier-bills' => ['bills' => [$first, $second]]]);

        $register = $this->openRegister();

        $this->actingAs($this->staff())->get(route('admin.registers.supplier-bills', $register))
            ->assertOk()
            ->assertSee('name="supplier_bill_id"', false)
            ->assertSeeInOrder([
                'value="'.$first['bill_id'].'"', 'data-due="1250.50"', 'data-supplier="Fresh Farms"', 'data-number="FF/102"',
                'Fresh Farms · FF/102', 'due 27 Sep 2026', '₹1,250.50 still due',
                'value="'.$second['bill_id'].'"', 'data-due="400.00"', 'data-supplier="City Dairy &amp; Sons"',
                'City Dairy &amp; Sons · CD-9', 'no due date', '₹400.00 still due',
            ], false);

        Http::assertSent(fn ($request) => $request->url() === $this->erpEndpoint($ids['outlet'], 'supplier-bills').'?status=open');
        $this->assertErpAsked(1);
    }

    public function test_no_unpaid_bills_is_said_plainly(): void
    {
        $ids = $this->linkToAccounts($this->shop);
        $this->erpAnswers($ids['outlet'], ['supplier-bills' => ['bills' => []]]);

        $this->actingAs($this->staff())->get(route('admin.registers.supplier-bills', $this->openRegister()))
            ->assertOk()
            ->assertSee('ERP has no unpaid supplier bills for this outlet.')
            ->assertDontSee('name="supplier_bill_id"', false);
    }

    public function test_with_erp_down_the_list_says_so_and_cash_is_still_paid_out(): void
    {
        $this->linkToAccounts($this->shop);
        $this->erpIsDown();

        $staff = $this->staff();
        $register = $this->openRegister();

        $this->actingAs($staff)->get(route('admin.registers.show', $register))
            ->assertOk()
            ->assertSee('Pay cash out of the drawer');

        $this->actingAs($staff)->get(route('admin.registers.supplier-bills', $register))
            ->assertOk()
            ->assertSee('ERP is not reachable right now')
            ->assertDontSee('name="supplier_bill_id"', false);

        $this->actingAs($staff)->postJson(route('admin.registers.paid-outs.store', $register), [
            'amount' => 900,
            'category' => 'supplier_payment',
            'note' => 'Vegetables, paid at the door',
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertSame('900.00', CashPaidOut::allShops()->sole()->amount);
    }

    /* ------------------------------------------------------- CRM alone */

    public function test_an_outlet_with_crm_alone_has_no_bill_list_and_asks_erp_nothing(): void
    {
        // The seeded shop holds CRM and nothing else.
        $this->erpWouldAnswerAnything();

        $staff = $this->staff();
        $register = $this->openRegister();

        $html = $this->actingAs($staff)->get(route('admin.registers.show', $register))
            ->assertOk()
            ->assertDontSee('data-erp-panel', false)
            ->getContent();

        $card = $this->card($html);
        $this->assertStringNotContainsString('ERP', $card);
        $this->assertStringNotContainsString('Supplier bill', $card);
        $this->assertStringContainsString('What it was for', $card);

        // The list is not there to be asked for either.
        $this->actingAs($staff)->get(route('admin.registers.supplier-bills', $register))->assertNotFound();

        // And a bill is not something this outlet can name.
        $this->actingAs($staff)->postJson(route('admin.registers.paid-outs.store', $register), [
            'amount' => 100,
            'category' => 'supplier_payment',
            'supplier_bill_id' => Ulid::generate(),
            'supplier_name' => 'Fresh Farms',
            'supplier_bill_no' => 'FF/102',
        ])->assertStatus(422)->assertJsonPath('success', false);

        $this->assertSame(0, CashPaidOut::allShops()->count());

        // Paying out itself is CRM's own, and works.
        $this->actingAs($staff)->postJson(route('admin.registers.paid-outs.store', $register), [
            'amount' => 60, 'category' => 'cleaning', 'note' => 'Floor cleaner',
        ])->assertOk()->assertJsonPath('success', true);

        Http::assertNothingSent();
    }
}
