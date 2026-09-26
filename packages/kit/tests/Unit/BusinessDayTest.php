<?php

namespace Khana\Kit\Tests\Unit;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Khana\Kit\Support\BusinessDay;
use PHPUnit\Framework\TestCase;

class BusinessDayTest extends TestCase
{
    public function test_a_bill_after_midnight_belongs_to_the_evening_before_the_cutoff(): void
    {
        // 01:30 IST on the 26th.
        $instant = CarbonImmutable::parse('2026-09-25T20:00:00Z');

        $this->assertSame('2026-09-25', BusinessDay::dateOf($instant, 'Asia/Kolkata', '04:00'));
        $this->assertSame('2026-09-26', BusinessDay::dateOf($instant, 'Asia/Kolkata'));
    }

    public function test_after_the_cutoff_it_is_the_new_day(): void
    {
        // 05:30 IST on the 26th - which is still the 25th in UTC.
        $instant = CarbonImmutable::parse('2026-09-26T00:00:00Z');

        $this->assertSame('2026-09-26', BusinessDay::dateOf($instant, 'Asia/Kolkata', '04:00'));
    }

    public function test_the_cutoff_minute_itself_starts_the_new_day(): void
    {
        $instant = CarbonImmutable::parse('2026-09-25T22:30:00Z'); // 04:00 IST

        $this->assertSame('2026-09-26', BusinessDay::dateOf($instant, 'Asia/Kolkata', '04:00'));
    }

    public function test_a_window_is_half_open_utc_from_cutoff_to_cutoff(): void
    {
        [$from, $to] = BusinessDay::window('2026-09-26', 'Asia/Kolkata', '04:00');

        $this->assertSame('2026-09-25T22:30:00+00:00', $from->toIso8601String());
        $this->assertSame('2026-09-26T22:30:00+00:00', $to->toIso8601String());

        [$from, $to] = BusinessDay::window('2026-09-26', 'Asia/Kolkata');

        $this->assertSame('2026-09-25T18:30:00+00:00', $from->toIso8601String());
        $this->assertSame('2026-09-26T18:30:00+00:00', $to->toIso8601String());
    }

    public function test_a_range_covers_both_ends_as_whole_days(): void
    {
        [$from, $to] = BusinessDay::range('2026-09-01', '2026-09-30', 'Asia/Kolkata');

        $this->assertSame('2026-08-31T18:30:00+00:00', $from->toIso8601String());
        $this->assertSame('2026-09-30T18:30:00+00:00', $to->toIso8601String());
    }

    public function test_today_is_asked_of_the_outlet(): void
    {
        $now = CarbonImmutable::parse('2026-09-25T19:00:00Z'); // 00:30 IST on the 26th

        $this->assertSame('2026-09-26', BusinessDay::today('Asia/Kolkata', '00:00', $now));
        $this->assertSame('2026-09-25', BusinessDay::today('Asia/Kolkata', '04:00', $now));
        $this->assertSame('2026-09-25', BusinessDay::today('UTC', '00:00', $now));
    }

    public function test_a_malformed_cutoff_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        BusinessDay::today('Asia/Kolkata', '4am');
    }

    public function test_an_impossible_date_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        BusinessDay::window('2026-02-30', 'Asia/Kolkata');
    }
}
