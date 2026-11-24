<?php

return [
    /*
    | Whether a customer must hold a platform-issued license key before using
    | the product. Existing customers are exempt per row (tenants.license_exempt,
    | set by the migration), so switching this on never locks out a restaurant
    | that is already trading. phpunit.xml turns it off for the general suite;
    | the license tests turn it back on.
    */
    'enforce' => (bool) env('LICENSE_ENFORCE', true),

    // First segment of every key: TSW-2026-ABCD-7890-XYZ.
    'prefix' => env('LICENSE_PREFIX', 'TSW'),

    // Days before a license/subscription ends at which customers start seeing a warning.
    'warn_days' => (int) env('LICENSE_WARN_DAYS', 7),

    // Wrong keys allowed per customer per ten minutes before the form locks.
    'max_attempts' => (int) env('LICENSE_MAX_ATTEMPTS', 8),
];
