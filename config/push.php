<?php

/*
|--------------------------------------------------------------------------
| Web push (§16)
|--------------------------------------------------------------------------
|
| Reaches a kitchen tablet whose screen has gone off between rushes. That is
| the whole of what it adds over the beep and the browser notification the
| kitchen display already does with its tab open — see public/assets/js/kds.js.
|
| ---------------------------------------------------------------------------
| Off until somebody generates keys
| ---------------------------------------------------------------------------
|
| Web Push needs a VAPID key pair, which identifies this server to the
| browser vendors' push services. Without one nothing subscribes and nothing
| sends, and every screen behaves exactly as it does today.
|
| Generate a pair with:
|
|     php artisan push:keys
|
| and put them in .env. The public key is sent to browsers by design; the
| private one signs and must not be.
|
*/

return [

    'enabled' => env('PUSH_ENABLED', false),

    'vapid' => [
        /*
        | Who the push service should contact about this application. A
        | mailto: or https: URL, and required by the spec - Firefox rejects a
        | subscription without one.
        */
        'subject' => env('PUSH_SUBJECT', 'mailto:admin@example.com'),

        'public_key' => env('PUSH_PUBLIC_KEY', ''),
        'private_key' => env('PUSH_PRIVATE_KEY', ''),
    ],

    // How long a push service should hold a message for a device that is
    // offline. A kitchen ticket is worthless in an hour, so it is short.
    'ttl' => (int) env('PUSH_TTL', 900),
];
