<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * The one-time code that proves a visitor owns an email address BEFORE the
 * sign-up form can be submitted.
 *
 * Stored in the cache (hashed, never the code itself) so nothing touches the
 * database: it lives ten minutes, allows five wrong tries, and a new code can
 * be asked for once a minute. A correct code is remembered in the visitor's
 * own session - and only there - which RegisterController checks.
 */
final class SignupOtp
{
    public const SESSION_KEY = 'signup_otp_verified';

    public const TTL_MINUTES = 10;

    public const MAX_TRIES = 5;

    public const RESEND_SECONDS = 60;

    public static function normalise(string $email): string
    {
        return Str::lower(trim($email));
    }

    /** Seconds until another code may be sent to this address (0 = now). */
    public static function waitSeconds(string $email, string $scope = 'signup'): int
    {
        return RateLimiter::availableIn(self::sendKey($email, $scope));
    }

    /** Makes a fresh code for the address and remembers its hash. Returns the plain code. */
    public static function issue(string $email, string $scope = 'signup'): string
    {
        $email = self::normalise($email);
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        Cache::put(self::cacheKey($email, $scope), ['hash' => self::hash($email, $code), 'tries' => 0], now()->addMinutes(self::TTL_MINUTES));
        RateLimiter::hit(self::sendKey($email, $scope), self::RESEND_SECONDS);

        return $code;
    }

    /** Takes back a code whose email could not be sent, so the person can try at once. */
    public static function forget(string $email, string $scope = 'signup'): void
    {
        $email = self::normalise($email);

        Cache::forget(self::cacheKey($email, $scope));
        RateLimiter::clear(self::sendKey($email, $scope));
    }

    /**
     * @return string 'ok', 'wrong', 'expired' or 'locked'
     */
    public static function check(string $email, string $code, string $scope = 'signup'): string
    {
        $email = self::normalise($email);
        $entry = Cache::get(self::cacheKey($email, $scope));

        if (! is_array($entry)) {
            return 'expired';
        }

        if (($entry['tries'] ?? 0) >= self::MAX_TRIES) {
            Cache::forget(self::cacheKey($email, $scope));

            return 'locked';
        }

        if (hash_equals((string) $entry['hash'], self::hash($email, trim($code)))) {
            Cache::forget(self::cacheKey($email, $scope));

            return 'ok';
        }

        $entry['tries'] = ($entry['tries'] ?? 0) + 1;
        Cache::put(self::cacheKey($email, $scope), $entry, now()->addMinutes(self::TTL_MINUTES));

        return $entry['tries'] >= self::MAX_TRIES ? 'locked' : 'wrong';
    }

    private static function hash(string $email, string $code): string
    {
        return hash_hmac('sha256', $email.'|'.$code, (string) config('app.key'));
    }

    private static function cacheKey(string $email, string $scope = 'signup'): string
    {
        return ($scope === 'signup' ? 'signup-otp:' : 'signup-otp-'.$scope.':').sha1($email);
    }

    private static function sendKey(string $email, string $scope = 'signup'): string
    {
        return ($scope === 'signup' ? 'signup-otp-send:' : 'signup-otp-send-'.$scope.':').sha1($email);
    }
}
