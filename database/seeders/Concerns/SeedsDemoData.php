<?php

namespace Database\Seeders\Concerns;

use Illuminate\Support\Carbon;

/**
 * Shared plumbing for the demo seeders.
 *
 * Everything random here is drawn from one seeded generator, so the same
 * command twice produces the same shop: a screenshot in a bug report still
 * matches the data a week later, and "it only happens on invoice 14" stays
 * reproducible. Nothing outside these helpers may call rand() directly.
 */
trait SeedsDemoData
{
    /** How many rows each module gets, unless the data says otherwise. */
    public const PER_MODULE = 20;

    /**
     * Fix the sequence so a re-seed reproduces the same shop.
     *
     * Called once per seeder rather than once globally, so running a single
     * seeder on its own still gives that seeder its own stable stream.
     */
    protected function seedRandom(int $salt = 0): void
    {
        mt_srand(20240824 + $salt);
    }

    /** @param array<int, mixed> $options */
    protected function pick(array $options): mixed
    {
        return $options[mt_rand(0, count($options) - 1)];
    }

    /** @param array<string, mixed> $options */
    protected function pickKey(array $options): string
    {
        return (string) $this->pick(array_keys($options));
    }

    protected function between(int $min, int $max): int
    {
        return mt_rand($min, $max);
    }

    /** A rupee amount with two decimals. */
    protected function money(float $min, float $max): float
    {
        return round(mt_rand((int) ($min * 100), (int) ($max * 100)) / 100, 2);
    }

    /** True `$percent` times in a hundred. */
    protected function chance(int $percent): bool
    {
        return mt_rand(1, 100) <= $percent;
    }

    /**
     * A business day within the last `$window` days.
     *
     * Weighted towards recent dates - a dashboard that reads "this month" and
     * "last 7 days" is not worth looking at if the data is all from March.
     */
    protected function recentDate(int $window = 60): Carbon
    {
        $back = $this->chance(55)
            ? $this->between(0, min(13, $window))
            : $this->between(0, $window);

        return Carbon::today()->subDays($back);
    }

    /** A timestamp during trading hours on `$day`. */
    protected function tradingHour(Carbon $day): Carbon
    {
        return $day->copy()->setTime($this->between(9, 19), $this->between(0, 59), $this->between(0, 59));
    }

    protected function say(string $message): void
    {
        $this->command?->info('  '.$message);
    }

    /**
     * Skip a block that has already been seeded.
     *
     * These seeders are re-runnable: a module that already holds at least the
     * target number of rows is left exactly as it is, rather than doubled.
     */
    protected function alreadySeeded(string $label, int $existing, int $target = self::PER_MODULE): bool
    {
        if ($existing < $target) {
            return false;
        }

        $this->say(sprintf('%s: %d already there, skipped.', $label, $existing));

        return true;
    }
}
