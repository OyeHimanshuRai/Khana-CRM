<?php

/*
| Defaults for the shared kit. Each app keeps its own config/khana.php naming
| who it sends events to and who it accepts them from; anything it leaves out
| falls back to the values the kit's code reads with a default.
*/

return [

    /*
    | This service's own name: accounts, crm or erp. Stamped as `source` on
    | every event it sends, and the name the others use for it.
    */
    'service' => env('KHANA_SERVICE'),

    /*
    | Where contracts/ lives. Apps sit at apps/<name>/, two levels below the
    | repository root. Read by the contract tests and by record-time
    | validation; a deployed build does not carry the schemas.
    */
    'contracts_path' => env('KHANA_CONTRACTS_PATH', base_path('../../contracts')),

    'events' => [

        /*
        | Check every event against its schema as it is recorded. Null means
        | on in local and testing - where a wrong payload should break the
        | change that produced it - and off elsewhere.
        */
        'validate' => env('KHANA_EVENTS_VALIDATE'),

        /*
        | Who this service sends to, keyed by the receiver's service name:
        |
        |   'erp' => [
        |       'url' => 'https://erp.example/internal/events',
        |       'secret' => env('KHANA_SECRET_CRM_TO_ERP'),
        |       'types' => ['crm.*', 'system.*'],
        |       'filter' => App\Events\OnlyOutletsWithErp::class,  // optional
        |   ],
        */
        'subscribers' => [],

        /*
        | Who this service accepts events from, and the secret each signs
        | with - one secret per sender and receiver pair:
        |
        |   'crm' => env('KHANA_SECRET_CRM_TO_ERP'),
        */
        'senders' => [],

        'batch_size' => 100,

        // A signature older (or newer) than this many seconds is refused.
        'tolerance' => 300,

        'timeout' => 10,

        // Days an event is kept after every subscriber has it, for replays.
        'retention_days' => 90,

        // Queue the delivery and inbox jobs run on; null is the default queue.
        'queue' => env('KHANA_EVENTS_QUEUE'),
    ],

    /*
    | Where each Khana service lives, for links between them: the product
    | switcher, the profile link, sign-in. Empty for a service this install
    | does not run.
    */
    'urls' => [
        'accounts' => env('KHANA_ACCOUNTS_URL'),
        'crm' => env('KHANA_CRM_URL'),
        'erp' => env('KHANA_ERP_URL'),
    ],

    /*
    | The shared layout (khana::layouts.app): the route its logo and "home"
    | links go to, and the POST route that signs out. The sidebar is the
    | app's own config('menu').
    */
    'ui' => [
        'home' => 'home',
        'logout' => 'logout',
        'favicon' => null,
    ],

    /*
    | Sign-in through Accounts, and the tokens services call each other with.
    */
    'sso' => [
        'accounts_url' => env('KHANA_ACCOUNTS_URL'),

        // The public (PKCE) client people sign in through. No secret: a
        // browser flow cannot keep one.
        'client_id' => env('KHANA_SSO_CLIENT_ID'),

        // Defaults to this app's /sso/callback.
        'redirect' => env('KHANA_SSO_REDIRECT'),

        'scopes' => [],

        // The confidential client this service reads other services' APIs as.
        'machine_client_id' => env('KHANA_MACHINE_CLIENT_ID'),
        'machine_client_secret' => env('KHANA_MACHINE_CLIENT_SECRET'),

        // Accounts' public key, to check tokens locally: the PEM itself, or a
        // path to it.
        'public_key' => env('KHANA_ACCOUNTS_PUBLIC_KEY'),

        // Seconds of clock difference tolerated on token times.
        'leeway' => 30,
    ],

];
