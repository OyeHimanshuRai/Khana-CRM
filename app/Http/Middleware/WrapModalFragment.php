<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * The create / edit / show screens are small HTML fragments that the admin
 * opens inside a dialog over the list page. Typed into the address bar, or
 * opened from a link in an email, the same fragment arrives with no layout
 * around it and no stylesheet - a raw, unstyled page.
 *
 * A plain browser visit (no AJAX header) to a GET that answered with a bare
 * fragment is therefore put inside the normal admin page. Dialog requests, JSON,
 * downloads, redirects and every full page are returned untouched.
 */
class WrapModalFragment
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Only a real page visit: a browser navigation says so. Background fetches
        // (live boards, feeds, the dialog itself) never carry this and are left alone.
        if (! $request->isMethod('GET') || $request->ajax() || $request->expectsJson()
            || $request->header('Sec-Fetch-Dest') !== 'document') {
            return $response;
        }

        if ($response->getStatusCode() !== 200 || ! str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            return $response;
        }

        $html = (string) $response->getContent();
        if ($html === '' || preg_match('/<(!doctype|html|head|body)\b/i', $html)) {
            return $response;
        }

        $name = (string) optional($request->route())->getName();
        $parts = explode('.', $name);
        $list = count($parts) >= 3 ? implode('.', array_slice($parts, 0, 2)).'.index' : null;

        return response()->view('admin.fragment-page', [
            'html' => $html,
            'title' => Str::headline($parts[1] ?? 'Details'),
            'backUrl' => $list && Route::has($list) ? route($list) : route('admin.dashboard'),
        ]);
    }
}
