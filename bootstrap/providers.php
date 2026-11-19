<?php

use App\Providers\AppServiceProvider;
use App\Providers\RealmServiceProvider;

return [
    // First: it settles the session cookie and guard the rest will build on.
    RealmServiceProvider::class,
    AppServiceProvider::class,
];
