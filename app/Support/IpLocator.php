<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Turns an IP address into an approximate place.
 *
 * Deliberately best-effort. Every failure path - lookup disabled, private
 * address, network error, rate limit, unparseable body - resolves to null,
 * and the screens fall back to showing the bare address. A location is a
 * nice-to-have on a security page; it must never be the reason one fails
 * to load.
 *
 * Lookups happen when a security screen is opened, never during login, so
 * a third-party outage cannot slow anyone signing in.
 *
 * See config/ip_lookup.php - this sends addresses to a third party and can
 * be switched off with IP_LOOKUP=false.
 */
final class IpLocator
{
    private const CACHE_PREFIX = 'ip.location.';

    /** Placeholder cached for addresses the API could not place. */
    private const MISS = '__unknown__';

    /**
     * A readable location, or null when there is nothing useful to show.
     */
    public static function for(?string $ip): ?string
    {
        if (blank($ip) || ! config('ip_lookup.enabled')) {
            return null;
        }

        if (static::isPrivate($ip)) {
            return 'Local network';
        }

        $cached = Cache::get(self::CACHE_PREFIX.$ip);

        if ($cached !== null) {
            return $cached === self::MISS ? null : $cached;
        }

        $location = static::lookup($ip);

        Cache::put(
            self::CACHE_PREFIX.$ip,
            $location ?? self::MISS,
            now()->addDays((int) config('ip_lookup.cache_days', 30)),
        );

        return $location;
    }

    /**
     * Resolve several addresses at once, for a table of them.
     *
     * @param  iterable<int, string|null>  $ips
     * @return array<string, string|null>
     */
    public static function many(iterable $ips): array
    {
        $resolved = [];

        foreach ($ips as $ip) {
            if (blank($ip) || array_key_exists($ip, $resolved)) {
                continue;
            }

            $resolved[$ip] = static::for($ip);
        }

        return $resolved;
    }

    /**
     * Loopback, private and link-local ranges never leave the building, so
     * asking a public API about them wastes a call and leaks nothing useful.
     */
    public static function isPrivate(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return true;
        }

        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }

    private static function lookup(string $ip): ?string
    {
        try {
            $response = Http::timeout((int) config('ip_lookup.timeout', 2))
                ->get(rtrim((string) config('ip_lookup.endpoint'), '/').'/'.$ip, [
                    'fields' => config('ip_lookup.fields'),
                ]);

            if (! $response->successful()) {
                return null;
            }

            $body = $response->json();

            // ip-api answers 200 with status:fail for a reserved or invalid
            // address, so the body has to be checked, not just the code.
            if (! is_array($body) || ($body['status'] ?? null) !== 'success') {
                return null;
            }

            return static::format($body);
        } catch (\Throwable $e) {
            // A geolocation outage is not worth an error; note it and move on.
            Log::info('IP lookup failed', ['ip' => $ip, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * "Jaipur, Rajasthan, India" - dropping whichever parts came back empty.
     *
     * @param  array<string, mixed>  $body
     */
    private static function format(array $body): ?string
    {
        $parts = collect([$body['city'] ?? null, $body['regionName'] ?? null, $body['country'] ?? null])
            ->filter()
            // Some ranges report the city and region under the same name.
            ->unique()
            ->values();

        return $parts->isEmpty() ? null : $parts->implode(', ');
    }

    /**
     * The network operator, when the caller wants it separately.
     */
    public static function isp(?string $ip): ?string
    {
        if (blank($ip) || ! config('ip_lookup.enabled') || static::isPrivate($ip)) {
            return null;
        }

        try {
            $body = Http::timeout((int) config('ip_lookup.timeout', 2))
                ->get(rtrim((string) config('ip_lookup.endpoint'), '/').'/'.$ip, [
                    'fields' => config('ip_lookup.fields'),
                ])
                ->json();

            return is_array($body) && ($body['status'] ?? null) === 'success'
                ? ($body['isp'] ?? null)
                : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
