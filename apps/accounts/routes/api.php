<?php

use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\SnapshotController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The reference API (contracts/api/accounts.yaml)
|--------------------------------------------------------------------------
|
| Two endpoints, and no more. Accounts is a hub, not a second admin panel:
| everything a person does here happens on the web screens, and everything a
| product needs is one of these two calls.
|
|   /me                        the person a browser token belongs to; CRM and
|                              ERP call it right after the sign-in redirect.
|   /tenants/{ulid}/snapshot   the whole of one business, for a first sync and
|                              the nightly check; a machine token, not a
|                              person, presents itself.
|
| Live changes travel as events (the outbox), not as polling. These calls are
| the cold start; the events keep the copy warm.
*/

// A person's browser token, checked by Passport (the `api` guard).
Route::middleware('auth:api')->get('me', MeController::class)->name('api.me');

// A product's machine token, verified locally with Accounts' public key.
Route::middleware('machine:tenants:read')
    ->get('tenants/{ulid}/snapshot', SnapshotController::class)
    ->name('api.tenant.snapshot');
