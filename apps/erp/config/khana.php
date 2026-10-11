<?php

/*
| How ERP talks to the other Khana services. See packages/kit/config/khana.php
| for what each key means; only what differs from the kit's defaults is here.
*/

return [

    'service' => env('KHANA_SERVICE', 'erp'),

    'contracts_path' => env('KHANA_CONTRACTS_PATH', base_path('../../contracts')),

    'events' => [

        'validate' => env('KHANA_EVENTS_VALIDATE'),

        // Sold-out and cost updates go back to CRM.
        'subscribers' => [
            'crm' => [
                'url' => env('KHANA_CRM_URL') ? rtrim((string) env('KHANA_CRM_URL'), '/').'/internal/events' : null,
                'secret' => env('KHANA_SECRET_ERP_TO_CRM'),
                'types' => ['erp.*', 'system.*'],
                // Only outlets that own CRM - an ERP-only outlet sends nothing.
                'filter' => Khana\Kit\Tenancy\OnlyEntitledOutlets::class,
            ],
        ],

        // Menu, kitchen, sales and paid-outs from CRM; people, outlets and
        // entitlements from Accounts.
        'senders' => [
            'crm' => env('KHANA_SECRET_CRM_TO_ERP'),
            'accounts' => env('KHANA_SECRET_ACCOUNTS_TO_ERP'),
        ],

        'queue' => env('KHANA_EVENTS_QUEUE'),
    ],

];
