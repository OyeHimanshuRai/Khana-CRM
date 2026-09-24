<?php

namespace Tests\Feature;

use App\Models\CashRegister;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Payment;
use App\Models\Shop;
use App\Services\CashRegisterService;
use App\Support\CurrentShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Day close: reconciling the till against the payments ledger.
 *
 * "Expected cash" is read from Payment (cash, both directions) and approved
 * cash Expense rows for the shop and the day - never a second bookkeeping
 * of money already recorded elsewhere. What has to hold:
 *
 *   - cash actually taken or handed back moves the expected figure; nothing
 *     else does (not other payment methods, not other shops, not other days)
 *   - only an *approved* cash expense counts; a draft or rejected one has
 *     not actually left the till as far as the record is concerned
 *   - a day can only be opened once, and closing/approving only happens in
 *     order
 */
class CashRegisterServiceTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private CashRegisterService $registers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        CurrentShop::forget();

        $this->shop = Shop::firstOrFail();
        $this->registers = new CashRegisterService();
    }

    protected function tearDown(): void
    {
        CurrentShop::forget();

        parent::tearDown();
    }

    /* ------------------------------------------------------------ fixtures */

    private function payment(string $method, string $direction, float $amount, ?Shop $shop = null): Payment
    {
        $shop ??= $this->shop;

        $payment = new Payment([
            'shop_id' => $shop->id,
            'number' => 'PAY-'.uniqid(),
            'direction' => $direction,
            'method' => $method,
            'party_name' => 'Test Party',
            'amount' => $amount,
            'paid_at' => now(),
            'status' => Payment::CLEARED,
        ]);
        $payment->saveQuietly();

        return $payment;
    }

    private function cashExpense(float $amount, string $status = Expense::APPROVED): Expense
    {
        $category = ExpenseCategory::query()->firstOrCreate(
            ['shop_id' => $this->shop->id, 'name' => 'Till Test Category'],
        );

        $expense = new Expense([
            'shop_id' => $this->shop->id,
            'expense_category_id' => $category->id,
            'reference' => 'EXP-'.uniqid(),
            'spent_on' => today(),
            'title' => 'Test expense',
            'amount' => $amount,
            'method' => Payment::CASH,
            'status' => $status,
        ]);
        $expense->saveQuietly();

        return $expense;
    }

    /* -------------------------------------------------------------- open */

    public function test_opening_a_register_sets_the_float(): void
    {
        $register = $this->registers->open($this->shop, 2000);

        $this->assertSame(CashRegister::OPEN, $register->status);
        $this->assertSame(2000.00, (float) $register->opening_float);
    }

    public function test_a_shop_cannot_open_the_same_day_twice(): void
    {
        $this->registers->open($this->shop, 1000);

        $this->expectException(RuntimeException::class);

        $this->registers->open($this->shop, 1500);
    }

    /* --------------------------------------------------- expected cash */

    public function test_cash_payments_in_increase_the_expected_cash(): void
    {
        $register = $this->registers->open($this->shop, 1000);

        $this->payment(Payment::CASH, Payment::IN, 500);
        $this->payment(Payment::CASH, Payment::IN, 300);

        $this->assertSame(1800.00, $this->registers->expectedCash($register));
    }

    public function test_cash_payments_out_reduce_the_expected_cash(): void
    {
        $register = $this->registers->open($this->shop, 1000);

        $this->payment(Payment::CASH, Payment::IN, 500);
        $this->payment(Payment::CASH, Payment::OUT, 200);

        $this->assertSame(1300.00, $this->registers->expectedCash($register));
    }

    public function test_only_approved_cash_expenses_reduce_the_expected_cash(): void
    {
        $register = $this->registers->open($this->shop, 1000);

        $this->cashExpense(150, Expense::APPROVED);
        $this->cashExpense(999, Expense::DRAFT);
        $this->cashExpense(999, Expense::REJECTED);

        $this->assertSame(850.00, $this->registers->expectedCash($register));
    }

    public function test_non_cash_methods_never_touch_the_expected_cash(): void
    {
        $register = $this->registers->open($this->shop, 1000);

        $this->payment(Payment::UPI, Payment::IN, 5000);
        $this->payment(Payment::CARD, Payment::IN, 5000);

        $this->assertSame(1000.00, $this->registers->expectedCash($register));
    }

    public function test_another_shops_cash_never_leaks_into_this_shops_expected_cash(): void
    {
        $otherShop = Shop::create([
            'name' => 'Other Shop', 'code' => 'OTH', 'slug' => 'other-shop',
            'invoice_prefix' => 'INV', 'pos_prefix' => 'POS',
            'currency' => 'INR', 'timezone' => 'Asia/Kolkata', 'is_active' => true,
        ]);

        $register = $this->registers->open($this->shop, 1000);

        $this->payment(Payment::CASH, Payment::IN, 9999, $otherShop);

        $this->assertSame(1000.00, $this->registers->expectedCash($register));
    }

    /* --------------------------------------------------------------- close */

    public function test_closing_records_a_balanced_till(): void
    {
        $register = $this->registers->open($this->shop, 1000);
        $this->payment(Payment::CASH, Payment::IN, 500);

        $closed = $this->registers->close($register, 1500);

        $this->assertSame(CashRegister::CLOSED, $closed->status);
        $this->assertSame(1500.00, (float) $closed->expected_cash);
        $this->assertSame(1500.00, (float) $closed->counted_cash);
        $this->assertSame(0.00, (float) $closed->variance);
        $this->assertTrue($closed->isBalanced());
    }

    public function test_closing_short_records_a_negative_variance(): void
    {
        $register = $this->registers->open($this->shop, 1000);
        $this->payment(Payment::CASH, Payment::IN, 500);

        $closed = $this->registers->close($register, 1400);

        $this->assertSame(-100.00, (float) $closed->variance);
        $this->assertTrue($closed->isShort());
        $this->assertFalse($closed->isOver());
    }

    public function test_closing_over_records_a_positive_variance(): void
    {
        $register = $this->registers->open($this->shop, 1000);

        $closed = $this->registers->close($register, 1050);

        $this->assertSame(50.00, (float) $closed->variance);
        $this->assertTrue($closed->isOver());
    }

    public function test_a_closed_register_cannot_be_closed_again(): void
    {
        $register = $this->registers->open($this->shop, 1000);
        $this->registers->close($register, 1000);

        $this->expectException(RuntimeException::class);

        $this->registers->close($register->fresh(), 1000);
    }

    /* ------------------------------------------------------------ approve */

    public function test_approving_a_closed_register_signs_it_off(): void
    {
        $register = $this->registers->open($this->shop, 1000);
        $this->registers->close($register, 1000);

        $approved = $this->registers->approve($register->fresh(), 'All good');

        $this->assertSame(CashRegister::APPROVED, $approved->status);
        $this->assertSame('All good', $approved->review_note);
    }

    public function test_an_open_register_cannot_be_approved(): void
    {
        $register = $this->registers->open($this->shop, 1000);

        $this->expectException(RuntimeException::class);

        $this->registers->approve($register);
    }

    public function test_an_already_approved_register_cannot_be_approved_again(): void
    {
        $register = $this->registers->open($this->shop, 1000);
        $this->registers->close($register, 1000);
        $this->registers->approve($register->fresh());

        $this->expectException(RuntimeException::class);

        $this->registers->approve($register->fresh());
    }
}
