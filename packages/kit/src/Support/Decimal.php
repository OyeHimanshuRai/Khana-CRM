<?php

namespace Khana\Kit\Support;

use InvalidArgumentException;

/**
 * Exact decimal arithmetic on scaled integers, shared by Money and Quantity.
 *
 * @internal
 */
final class Decimal
{
    /**
     * "12.5" at scale 2 is 1250. More places than the scale allows are an
     * error rather than a silent rounding: a caller sending 12.345 rupees has
     * a bug worth hearing about.
     */
    public static function parse(string $value, int $scale): int
    {
        $value = trim($value);

        if (preg_match('/^(-)?(\d+)(?:\.(\d+))?$/', $value, $m) !== 1) {
            throw new InvalidArgumentException("\"{$value}\" is not a decimal number.");
        }

        $fraction = $m[3] ?? '';

        if (strlen($fraction) > $scale) {
            throw new InvalidArgumentException("\"{$value}\" has more than {$scale} decimal places.");
        }

        $digits = ltrim($m[2], '0').str_pad($fraction, $scale, '0');
        $digits = $digits === '' ? '0' : ltrim($digits, '0');
        $digits = $digits === '' ? '0' : $digits;

        if (strlen($digits) > 18) {
            throw new InvalidArgumentException("\"{$value}\" is too large.");
        }

        $units = (int) $digits;

        return $m[1] === '-' ? -$units : $units;
    }

    public static function format(int $units, int $scale): string
    {
        $sign = $units < 0 ? '-' : '';
        $abs = (string) abs($units);
        $abs = str_pad($abs, $scale + 1, '0', STR_PAD_LEFT);

        return $sign.substr($abs, 0, -$scale).'.'.substr($abs, -$scale);
    }

    /**
     * $a × $b ÷ $c, rounded half away from zero - the rounding PHP's round()
     * and every invoice so far have used.
     */
    public static function mulDiv(int $a, int $b, int $c): int
    {
        if ($c === 0) {
            throw new InvalidArgumentException('Division by zero.');
        }

        if ($b !== 0 && abs($a) > intdiv(PHP_INT_MAX, abs($b))) {
            throw new InvalidArgumentException('Amount is too large to calculate exactly.');
        }

        $product = $a * $b;
        $negative = ($product < 0) !== ($c < 0);
        $product = abs($product);
        $divisor = abs($c);

        $quotient = intdiv($product, $divisor);

        if (($product % $divisor) * 2 >= $divisor) {
            $quotient++;
        }

        return $negative ? -$quotient : $quotient;
    }
}
