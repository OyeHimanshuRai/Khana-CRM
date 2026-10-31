<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser-side hardening applied to every response.
 *
 * What is deliberately not here is a Content-Security-Policy. The admin and
 * storefront pages rely on inline scripts and styles throughout, so a policy
 * strict enough to matter would break them, and one loose enough not to break
 * them would only look like protection. Output escaping (e() everywhere,
 * markdown with html stripped) is what stops XSS here.
 *
 * Headers a controller already set are left alone: the email preview sets its
 * own framing rules.
 */
class SecurityHeaders
{
    /** @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $defaults = [
            // Stops a pasted-in upload being re-interpreted as a script.
            'X-Content-Type-Options' => 'nosniff',
            // Nobody else may frame the admin (clickjacking).
            'X-Frame-Options' => 'SAMEORIGIN',
            // Do not leak order and customer URLs to third-party links.
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            // The scanner needs the camera and the shop map needs location.
            // Nothing here uses the microphone, payment sheet or USB.
            'Permissions-Policy' => 'camera=(self), geolocation=(self), microphone=(), usb=(), payment=(self)',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            /*
             | The parts of a Content Security Policy that cost nothing: no
             | plugins, no <base> tag rewriting every relative link to
             | somebody else's server, and nobody outside framing a page
             | (the modern form of X-Frame-Options above). Script and style
             | sources are not restricted yet - the screens still carry
             | inline scripts, and a policy that broke the till would be
             | switched off within the hour.
             */
            'Content-Security-Policy' => "object-src 'none'; base-uri 'self'; frame-ancestors 'self'",
        ];

        // Only over HTTPS: sending it on plain HTTP is ignored, and on a dev
        // box that later gets a certificate it would pin the wrong thing.
        if ($request->isSecure() && app()->isProduction()) {
            $defaults['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        foreach ($defaults as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }
}
