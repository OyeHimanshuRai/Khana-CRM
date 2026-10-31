<?php

namespace App\Support;

/**
 * Money, spelled out.
 *
 * A tax invoice is expected to carry the amount in words, and India counts
 * in lakhs and crores rather than in millions - so this cannot be the usual
 * three-digit grouping. Written here rather than pulled in as a dependency
 * because it is thirty lines and the alternative is a package that has to be
 * kept current for one string on one document.
 */
final class Money
{
    /** @var array<int, string> */
    private const ONES = [
        '', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine',
        'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen',
        'Seventeen', 'Eighteen', 'Nineteen',
    ];

    /** @var array<int, string> */
    private const TENS = [
        '', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety',
    ];

    /**
     * "One Thousand Two Hundred and Thirty Four Rupees and Fifty Paise Only".
     */
    public static function inWords(float $amount, string $currency = 'Rupees', string $fraction = 'Paise'): string
    {
        $negative = $amount < 0;
        $amount = abs(round($amount, 2));

        $whole = (int) floor($amount);
        // Rounded rather than truncated: 0.005 is a paisa, not nothing.
        $paise = (int) round(($amount - $whole) * 100);

        // Rounding the fraction can carry into the rupees - 99.999 is 100.
        if ($paise === 100) {
            $whole++;
            $paise = 0;
        }

        $words = $whole === 0 ? 'Zero' : self::indian($whole);

        $out = trim($words).' '.$currency;

        if ($paise > 0) {
            $out .= ' and '.self::indian($paise).' '.$fraction;
        }

        return ($negative ? 'Minus ' : '').$out.' Only';
    }

    /**
     * Group into crore / lakh / thousand / hundred, the Indian way.
     */
    private static function indian(int $number): string
    {
        if ($number < 100) {
            return self::twoDigits($number);
        }

        $parts = [];

        $crore = intdiv($number, 10000000);
        $number %= 10000000;

        $lakh = intdiv($number, 100000);
        $number %= 100000;

        $thousand = intdiv($number, 1000);
        $number %= 1000;

        $hundred = intdiv($number, 100);
        $rest = $number % 100;

        if ($crore > 0) {
            $parts[] = self::indian($crore).' Crore';
        }

        if ($lakh > 0) {
            $parts[] = self::twoDigits($lakh).' Lakh';
        }

        if ($thousand > 0) {
            $parts[] = self::twoDigits($thousand).' Thousand';
        }

        if ($hundred > 0) {
            $parts[] = self::ONES[$hundred].' Hundred';
        }

        if ($rest > 0) {
            // "and" only before the last group, as it is read aloud.
            $parts[] = ($parts === [] ? '' : 'and ').self::twoDigits($rest);
        }

        return implode(' ', $parts);
    }

    private static function twoDigits(int $number): string
    {
        if ($number < 20) {
            return self::ONES[$number];
        }

        $tens = self::TENS[intdiv($number, 10)];
        $ones = self::ONES[$number % 10];

        return $ones === '' ? $tens : $tens.' '.$ones;
    }
}
