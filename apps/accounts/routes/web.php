<?php

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\PlanController;
use App\Http\Controllers\Admin\SecurityController;
use App\Http\Controllers\Admin\TenantController;
use App\Http\Controllers\Auth\InvitationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\SignupController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\Owner\BillingController;
use App\Http\Controllers\Owner\BusinessController;
use App\Http\Controllers\Owner\OutletController;
use App\Http\Controllers\Owner\SalesKeyController;
use App\Http\Controllers\Owner\TeamController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Accounts web screens
|--------------------------------------------------------------------------
|
| Signed out: sign in, sign up, accept an invitation, reset a password.
| Signed in: the owner's four screens (business, outlets, team, plan) and
| the platform back office. Passport adds /oauth/* on top of these.
*/

// --- Signed out ---------------------------------------------------------
Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'show'])->name('login');
    Route::post('login', [LoginController::class, 'login']);

    Route::get('signup', [SignupController::class, 'show'])->name('signup');
    Route::post('signup', [SignupController::class, 'register']);

    Route::get('invite/{token}', [InvitationController::class, 'show'])->name('invite.show');
    Route::post('invite/{token}', [InvitationController::class, 'accept'])->name('invite.accept');

    Route::get('forgot', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('forgot', [PasswordResetController::class, 'email'])->name('password.email');
    Route::get('reset/{token}', [PasswordResetController::class, 'show'])->name('password.reset');
    Route::post('reset', [PasswordResetController::class, 'update'])->name('password.update');
});

Route::post('logout', [LoginController::class, 'logout'])->middleware('auth')->name('logout');

// --- Signed in ----------------------------------------------------------
Route::middleware('auth')->group(function () {
    Route::get('/', HomeController::class)->name('home');

    Route::get('profile', [ProfileController::class, 'edit'])->name('profile');
    Route::put('profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('profile/password', [ProfileController::class, 'password'])->name('profile.password');

    // --- Owner: the four business screens ---
    Route::middleware('owner')->group(function () {
        Route::get('business', [BusinessController::class, 'edit'])->name('business.edit');
        Route::put('business', [BusinessController::class, 'update'])->name('business.update');

        Route::get('outlets', [OutletController::class, 'index'])->name('outlets.index');
        Route::get('outlets/new', [OutletController::class, 'create'])->name('outlets.create');
        Route::post('outlets', [OutletController::class, 'store'])->name('outlets.store');
        Route::get('outlets/{outlet}/edit', [OutletController::class, 'edit'])->name('outlets.edit');
        Route::put('outlets/{outlet}', [OutletController::class, 'update'])->name('outlets.update');

        // Keys for other billing software, one outlet at a time.
        Route::post('outlets/{outlet}/keys', [SalesKeyController::class, 'store'])->name('outlets.keys.store');
        Route::delete('outlets/{outlet}/keys/{key}', [SalesKeyController::class, 'destroy'])->name('outlets.keys.destroy');

        Route::get('team', [TeamController::class, 'index'])->name('team.index');
        Route::get('team/new', [TeamController::class, 'create'])->name('team.create');
        Route::post('team', [TeamController::class, 'store'])->name('team.store');
        Route::get('team/{member}/edit', [TeamController::class, 'edit'])->name('team.edit');
        Route::put('team/{member}', [TeamController::class, 'update'])->name('team.update');
        Route::delete('team/{member}', [TeamController::class, 'deactivate'])->name('team.deactivate');

        Route::get('billing', [BillingController::class, 'index'])->name('billing.index');
        Route::post('billing/{subscription}/pay', [BillingController::class, 'pay'])->name('billing.pay');
        Route::post('billing/{subscription}/plan', [BillingController::class, 'changePlan'])->name('billing.plan');
        Route::post('billing/{subscription}/cancel', [BillingController::class, 'cancel'])->name('billing.cancel');
    });

    // --- Platform back office ---
    Route::middleware('platform')->prefix('admin')->name('admin.')->group(function () {
        Route::get('overview', DashboardController::class)->name('dashboard');
        Route::get('businesses', [TenantController::class, 'index'])->name('tenants.index');
        Route::get('businesses/{tenant}', [TenantController::class, 'show'])->name('tenants.show');
        Route::post('businesses/{tenant}/status', [TenantController::class, 'setStatus'])->name('tenants.status');
        Route::post('businesses/{tenant}/subscriptions/{subscription}/extend', [TenantController::class, 'extend'])->name('tenants.extend');

        Route::get('plans', [PlanController::class, 'index'])->name('plans.index');
        Route::get('plans/new', [PlanController::class, 'create'])->name('plans.create');
        Route::post('plans', [PlanController::class, 'store'])->name('plans.store');
        Route::get('plans/{plan}/edit', [PlanController::class, 'edit'])->name('plans.edit');
        Route::put('plans/{plan}', [PlanController::class, 'update'])->name('plans.update');

        Route::get('security', [SecurityController::class, 'index'])->name('security.index');
        Route::post('security/block', [SecurityController::class, 'block'])->name('security.block');
        Route::delete('security/block/{blockedIp}', [SecurityController::class, 'unblock'])->name('security.unblock');
    });
});
