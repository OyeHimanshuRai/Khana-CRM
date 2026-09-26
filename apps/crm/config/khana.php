<?php

/*
| How CRM talks to the other Khana services. See packages/kit/config/khana.php
| for what each key means; only what differs from the kit's defaults is here.
*/

return [

    'service' => env('KHANA_SERVICE', 'crm'),

    'contracts_path' => env('KHANA_CONTRACTS_PATH', base_path('../../contracts')),

    'events' => [

        'validate' => env('KHANA_EVENTS_VALIDATE'),

        // Menu, kitchen, sales and cash paid-outs go to ERP.
        'subscribers' => [
            'erp' => [
                'url' => env('KHANA_ERP_URL') ? rtrim((string) env('KHANA_ERP_URL'), '/').'/internal/events' : null,
                'secret' => env('KHANA_SECRET_CRM_TO_ERP'),
                'types' => ['crm.*', 'system.*'],
                // Only outlets that own ERP - a CRM-only outlet sends nothing.
                'filter' => Khana\Kit\Tenancy\OnlyEntitledOutlets::class,
            ],
        ],

        // Sold-out and cost updates come from ERP; people, outlets and
        // entitlements from Accounts.
        'senders' => [
            'erp' => env('KHANA_SECRET_ERP_TO_CRM'),
            'accounts' => env('KHANA_SECRET_ACCOUNTS_TO_CRM'),
        ],

        'queue' => env('KHANA_EVENTS_QUEUE'),
    ],

];
