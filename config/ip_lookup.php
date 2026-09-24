<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Approximate IP geolocation
    |--------------------------------------------------------------------------
    |
    | Turns an address in the session and login history into a readable
    | "Jaipur, Rajasthan, India".
    |
    | This sends the address to a third party, so it is a deliberate switch
    | rather than an always-on default. Turn it off with IP_LOOKUP=false and
    | the screens simply show the address on its own.
    |
    | ip-api.com's free tier is HTTP only and rate limited to roughly 45
    | requests a minute from one address; results are cached for a month, and
    | lookups only happen when a security screen is actually opened, never on
    | the login path.
    |
    */

    'enabled' => env('IP_LOOKUP', true),

    'endpoint' => env('IP_LOOKUP_ENDPOINT', 'http://ip-api.com/json/'),

    /* Only what is needed for one line of text, so nothing extra is fetched. */
    'fields' => 'status,message,country,regionName,city,isp',

    /* Seconds. Kept short: a slow lookup must never hold up a page. */
    'timeout' => (int) env('IP_LOOKUP_TIMEOUT', 2),

    /* An address rarely moves, so a long cache is both cheap and kind. */
    'cache_days' => (int) env('IP_LOOKUP_CACHE_DAYS', 30),

];
