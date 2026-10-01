<?php

namespace App\Support;

use InvalidArgumentException;
use Khana\Kit\Support\Decimal;

/**
 * Units, quantities and cost rates - the only place the sums are done.
 *
 * Every stock item is held in one of three base units: grams, millilitres or
 * pieces. People buy and write recipes in whatever is natural - a kilo, a
 * litre, a dozen, "a 5 kg bag" - and it is converted to the base unit on the
 * way in.
 *
 * A quantity is stored as a whole number of ten-thousandths of the base unit
 * and a cost rate as ten-thousandths of a paisa per base unit (see the stock
 * tables migration). Both are plain ints everywhere in the code; this class
 * turns them into what a person types and reads.
 */
final class Units
{
    /** Ten-thousandths: the scale of a stored quantity and of a cost rate. */
    public const SCALE = 10_000;

    public const BASE = [
        'g' => 'Weight (grams)',
        'ml' => 'Volume (millilitres)',
        'pc' => 'Count (pieces)',
    ];

    /** code => [label, base unit, how many base units it holds] */
    public const UNITS = [
        'g' => ['g', 'g', 1],
        'kg' => ['kg', 'g', 1000],
        'ml' => ['ml', 'ml', 1],
        'l' => ['litre', 'ml', 1000],
        'pc' => ['pc', 'pc', 1],
        'dozen' => ['dozen', 'pc', 12],
    ];

    /**
     * The units a quantity of this base may be written in.
     *
     * @return array<string, string> code => label
     */
    public static function optionsFor(string $base): array
    {
        $options = [];

        foreach (self::UNITS as $code => [$label, $unitBase]) {
            if ($unitBase === $base) {
                $options[$code] = $label;
            }
        }

        return $options;
    }

    /**
     * What somebody typed, in a unit, as a stored quantity.
     *
     * @param  int|null  $packQty  the stored size of one pack, when $unit is "pack"
     */
    public static function toBase(string|int|float $amount, string $unit, string $base, ?int $packQty = null): int
    {
        $entered = self::parse($amount);

        if ($unit === 'pack') {
            if ($packQty === null || $packQty <= 0) {
                throw new InvalidArgumentException('That pack size is not set up for this item.');
            }

            return Decimal::mulDiv($entered, $packQty, self::SCALE);
        }

        [, $unitBase, $factor] = self::UNITS[$unit] ?? throw new InvalidArgumentException("\"{$unit}\" is not a unit ERP knows.");

        if ($unitBase !== $base) {
            throw new InvalidArgumentException("This item is kept in {$base}, so it cannot be measured in {$unit}.");
        }

        return $entered * $factor;
    }

    /** A typed number, as ten-thousandths. Up to 4 decimal places. */
    public static function parse(string|int|float $amount): int
    {
        if (is_float($amount)) {
            $amount = number_format($amount, 4, '.', '');
        }

        $amount = trim((string) $amount);

        // "1.50000" and "1,250.5" are both things people type.
        $amount = str_replace(',', '', $amount);

        if (preg_match('/^(-?\d+)\.(\d{4})\d*$/', $amount, $m) === 1) {
            $amount = $m[1].'.'.$m[2];
        }

        return Decimal::parse($amount === '' ? '0' : $amount, 4);
    }

    /** A stored quantity as a plain number in its base unit: "250", "0.5". */
    public static function plain(int $qty): string
    {
        $text = Decimal::format($qty, 4);

        return rtrim(rtrim($text, '0'), '.') ?: '0';
    }

    /**
     * A stored quantity the way it is said aloud: 12500 g is "12.5 kg".
     */
    public static function show(int $qty, string $base): string
    {
        $abs = abs($qty);

        [$unit, $divisor] = match (true) {
            $base === 'g' && $abs >= 1000 * self::SCALE => ['kg', 1000],
            $base === 'ml' && $abs >= 1000 * self::SCALE => ['l', 1000],
            default => [$base, 1],
        };

        $value = $divisor === 1 ? $qty : Decimal::mulDiv($qty, 1, $divisor);

        // Three places is as fine as anybody weighs.
        $rounded = Decimal::mulDiv($value, 1, 10) * 10;

        return self::plain($rounded).' '.$unit;
    }

    /* -------------------------------------------------------------- cost */

    /** The worth, in paise, of a quantity at a cost rate. */
    public static function value(int $qty, int $rate): int
    {
        return Decimal::mulDiv($qty, $rate, self::SCALE * self::SCALE);
    }

    /** The cost rate that makes a quantity worth this many paise. */
    public static function rate(int $paise, int $qty): int
    {
        return $qty === 0 ? 0 : Decimal::mulDiv($paise, self::SCALE * self::SCALE, $qty);
    }

    /**
     * A cost rate the way a price is quoted: per kg, per litre or per piece.
     *
     * @return array{paise: int, unit: string}
     */
    public static function quote(int $rate, string $base): array
    {
        [$unit, $factor] = match ($base) {
            'g' => ['kg', 1000],
            'ml' => ['litre', 1000],
            default => ['pc', 1],
        };

        return ['paise' => Decimal::mulDiv($rate, $factor, self::SCALE), 'unit' => $unit];
    }

    /** A price typed per kg / litre / piece, in rupees, as a cost rate. */
    public static function rateFromQuote(string|int|float $rupees, string $base): int
    {
        $paise = Decimal::mulDiv(self::parse($rupees), 100, 1);

        $factor = $base === 'pc' ? 1 : 1000;

        return Decimal::mulDiv($paise, 1, $factor);
    }

    public static function rupees(int $paise): string
    {
        return ($paise < 0 ? '-' : '').number_format(abs($paise) / 100, 2);
    }
}
