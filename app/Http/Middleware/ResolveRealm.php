<?php

namespace App\Http\Middleware;

use App\Support\Realm;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puts a request on its side of the app before anything else sees it.
 *
 * /superadmin/* is the Super Admin: its own guard and its own session cookie.
 * Everything else is a restaurant (or the public site): the `web` guard and the
 * normal cookie, with the restaurant's back office served at plain URLs. See
 * App\Support\Realm for the whole picture.
 *
 * Global, and first, so that routing, the session and the guards all agree on
 * which side they are serving. The API and the health check carry no browser
 * session and are left alone.
 */
class ResolveRealm
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Realm::ignores($request->getPathInfo())) {
            return $next($request);
        }

        $realm = Realm::fromPath($request->getPathInfo());

        Realm::enter($realm);

        if ($realm === Realm::CUSTOMER) {
            Realm::alias($request);
        }

        try {
            return $next($request);
        } finally {
            Realm::leave();
        }
    }
}
