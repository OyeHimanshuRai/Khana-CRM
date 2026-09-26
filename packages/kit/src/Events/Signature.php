<?php

namespace Khana\Kit\Events;

/**
 * The signature on every batch of events one service sends another.
 *
 *   X-Khana-Signature: t=<unix time>,v1=<HMAC-SHA256 of "t.body">
 *
 * The time is inside the signed string, so a captured request cannot be
 * replayed later with a fresh timestamp, and a receiver refuses anything
 * outside the tolerance window. Several v1 values may be sent at once, which
 * is how a secret is rotated without a moment where the two sides disagree.
 */
final class Signature
{
    public const HEADER = 'X-Khana-Signature';

    public const SOURCE_HEADER = 'X-Khana-Source';

    public static function sign(string $body, string $secret, ?int $time = null): string
    {
        $time ??= time();

        return 't='.$time.',v1='.hash_hmac('sha256', $time.'.'.$body, $secret);
    }

    public static function verify(?string $header, string $body, string $secret, int $tolerance = 300, ?int $now = null): bool
    {
        if ($header === null || $header === '' || $secret === '') {
            return false;
        }

        $time = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($key === 't' && ctype_digit($value)) {
                $time = (int) $value;
            } elseif ($key === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }

        if ($time === null || $signatures === []) {
            return false;
        }

        if (abs(($now ?? time()) - $time) > $tolerance) {
            return false;
        }

        $expected = hash_hmac('sha256', $time.'.'.$body, $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }
}
