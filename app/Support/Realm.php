<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Which "side" of the app a request belongs to, and everything that follows
 * from it.
 *
 *   platform  /superadmin/*          the Super Admin. Guard `superadmin`, its
 *                                    own session cookie (only sent to
 *                                    /superadmin/*), signs in at
 *                                    /superadmin/login.
 *   customer  everything else        a restaurant's owner and staff, the
 *                                    public site and the storefronts. Guard
 *                                    `web`, the normal session cookie, signs
 *                                    in at /login. Their back office is at
 *                                    plain URLs: /dashboard, /orders, ...
 *
 * The two sit on different cookies, different guards and different URL
 * spaces, so one browser can be signed in to both at once and signing out of
 * one leaves the other alone.
 *
 * The back office is a single set of routes (registered under /superadmin, so
 * every route name and every permission check is unchanged). A restaurant
 * reaches the same routes at the plain URL: ResolveRealm maps /orders to
 * /superadmin/orders before routing, unless /orders is already a public page
 * of the site, and URL generation drops the prefix again for that side - so
 * route('admin.orders.index') is /orders for a restaurant and /superadmin/orders
 * for the Super Admin, from the same code.
 */
final class Realm
{
    public const PLATFORM = 'platform';

    public const CUSTOMER = 'customer';

    public const PREFIX = 'superadmin';

    private static ?string $current = null;

    /** Outside a request (a test's own route() calls): the side to read URLs for. Tests only. */
    private static ?string $fallback = null;

    private static ?string $customerCookie = null;

    private static ?string $customerPath = null;

    /** @var array<string, true>|null */
    private static ?array $rootSegments = null;

    /* ------------------------------------------------------------ which side */

    public static function fromPath(string $path): string
    {
        $path = trim($path, '/');

        return $path === self::PREFIX || str_starts_with($path, self::PREFIX.'/')
            ? self::PLATFORM
            : self::CUSTOMER;
    }

    /** True for requests that never carry a browser session (the API, health). */
    public static function ignores(string $path): bool
    {
        $path = trim($path, '/');

        return $path === 'up' || $path === 'api' || str_starts_with($path, 'api/');
    }

    public static function current(): ?string
    {
        return self::$current ?? self::$fallback;
    }

    public static function isPlatform(): bool
    {
        return self::current() === self::PLATFORM;
    }

    public static function isCustomer(): bool
    {
        return self::current() === self::CUSTOMER;
    }

    /** For tests: the side outside-a-request code reads URLs for. Never set in the app. */
    public static function useFallback(?string $realm): void
    {
        self::$fallback = $realm;
    }

    /** The guard the current side signs in on; `web` when outside a request. */
    public static function guardName(): string
    {
        return self::isPlatform() ? 'superadmin' : 'web';
    }

    public static function loginRoute(): string
    {
        return route(self::isPlatform() ? 'admin.login' : 'login');
    }

    /* ------------------------------------------------- session + guard setup */

    /**
     * Point the session cookie and the default guard at one side.
     *
     * Called as early as the request is known - from the service provider, so
     * nothing touches the session first - and again from the middleware.
     */
    public static function configure(string $realm): void
    {
        self::$customerCookie ??= (string) config('session.cookie');
        self::$customerPath ??= (string) config('session.path');

        $before = [config('session.cookie'), config('session.path')];

        if ($realm === self::PLATFORM) {
            config([
                'session.cookie' => (string) config('session.platform.cookie'),
                'session.path' => (string) config('session.platform.path'),
                'auth.defaults.guard' => 'superadmin',
            ]);
        } else {
            config([
                'session.cookie' => self::$customerCookie,
                'session.path' => self::$customerPath,
                'auth.defaults.guard' => 'web',
            ]);
        }

        // A store that already exists (only possible when one process serves
        // several requests, as in a test run) is renamed rather than rebuilt:
        // the guards that hold it keep working, and the cookie it is read from
        // and written to is this side's.
        if ($before !== [config('session.cookie'), config('session.path')]
            && app()->resolved('session')
            && app('session')->getDefaultDriver() !== 'null') {
            app('session')->driver()->setName((string) config('session.cookie'));
        }
    }

    public static function enter(string $realm): void
    {
        self::configure($realm);
        Auth::shouldUse($realm === self::PLATFORM ? 'superadmin' : 'web');
        self::$current = $realm;
    }

    public static function leave(): void
    {
        self::$current = null;
    }

    /* ----------------------------------------------------- customer-side URLs */

    /**
     * /superadmin/orders -> /orders, unless the plain path is already something
     * else on the site (/superadmin/login must stay the Super Admin's door).
     */
    public static function stripPrefix(string $path): string
    {
        if (! str_starts_with($path, '/'.self::PREFIX.'/')) {
            return $path;
        }

        $plain = substr($path, strlen(self::PREFIX) + 1);

        return self::isPublicPath($plain, 'GET') ? $path : $plain;
    }

