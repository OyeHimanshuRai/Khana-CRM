<?php

use App\Providers\AppServiceProvider;
use App\Providers\ErpIntegrationServiceProvider;
use App\Providers\SignInServiceProvider;

return [
    AppServiceProvider::class,
    SignInServiceProvider::class,
    ErpIntegrationServiceProvider::class,
];
