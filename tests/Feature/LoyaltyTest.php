<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\LoyaltyProgram;
use App\Models\LoyaltyTransaction;
use App\Models\Shop;
use App\Models\User;
use App\Services\LoyaltyService;
use App\Support\CurrentShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Tests\TestCase;

/**
 * Points a regular earns and spends (§15, §21).
 *
 * Two properties are worth more than everything else here:
 *
 *   1. The balance is the sum of the rows, so no code path can leave a figure
 *      that disagrees with the statement behind it.
 *
 *   2. Earning never fails a sale, and redeeming never silently does nothing.
 *      Those are opposite rules on purpose - one runs after the money is
 *      taken, the other before the total is struck.
 */
class LoyaltyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');

        $this->seed();

        CurrentShop::forget();

        $this->actingAs($this->staff());
        CurrentShop::forget();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CurrentShop::forget();

        parent::tearDown();
    }

    /* ---------------------------------------------------------- fixtures */

    private function service(): LoyaltyService
    {
        return app(LoyaltyService::class);
    }

    private function shop(): Shop
    {
        return Shop::query()->orderBy('id')->firstOrFail();
    }

    /** @param array<int, string> $permissions */
    private function staff(array $permissions = []): User
    {
        $user = User::query()->firstWhere('email', 'crm@example.test');

        if ($user === null) {
            $user = User::create([
                'tenant_id' => $this->shop()->tenant_id,
                'name' => 'Front Desk',
                'email' => 'crm@example.test',
                'password' => 'crm-password-1',
                'is_admin' => true,
            ]);

            $user->shops()->syncWithoutDetaching([$this->shop()->id => ['is_default' => true]]);
            $user->forceFill([
                'current_shop_id' => $this->shop()->id,
                'all_shops_view' => false,
            ])->save();
        }

        $user->givePermissionTo(array_merge(['dashboard.overview.view'], $permissions));

        return $user->fresh();
    }

    /** @param array<string, mixed> $overrides */
    private function program(array $overrides = []): LoyaltyProgram
    {
        return LoyaltyProgram::updateOrCreate(
            ['shop_id' => $this->shop()->id],
            array_merge([
                'is_active' => true,
                'name' => 'Loyalty',
                'points_per_hundred' => 5,
                'min_spend' => 0,
                'redeem_value' => 1,
                'min_redeem_points' => 100,
                'max_redeem_percent' => 50,
            ], $overrides),
        );
    }

    private function customer(string $name = 'Mehta'): Customer
    {
        return Customer::create([
            'shop_id' => $this->shop()->id,
            'code' => 'C-'.uniqid(),
            'name' => $name,
            'mobile' => '98765'.random_int(10000, 99999),
            'is_active' => true,
        ]);
    }

    /* --------------------------------------------------------- earning */

    public function test_points_are_rounded_down_never_up(): void
    {
        $program = $this->program(['points_per_hundred' => 5]);

        // 5% of 1,250 is 62.5. A programme that rounded up would pay out more
        // than it advertised on every single bill, which nobody intends and
        // nobody notices.
        $this->assertSame(62, $program->pointsFor(1250));
        $this->assertSame(0, $program->pointsFor(19));
    }

    public function test_a_bill_below_the_minimum_earns_nothing(): void
    {
        $this->program(['min_spend' => 500]);
        $customer = $this->customer();

        $this->assertNull($this->service()->award($customer, 300));
        $this->assertSame(0, $this->service()->balance($customer));
    }

    public function test_earning_is_silent_when_no_programme_is_running(): void
    {
        $this->program(['is_active' => false]);
        $customer = $this->customer();

        // Null, not an exception. This runs after the money is taken.
        $this->assertNull($this->service()->award($customer, 1000));
    }

    public function test_the_same_bill_cannot_pay_out_twice(): void
    {
        $this->program();
        $customer = $this->customer();
        $source = $this->customer('A source row');

        $this->assertNotNull($this->service()->award($customer, 1000, $source));

        // A retried settle, a webhook arriving twice, a double click.
        $this->assertNull($this->service()->award($customer, 1000, $source));
        $this->assertSame(50, $this->service()->balance($customer));
    }

    public function test_the_balance_is_the_sum_of_the_rows(): void
    {
        $this->program();
        $customer = $this->customer();

        $this->service()->award($customer, 2000);
        $this->service()->adjust($customer, 40, 'goodwill');

        $this->assertSame(140, $this->service()->balance($customer));

        // And the passbook's running total agrees with it.
        $this->assertSame(140, LoyaltyTransaction::query()
            ->where('customer_id', $customer->id)
            ->latest('id')
            ->value('balance_after'));
    }

    /* -------------------------------------------------------- spending */

    public function test_points_cannot_pay_more_than_the_cap_allows(): void
    {
        $this->program(['max_redeem_percent' => 50, 'redeem_value' => 1]);
        $customer = $this->customer();
        $this->service()->adjust($customer, 4000, 'seeded');

        // The rule that stops a loyalty programme becoming a discount scheme.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('at most 50%');

        $this->service()->redeem($customer, 400, billTotal: 500);
    }

    public function test_spending_up_to_the_cap_is_allowed(): void
    {
        $this->program(['max_redeem_percent' => 50, 'redeem_value' => 1]);
        $customer = $this->customer();
        $this->service()->adjust($customer, 4000, 'seeded');

        $this->service()->redeem($customer, 250, billTotal: 500);

        $this->assertSame(3750, $this->service()->balance($customer));
    }

    public function test_spending_more_than_the_balance_is_refused(): void
    {
        $this->program();
        $customer = $this->customer();
        $this->service()->adjust($customer, 120, 'seeded');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not enough');

        $this->service()->redeem($customer, 500, billTotal: 10000);
    }

    public function test_a_balance_below_the_floor_cannot_be_spent(): void
    {
        $this->program(['min_redeem_points' => 100]);
        $customer = $this->customer();
        $this->service()->adjust($customer, 40, 'seeded');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('from 100 upwards');

        $this->service()->redeem($customer, 40, billTotal: 5000);
    }

    public function test_redeeming_throws_rather_than_doing_nothing(): void
    {
        // The opposite rule to earning, and deliberately: this runs before
        // the total is struck, so a redemption that silently did nothing
        // would charge the guest full price for points they thought they had
        // spent.
        $this->program(['is_active' => false]);

        $this->expectException(RuntimeException::class);

        $this->service()->redeem($this->customer(), 100, billTotal: 1000);
    }

    /* ------------------------------------------------------ adjustments */

    public function test_an_adjustment_must_say_why(): void
    {
        $this->program();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Say why');

        $this->service()->adjust($this->customer(), 100, '   ');
    }

    public function test_an_adjustment_cannot_push_a_balance_below_zero(): void
    {
        $this->program();
        $customer = $this->customer();
        $this->service()->adjust($customer, 100, 'seeded');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('below zero');

        $this->service()->adjust($customer, -500, 'oops');
    }

    /* ---------------------------------------------------------- expiry */

    public function test_points_lapse_after_their_time(): void
    {
        $this->program(['expiry_months' => 6]);
        $customer = $this->customer();

        $this->service()->award($customer, 2000);
        $this->assertSame(100, $this->service()->balance($customer));

        Carbon::setTestNow(now()->addMonths(7));

        $this->service()->expire();

        $this->assertSame(0, $this->service()->balance($customer));
        $this->assertSame(
            LoyaltyTransaction::EXPIRE,
            LoyaltyTransaction::query()->latest('id')->firstOrFail()->type,
        );
    }

    public function test_expiry_never_takes_more_than_is_there(): void
    {
        $this->program(['expiry_months' => 6, 'min_redeem_points' => 1, 'max_redeem_percent' => 100]);
        $customer = $this->customer();

        $this->service()->award($customer, 2000);   // 100 points
        $this->service()->redeem($customer, 80, billTotal: 1000);

        Carbon::setTestNow(now()->addMonths(7));

        $this->service()->expire();

        // 100 lapsed but 80 were already spent. Taking the full 100 would
        // quietly confiscate points the customer never had.
        $this->assertSame(0, $this->service()->balance($customer));
    }

    public function test_expiry_does_not_run_twice_on_the_same_points(): void
    {
        $this->program(['expiry_months' => 6]);
        $customer = $this->customer();
        $this->service()->award($customer, 2000);

        Carbon::setTestNow(now()->addMonths(7));

        $this->service()->expire();
        $this->service()->expire();

        $this->assertSame(0, $this->service()->balance($customer));
        $this->assertSame(1, LoyaltyTransaction::query()
            ->where('type', LoyaltyTransaction::EXPIRE)->count());
    }

    public function test_points_never_lapse_when_the_programme_says_never(): void
    {
        $this->program(['expiry_months' => null]);
        $customer = $this->customer();
        $this->service()->award($customer, 2000);

        Carbon::setTestNow(now()->addYears(5));
        $this->service()->expire();

        $this->assertSame(100, $this->service()->balance($customer));
    }

    /* -------------------------------------------------------- the screens */

    public function test_the_screens_open_and_say_plainly_when_nothing_runs(): void
    {
        $this->actingAs($this->staff(['crm.loyalty.view']));
        CurrentShop::forget();

        $this->get(route('admin.loyalty.index'))
            ->assertOk()
            ->assertSee('No loyalty programme is running here');

        $this->get(route('admin.loyalty.settings'))->assertOk();
    }

    public function test_saving_the_programme_says_what_the_rate_means(): void
    {
        $this->actingAs($this->staff(['crm.loyalty.view', 'crm.loyalty.edit']));
        CurrentShop::forget();

        $response = $this->putJson(route('admin.loyalty.save'), [
            'name' => 'Regulars',
            'points_per_hundred' => 5,
            'min_spend' => 0,
            'redeem_value' => 1,
            'min_redeem_points' => 100,
            'max_redeem_percent' => 50,
            'is_active' => 1,
        ]);

        $response->assertOk();

        // "5 points per 100" and "1 rupee a point" are two numbers whose
        // product nobody computes in their head - so the screen does it.
        $this->assertStringContainsString('50 points', $response->json('message'));
        $this->assertStringContainsString('5.0%', $response->json('message'));
    }

    public function test_adjusting_needs_its_own_right(): void
    {
        $this->program();
        $customer = $this->customer();

        // view and edit, but not adjust: the one action that creates value
        // out of nothing.
        $this->actingAs($this->staff(['crm.loyalty.view', 'crm.loyalty.edit']));
        CurrentShop::forget();

        $this->postJson(route('admin.loyalty.adjust', $customer), [
            'points' => 500, 'note' => 'for me',
        ])->assertForbidden();
    }

    public function test_adjusting_over_http_moves_the_balance(): void
    {
        $this->program();
        $customer = $this->customer();

        $this->actingAs($this->staff(['crm.loyalty.view', 'crm.loyalty.adjust']));
        CurrentShop::forget();

        $this->postJson(route('admin.loyalty.adjust', $customer), [
            'points' => 250,
            'note' => 'Goodwill — cold soup',
        ])->assertOk();

        $this->assertSame(250, $this->service()->balance($customer->fresh()));
    }
}
