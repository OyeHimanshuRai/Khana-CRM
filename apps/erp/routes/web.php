<?php

use App\Http\Controllers\HomeController;
use App\Http\Controllers\LoginController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| ERP's screens
|--------------------------------------------------------------------------
|
| Signing in is Khana Accounts' job: the kit registers /sso/login and
| /sso/callback, and App\Auth\AccountsSignIn starts the session here.
|
| Everything past the sign-in page needs a signed-in person whose outlet has
| bought ERP (`entitled:erp`, from the kit - a suspended outlet may read and
| export but not change anything). What a person may do inside is their
| role's abilities (App\Support\Roles), checked with `can:` on each route.
|
| One file per hub of the sidebar (config/menu.php).
|
*/

Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'show'])->name('login');
    Route::post('login/local', [LoginController::class, 'local'])->name('login.local');
});

Route::post('logout', [LoginController::class, 'logout'])->middleware('auth')->name('logout');

Route::middleware(['auth', 'entitled:erp'])->group(function () {
    Route::get('/', HomeController::class)->name('home');

    require __DIR__.'/erp/stock.php';
    require __DIR__.'/erp/purchases.php';
    require __DIR__.'/erp/kitchen.php';
    require __DIR__.'/erp/recipes.php';
    require __DIR__.'/erp/sales.php';
    require __DIR__.'/erp/bills.php';
    require __DIR__.'/erp/office.php';
});
