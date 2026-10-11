<?php

namespace Khana\Kit\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Which trading day an instant belongs to, for one outlet.
 *
 * Instants are stored in UTC and stay that way; changing the application's
 * clock would silently reinterpret every row already written. What differs
 * between outlets is where a day starts, which takes two facts about the
 * outlet: its time zone, and its day cutoff.
 *
 * The cutoff is what makes a restaurant's night one day. With a 04:00
 * cutoff, a bill settled at 01:30 on the 26th belongs to the 25th - the
 * evening it was eaten in - which is the day the cash was counted against
 * and the day the owner thinks of it as. A midnight cutoff is the plain
 * calendar day.
 *
 * Every event that carries a business_date is stamped here, by the sender,
 * so two services can never disagree about which day a sale belongs to.
 */
final class BusinessDay
{
    public const MIDNIGHT = '00:00';

    /** The business date an instant belongs to: "2026-09-25". */
    public static function dateOf(CarbonInterface $instant, string $zone, string $cutoff = self::MIDNIGHT): string
    {
        [$hour, $minute] = self::cutoff($cutoff);

        $local = CarbonImmutable::instance($instant)->setTimezone($zone);

        $beforeCutoff = $local->hour < $hour || ($local->hour === $hour && $local->minute < $minute);

        return ($beforeCutoff ? $local->subDay() : $local)->toDateString();
    }

    /** The business date it is now at that outlet. */
    public static function today(string $zone, string $cutoff = self::MIDNIGHT, ?CarbonInterface $now = null): string
    {
        return self::dateOf($now ?? CarbonImmutable::now(), $zone, $cutoff);
    }

    /**
     * The UTC range one business date covers, half-open.
     *
     * Use it as `->where($col, '>=', $from)->where($col, '<', $to)`. Not
     * whereBetween, which would count the last second twice across two
     * adjacent days; and not whereDate, which asks the database to truncate
     * in a zone it does not know.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function window(string $date, string $zone, string $cutoff = self::MIDNIGHT): array
    {
        [$hour, $minute] = self::cutoff($cutoff);

        $start = CarbonImmutable::createFromFormat('!Y-m-d', $date, $zone);

        if ($start === false || $start->toDateString() !== $date) {
            throw new InvalidArgumentException("\"{$date}\" is not a date.");
        }

        $start = $start->setTime($hour, $minute);

        return [
            $start->setTimezone('UTC'),
            $start->addDay()->setTimezone('UTC'),
        ];
    }

    /**
     * The UTC range covering a span of business dates, both ends included as
     * whole days - what a business means by "1st to 31st".
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function range(string $from, string $to, string $zone, string $cutoff = self::MIDNIGHT): array
    {
        [$start] = self::window($from, $zone, $cutoff);
        [, $end] = self::window($to, $zone, $cutoff);

        return [$start, $end];
    }

    /** @return array{0: int, 1: int} */
    private static function cutoff(string $cutoff): array
    {
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $cutoff, $m) !== 1) {
            throw new InvalidArgumentException("\"{$cutoff}\" is not a day cutoff; expected HH:MM.");
        }

        return [(int) $m[1], (int) $m[2]];
    }
}
