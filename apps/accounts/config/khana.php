<?php

/*
| How Accounts talks to the other Khana services. See
| packages/kit/config/khana.php for what each key means; only what differs
| from the kit's defaults is here.
*/

return [

    'service' => env('KHANA_SERVICE', 'accounts'),

    'contracts_path' => env('KHANA_CONTRACTS_PATH', base_path('../../contracts')),

    'events' => [

        'validate' => env('KHANA_EVENTS_VALIDATE'),

        // Businesses, outlets, people, access and entitlements go to both
        // products, which keep read-only copies.
        'subscribers' => [
            'crm' => [
                'url' => env('KHANA_CRM_URL') ? rtrim((string) env('KHANA_CRM_URL'), '/').'/internal/events' : null,
                'secret' => env('KHANA_SECRET_ACCOUNTS_TO_CRM'),
                'types' => ['accounts.*', 'system.*'],
            ],
            'erp' => [
                'url' => env('KHANA_ERP_URL') ? rtrim((string) env('KHANA_ERP_URL'), '/').'/internal/events' : null,
                'secret' => env('KHANA_SECRET_ACCOUNTS_TO_ERP'),
                'types' => ['accounts.*', 'system.*'],
            ],
        ],

        // Accounts is told nothing by the products; it is the source.
        'senders' => [],

        'queue' => env('KHANA_EVENTS_QUEUE'),
    ],

];
