<?php

namespace Tests\Unit;

use App\Models\Plan;
use App\Support\Modules;
use Tests\TestCase;

/**
 * What a plan says about itself, with nothing else in the room: no database,
 * no subscription, no shop. Everything the rest of the system believes about
 * a plan comes through these few methods.
 */
class PlanTest extends TestCase
{
    private function plan(array $attributes = []): Plan
    {
        return new Plan(['name' => 'Test', 'monthly_price' => 1000, ...$attributes]);
    }

    /* ----------------------------------------------------------- modules */

    public function test_a_plan_that_names_no_modules_grants_none_rather_than_all(): void
    {
        $plan = $this->plan(['modules' => null]);

        $this->assertSame([], $plan->moduleKeys());
        $this->assertFalse($plan->grants('kitchen'));
    }

    public function test_a_plan_stripped_to_nothing_grants_nothing(): void
    {
        $plan = $this->plan(['modules' => []]);

        $this->assertSame([], $plan->moduleKeys());
        $this->assertFalse($plan->grants('pos'));
    }

    public function test_a_plan_grants_exactly_what_it_lists(): void
    {
        $plan = $this->plan(['modules' => ['pos', 'kitchen']]);

        $this->assertTrue($plan->grants('pos'));
        $this->assertTrue($plan->grants('kitchen'));
        $this->assertFalse($plan->grants('dining'));
    }

    public function test_a_module_that_no_longer_exists_grants_nothing(): void
    {
        $plan = $this->plan(['modules' => ['pos', 'teleportation']]);

        $this->assertSame(['pos'], $plan->moduleKeys());
    }

    /* ------------------------------------------------------------- price */

    public function test_each_period_has_its_own_price(): void
    {
        $plan = $this->plan(['monthly_price' => 1000, 'yearly_price' => 10000]);

        $this->assertSame(1000.0, $plan->priceFor(Plan::MONTHLY));
        $this->assertSame(10000.0, $plan->priceFor(Plan::YEARLY));
    }

    public function test_with_no_yearly_price_a_year_is_twelve_months(): void
    {
        $this->assertSame(12000.0, $this->plan(['yearly_price' => null])->priceFor(Plan::YEARLY));
    }

    /* ------------------------------------------------------------ limits */

    public function test_limits_read_as_a_person_would_say_them(): void
    {
        $lines = $this->plan([
            'modules' => ['pos', 'kitchen'], 'max_shops' => 3, 'max_users' => 25, 'max_orders_per_month' => 3000,
        ])->limitLines();

        $this->assertSame('Up to 3 outlets', $lines[0]);
        $this->assertSame('25 staff accounts', $lines[1]);
        $this->assertSame('3,000 orders a month', $lines[2]);
        $this->assertSame('2 of '.count(Modules::keys()).' modules', $lines[3]);
    }

    public function test_one_outlet_is_not_up_to_one_outlet(): void
    {
        $this->assertSame('1 outlet', $this->plan(['max_shops' => 1])->limitLines()[0]);
    }

    public function test_empty_limits_are_unlimited_and_no_order_cap_is_not_mentioned(): void
    {
        $lines = $this->plan(['modules' => Modules::keys()])->limitLines();

        $this->assertSame(['Unlimited outlets', 'Unlimited staff accounts', 'Every module'], $lines);
    }
}
