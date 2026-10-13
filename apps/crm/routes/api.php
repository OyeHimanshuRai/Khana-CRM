<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Machine\OutletMenuController;
use App\Http\Controllers\Api\Machine\RoleController;
use App\Http\Controllers\Api\Machine\SaleController;
use App\Http\Controllers\Api\Machine\SalesDigestController;
use App\Http\Controllers\Api\MenuController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\TableController;
use App\Http\Middleware\EnsureShopIsEntitled;
use App\Support\CurrentShop;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The API (§2, §15, §21)
|--------------------------------------------------------------------------
|
| §2 lists an API under Settings & Integrations, and §21 names the two things
| that would consume one: a captain's Android app, and third-party delivery
| aggregators. Those two want almost the same handful of endpoints -
|
|     read the menu          an aggregator syncs it; a captain browses it
|     read the tables        a captain only
|     create an order        both
|     read an order's state  both
|     move an order along    both
|
| - and this is that handful. It is deliberately small. An API built to cover
| everything the admin panel can do would be a second product to maintain,
| and most of it would never be called.
|
| ---------------------------------------------------------------------------
| Abilities are the whole security model
| ---------------------------------------------------------------------------
|
| Every token carries abilities, and every route names the one it needs. An
| aggregator gets `menu:read` and `orders:write` and can do nothing else -
| not read another restaurant's tables, not cancel yesterday's orders.
|
| The shop a token belongs to comes from the user it was minted for, through
| CurrentShop, exactly as it does for a browser session. There is no shop id
| in any URL here, and that is on purpose: an id a caller can edit is an id a
| caller will edit.
|
| ---------------------------------------------------------------------------
| Throttled by token
| ---------------------------------------------------------------------------
|
| An aggregator polling the menu every thirty seconds is normal; one polling
| it every second is a bug on their side that becomes a bill on ours.
|
| ---------------------------------------------------------------------------
| Only for an outlet that has CRM
| ---------------------------------------------------------------------------
|
| A token belongs to a member of staff, so it meets the gate the back office
| does (EnsureShopIsEntitled): an outlet whose CRM has ended - or was never
| bought - answers 403 with a sentence saying so, and a suspended one may be
| read but takes no orders. `:api` makes the refusal JSON however the caller
| asked, and lets an install with no Accounts carry on as before.
|
*/

// App sign-in sits outside the group below: no token yet, and logout must work for any outlet.
Route::middleware('throttle:app-sign-in')->prefix('auth')->group(function () {
    Route::post('exchange', [AuthController::class, 'exchange'])->name('api.auth.exchange');
    Route::post('login', [AuthController::class, 'login'])->name('api.auth.login');
});

Route::post('auth/logout', [AuthController::class, 'logout'])
    ->middleware('auth:sanctum')
    ->name('api.auth.logout');

Route::middleware(['auth:sanctum', 'throttle:api', 'shop.entitled:'.EnsureShopIsEntitled::API])->group(function () {

    /*
    | Who am I. The first call any integrator makes, and the one that tells
    | them whether their token works before they debug anything else.
    */
    Route::get('me', function (Request $request) {
        $user = $request->user();

        return response()->json([
            'name' => $user->name,
            'shop' => CurrentShop::get()?->name,
            'abilities' => $user->currentAccessToken()?->abilities ?? [],
        ]);
    })->name('api.me');

    /* ------------------------------------------------------------- menu */

    Route::middleware('ability:menu:read')->group(function () {
        Route::get('menu', [MenuController::class, 'index'])->name('api.menu.index');
        Route::get('menu/{product}', [MenuController::class, 'show'])
            ->whereNumber('product')
            ->name('api.menu.show');
    });

    /*
    | Sold out, from the other side.
    |
    | Its own ability: an aggregator that can mark a dish unavailable is
    | useful, and one that can do it while only holding read access is a way
    | to close a restaurant's menu from outside.
    */
    Route::put('menu/{product}/availability', [MenuController::class, 'availability'])
        ->whereNumber('product')
        ->middleware('ability:menu:write')
        ->name('api.menu.availability');

    /* ----------------------------------------------------------- tables */

    Route::middleware('ability:tables:read')->group(function () {
        Route::get('tables', [TableController::class, 'index'])->name('api.tables.index');
    });

    /* ----------------------------------------------------------- orders */

    Route::middleware('ability:orders:read')->group(function () {
        Route::get('orders', [OrderController::class, 'index'])->name('api.orders.index');
        Route::get('orders/{order}', [OrderController::class, 'show'])
            ->whereNumber('order')
            ->name('api.orders.show');
    });

    Route::middleware('ability:orders:write')->group(function () {
        Route::post('orders', [OrderController::class, 'store'])->name('api.orders.store');
        Route::put('orders/{order}/status', [OrderController::class, 'status'])
            ->whereNumber('order')
            ->name('api.orders.status');
    });
});

/*
|--------------------------------------------------------------------------
| What the other Khana services may read (contracts/api/crm.yaml)
|--------------------------------------------------------------------------
|
| A different caller from everything above: not a person's Sanctum token
| but another service's machine token, signed by Accounts and checked here
| with Accounts' public key (the kit's `machine:` middleware). No session, no
| throttle - a server calls these, and only for a screen, a first sync or
| the nightly check. Everything live travels as events instead.
|
|   outlets/{id}/menu           ERP's first sync of an outlet's menu
|   outlets/{id}/sales-digest   ERP's nightly check of one business day
|   sales/{id}                  one sale the nightly check found missing
|   roles                       Accounts' team screen
|
| Which outlets a caller may read is App\Http\Controllers\Api\Machine\
| ServedOutlets: those that own ERP, and only the one a token is bound to.
*/

$ulid = '[0-7][0-9A-HJKMNP-TV-Z]{25}';

Route::middleware('machine:menu:read')
    ->get('outlets/{id}/menu', OutletMenuController::class)
    ->where('id', $ulid)
    ->name('api.machine.outlet.menu');

Route::middleware('machine:sales:read')->group(function () use ($ulid) {
    Route::get('outlets/{id}/sales-digest', SalesDigestController::class)
        ->where('id', $ulid)
        ->name('api.machine.outlet.sales-digest');

    Route::get('sales/{id}', SaleController::class)
        ->where('id', $ulid)
        ->name('api.machine.sale');
});

Route::middleware('machine:roles:read')
    ->get('roles', RoleController::class)
    ->name('api.machine.roles');
