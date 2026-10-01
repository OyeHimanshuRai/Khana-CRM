<?php

use App\Http\Controllers\Api\RoleController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| ERP's API (contracts/api/erp.yaml)
|--------------------------------------------------------------------------
|
| Read by CRM and Accounts, and written to by other billing software, all
| on machine tokens issued by Accounts and checked here against its public
| key (the kit's `machine:<scope>` middleware). Served under /api/v1.
|
*/

Route::middleware('machine:roles:read')
    ->get('roles', RoleController::class)
    ->name('api.roles');
