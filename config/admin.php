<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Seeded administrator account
    |--------------------------------------------------------------------------
    |
    | Used by database/seeders/AdminUserSeeder.php to create the first admin.
    | Override per environment with ADMIN_EMAIL / ADMIN_PASSWORD rather than
    | editing the seeder.
    |
    */

    'default_email' => env('ADMIN_EMAIL', 'admin@erp.test'),

    'default_password' => env('ADMIN_PASSWORD', 'password'),

];
