<?php

/*
| The two sides of the app (see App\Support\Realm).
|
| `public_segments` is every first path segment that a PUBLIC route starts with
| - the site, the sign-in and sign-up pages, the storefronts, the webhooks. A
| restaurant's back office lives at plain URLs (/orders, /dashboard), so a
| request whose first segment is on this list is checked against the real
| routes before it is treated as a back-office page; one that is not on it is
| one without asking.
|
| tests/Unit/RealmTest compares this list with the registered routes and fails
| when a public route is added without being listed here.
*/

return [
    'public_segments' => [
        '', 'api', 'billing', 'broadcasting', 'clear-all', 'contact-support', 'demo', 'demo-request',
        'email', 'faq', 'features', 'forgot-password', 'geo', 'how-it-works', 'impersonate',
        'login', 'p', 'payments', 'pricing', 'register', 'reset-password', 'sanctum',
        'shop', 'storage', 't', 'up',
    ],
];
