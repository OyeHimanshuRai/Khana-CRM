<?php

/*
|--------------------------------------------------------------------------
| Pushing changes to screens that are already open
|--------------------------------------------------------------------------
|
| The kitchen display and the live-orders board both poll. Polling works,
| survives a restart, needs no extra process and is why those screens shipped
| at all - but it means a ticket can sit for the length of a poll before a
| cook sees it, and on a busy pass that is the difference between a dish
| going out hot and going out warm.
|
| So broadcasting is added *beside* polling rather than instead of it.
|
| ---------------------------------------------------------------------------
| `null` is the default, and it is a working configuration
| ---------------------------------------------------------------------------
|
| Reverb is a long-running process. Plenty of restaurants run this on shared
| hosting where nothing long-running is allowed, and a release that assumed
| otherwise would break the kitchen screen for all of them.
|
| With `null` the events are dispatched and discarded, every screen polls
| exactly as it does today, and nothing is worse than before. Switch
| BROADCAST_CONNECTION to `reverb`, run `php artisan reverb:start`, and the
| same screens go instant - see public/assets/js/realtime.js, which slows its
| own polling to a heartbeat once a socket is live.
|
*/

return [

    'default' => env('BROADCAST_CONNECTION', 'null'),

    'connections' => [

        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => [
                'host' => env('REVERB_HOST'),
                'port' => env('REVERB_PORT', 443),
                'scheme' => env('REVERB_SCHEME', 'https'),
                'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
            ],
            'client_options' => [
                // A kitchen screen must not hang on a socket server that has
                // gone away; it falls back to polling instead.
                'timeout' => 5,
            ],
        ],

        'pusher' => [
            'driver' => 'pusher',
            'key' => env('PUSHER_APP_KEY'),
            'secret' => env('PUSHER_APP_SECRET'),
            'app_id' => env('PUSHER_APP_ID'),
            'options' => [
                'cluster' => env('PUSHER_APP_CLUSTER'),
                'host' => env('PUSHER_HOST') ?: 'api-'.env('PUSHER_APP_CLUSTER', 'mt1').'.pusher.com',
                'port' => env('PUSHER_PORT', 443),
                'scheme' => env('PUSHER_SCHEME', 'https'),
                'useTLS' => env('PUSHER_SCHEME', 'https') === 'https',
            ],
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],
    ],
];
