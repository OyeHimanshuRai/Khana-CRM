<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The date range and filters every report shares.
 *
 * Lifted out because otherwise each report re-implements "what does 'this
 * month' mean", and they drift: one counts today, another does not, and two
 * reports over the same data stop agreeing. Here it is decided once.
 *
 * Ranges are inclusive of both ends and always land on whole days, because
 * that is what a business means by "1st to 31st" - a report that silently
 * stops at midnight on the 31st is missing a day's takings.
 */
final class ReportFilters
{
    /** @var array<string, string> */
    public const PRESETS = [
        'today' => 'Today',
        'yesterday' => 'Yesterday',
        'this_week' => 'This week',
        'last_week' => 'Last week',
        'this_month' => 'This month',
        'last_month' => 'Last month',
        'this_quarter' => 'This quarter',
        'this_year' => 'This financial year',
        'last_year' => 'Last financial year',
        'custom' => 'Custom range',
    ];

    private function __construct(
        public readonly Carbon $from,
        public readonly Carbon $to,
        public readonly string $preset,
    ) {}

    /**
     * Read the range out of the request, defaulting to this month.
     */
    public static function fromRequest(Request $request, string $default = 'this_month'): self
    {
        $preset = $request->string('preset')->toString() ?: $default;

        // An explicit date always wins over a preset - somebody typing a
        // date means it, even if the preset select still says "this month".
        if ($request->filled('from') || $request->filled('to')) {
            $preset = 'custom';
        }

        [$from, $to] = self::resolve($preset, $request);

        return new self($from->startOfDay(), $to->endOfDay(), $preset);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private static function resolve(string $preset, Request $request): array
    {
        $today = today();

        return match ($preset) {
            'today' => [$today->copy(), $today->copy()],
            'yesterday' => [$today->copy()->subDay(), $today->copy()->subDay()],
            'this_week' => [$today->copy()->startOfWeek(), $today->copy()],
            'last_week' => [
                $today->copy()->subWeek()->startOfWeek(),
                $today->copy()->subWeek()->endOfWeek(),
            ],
            'last_month' => [
                $today->copy()->subMonthNoOverflow()->startOfMonth(),
                $today->copy()->subMonthNoOverflow()->endOfMonth(),
            ],
            'this_quarter' => [$today->copy()->firstOfQuarter(), $today->copy()],
            /*
             | India's financial year runs April to March, and a shop asking
             | for "this year" in an accounting context means that, not
             | January to December.
             */
            'this_year' => [self::financialYearStart($today), $today->copy()],
            'last_year' => [
                self::financialYearStart($today)->subYear(),
                self::financialYearStart($today)->subDay(),
            ],
            'custom' => [
                $request->filled('from')
                    ? Carbon::parse($request->string('from')->toString())
                    : $today->copy()->startOfMonth(),
                $request->filled('to')
                    ? Carbon::parse($request->string('to')->toString())
                    : $today->copy(),
            ],
            default => [$today->copy()->startOfMonth(), $today->copy()],
        };
    }

    /** 1 April of whichever financial year the date falls in. */
    private static function financialYearStart(Carbon $date): Carbon
    {
        $year = $date->month >= 4 ? $date->year : $date->year - 1;

        return Carbon::create($year, 4, 1)->startOfDay();
    }

    /* ------------------------------------------------------------ output */

    public function label(): string
    {
        if ($this->from->isSameDay($this->to)) {
            return $this->from->format('d M Y');
        }

        return $this->from->format('d M Y').' – '.$this->to->format('d M Y');
    }

    /** Days covered, inclusive of both ends. */
    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to) + 1;
    }

    /**
     * The same length of time immediately before this range.
     *
     * What "compared with the previous period" means, and worth having in
     * one place: comparing a 31-day month against a 28-day one is the sort
     * of thing that makes a report lie without anybody noticing.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function previousPeriod(): array
    {
        $length = $this->days();

        return [
            $this->from->copy()->subDays($length)->startOfDay(),
            $this->from->copy()->subDay()->endOfDay(),
        ];
    }

    /**
     * Query-string parameters that reproduce this range.
     *
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        return [
            'preset' => $this->preset,
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
        ];
    }

    /** A filename-safe stamp for exports. */
    public function slug(): string
    {
        return $this->from->format('Ymd').'-'.$this->to->format('Ymd');
    }
}