    /** A full URL as a restaurant should see it. */
    public static function customerUrl(string $url): string
    {
        $parts = parse_url($url);

        if (! isset($parts['path']) || ! str_starts_with($parts['path'], '/'.self::PREFIX.'/')) {
            return $url;
        }

        $path = self::stripPrefix($parts['path']);

        return ($parts['scheme'] ?? '') !== ''
            ? ($parts['scheme'].'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '')
                .$path.(isset($parts['query']) ? '?'.$parts['query'] : '').(isset($parts['fragment']) ? '#'.$parts['fragment'] : ''))
            : $path.(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    /**
     * A back-office link as a restaurant's owner should receive it by email.
     * Mail is built outside any request, where route() gives the platform form.
     *
     * @param  array<string, mixed>|mixed  $parameters
     */
    public static function customerRoute(string $name, mixed $parameters = []): string
    {
        return self::customerUrl(route($name, $parameters));
    }

    /**
     * A Super Admin URL, from code running on the restaurant's side (leaving
     * "login as owner" sends the browser back to the platform).
     *
     * @param  array<string, mixed>|mixed  $parameters
     */
    public static function platformRoute(string $name, mixed $parameters = []): string
    {
        $before = self::$current;
        $fallback = self::$fallback;
        self::$current = self::PLATFORM;
        self::$fallback = null;

        try {
            return route($name, $parameters);
        } finally {
            self::$current = $before;
            self::$fallback = $fallback;
        }
    }

    /**
     * Where a visitor was headed, as a URL on the side they signed in to.
     * A shared "intended" value is also what the storefront writes, so only
     * this side's own URLs are honoured.
     */
    public static function intendedFor(string $intended, string $fallback): string
    {
        if ($intended === '') {
            return $fallback;
        }

        $host = parse_url($intended, PHP_URL_HOST);
        $path = (string) (parse_url($intended, PHP_URL_PATH) ?: '/');

        if ($host !== null && $host !== parse_url(url('/'), PHP_URL_HOST)) {
            return $fallback;
        }

        if (self::isPlatform()) {
            return $path === '/'.self::PREFIX || str_starts_with($path, '/'.self::PREFIX.'/') ? $intended : $fallback;
        }

        $url = self::customerUrl($intended);
        $plain = (string) (parse_url($url, PHP_URL_PATH) ?: '/');

        // The site's own pages, the storefronts and the login door itself are
        // not a back-office destination.
        return $plain === '/' || str_starts_with($plain, '/shop/') || self::isPublicPath($plain, 'GET')
            ? $fallback
            : $url;
    }

    /** The path prefix that back-office links carry on this side ('' or '/superadmin'). */
    public static function linkBase(): string
    {
        return self::isPlatform() ? '/'.self::PREFIX : '';
    }

    /* ----------------------------------------------------------- the alias */

    /**
     * A restaurant's request for /orders, as the back-office route it is.
     *
     * Returns the request unchanged for the public site (/pricing, /login,
     * /shop/..., /t/...), for the Super Admin's own prefix and for anything
     * that is not a back-office page at all (those 404 as they always did).
     */
    public static function alias(Request $request): void
    {
        $path = $request->getPathInfo();

        if ($path === '/' || self::fromPath($path) === self::PLATFORM || self::ignores($path)) {
            return;
        }

        $segment = explode('/', ltrim($path, '/'))[0];

        // First segments no public route starts with can be aliased without asking.
        if (isset(self::rootSegments()[$segment]) && self::isPublicPath($path, $request->getMethod())) {
            return;
        }

        $server = $request->server->all();
        $query = isset($server['QUERY_STRING']) && $server['QUERY_STRING'] !== '' ? '?'.$server['QUERY_STRING'] : '';
        $server['REQUEST_URI'] = '/'.self::PREFIX.$path.$query;
        unset($server['PATH_INFO']);

        $request->initialize(
            $request->query->all(),
            $request->request->all(),
            $request->attributes->all(),
            $request->cookies->all(),
            $request->files->all(),
            $server,
            $request->getContent(),
        );
    }

    /** Does the site itself (not the back office) answer this path? */
    private static function isPublicPath(string $path, string $method): bool
    {
        $segment = explode('/', ltrim($path, '/'))[0];

        if (! isset(self::rootSegments()[$segment])) {
            return false;
        }

        try {
            app('router')->getRoutes()->match(Request::create($path, $method));

            return true;
        } catch (MethodNotAllowedHttpException) {
            return true;
        } catch (NotFoundHttpException) {
            return false;
        }
    }

    /**
     * First path segments that some public (non back-office) route starts with.
     *
     * A fixed list rather than a walk over every route on every request; a
     * test compares it with the real routes so it cannot drift.
     *
     * @return array<string, true>
     */
    public static function rootSegments(): array
    {
        return self::$rootSegments ??= array_fill_keys((array) config('realm.public_segments', []), true);
    }

    /** For tests that register routes after the first look. */
    public static function forget(): void
    {
        self::$current = null;
        self::$fallback = null;
        self::$rootSegments = null;
    }
}
