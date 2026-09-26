<?php

namespace Khana\Kit\Tests\Unit;

use InvalidArgumentException;
use Khana\Kit\Support\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public static function rupeeStrings(): array
    {
        return [
            ['525', 52500],
            ['525.5', 52550],
            ['525.50', 52550],
            ['0.05', 5],
            ['-3.25', -325],
            ['0', 0],
        ];
    }

    #[DataProvider('rupeeStrings')]
    public function test_rupees_parse_exactly(string $input, int $paise): void
    {
        $this->assertSame($paise, Money::rupees($input)->paise);
    }

    public function test_whole_rupees_are_accepted_as_integers(): void
    {
        $this->assertSame(500, Money::rupees(5)->paise);
    }

    public function test_more_than_two_decimal_places_is_an_error_not_a_rounding(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::rupees('1.234');
    }

    public function test_garbage_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::rupees('12,50');
    }

    public function test_a_unit_price_times_a_quantity_rounds_half_away_from_zero(): void
    {
        $this->assertSame(625, Money::paise(2500)->times('0.2500')->paise);
        $this->assertSame(3, Money::paise(5)->times('0.5')->paise);
        $this->assertSame(-3, Money::paise(-5)->times('0.5')->paise);
        $this->assertSame(50000, Money::paise(25000)->times(2)->paise);
    }

    public function test_a_percentage_rounds_to_the_nearest_paisa(): void
    {
        $this->assertSame(191, Money::paise(3810)->percent('5')->paise);
        $this->assertSame(250, Money::paise(10000)->percent('2.5')->paise);
        $this->assertSame(1800, Money::paise(10000)->percent(18)->paise);
    }

    public function test_allocation_always_adds_back_up_to_the_whole(): void
    {
        $shares = Money::paise(1000)->allocate([1, 1, 1]);

        $this->assertSame([334, 333, 333], array_map(fn (Money $m) => $m->paise, $shares));
    }

    public function test_a_bill_discount_is_spread_by_taxable_value(): void
    {
        $shares = Money::paise(10000)->allocate(['tikka' => Money::paise(50000), 'cola' => Money::paise(3800)]);

        $this->assertSame(9294, $shares['tikka']->paise);
        $this->assertSame(706, $shares['cola']->paise);
    }

    public function test_a_negative_amount_allocates_negative_shares(): void
    {
        $shares = Money::paise(-101)->allocate([1, 1]);

        $this->assertSame(-101, $shares[0]->paise + $shares[1]->paise);
        $this->assertSame(-51, $shares[0]->paise);
    }

    public function test_weights_that_add_up_to_nothing_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::paise(100)->allocate([0, 0]);
    }

    public function test_rounding_to_the_rupee_keeps_the_difference(): void
    {
        [$total, $roundOff] = Money::paise(56490)->roundToRupee();
        $this->assertSame([56500, 10], [$total->paise, $roundOff->paise]);

        [$total, $roundOff] = Money::paise(56449)->roundToRupee();
        $this->assertSame([56400, -49], [$total->paise, $roundOff->paise]);

        [$total, $roundOff] = Money::paise(56450)->roundToRupee();
        $this->assertSame([56500, 50], [$total->paise, $roundOff->paise]);
    }

    public function test_formatting_uses_indian_digit_grouping(): void
    {
        $this->assertSame('₹525.00', Money::paise(52500)->format());
        $this->assertSame('₹1,25,000.00', Money::paise(12500000)->format());
        $this->assertSame('₹12,34,567.89', Money::paise(123456789)->format());
        $this->assertSame('-₹525.00', Money::paise(-52500)->format());
        $this->assertSame('₹0.05', Money::paise(5)->format());
    }

    public function test_it_travels_as_whole_paise(): void
    {
        $this->assertSame('{"total":52500}', json_encode(['total' => Money::paise(52500)]));
        $this->assertSame('525.00', Money::paise(52500)->toRupees());
    }
}
