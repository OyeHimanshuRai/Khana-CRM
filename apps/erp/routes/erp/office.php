<?php

use App\Http\Controllers\ReportController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

/*
| Reports, how stock behaves at this outlet, and the business's own roles.
*/

Route::middleware('can:reports.view')->group(function () {
    Route::get('reports/{report}', ReportController::class)
        ->whereIn('report', ReportController::REPORTS)
        ->name('reports.show');

    Route::get('reports/{report}/export', [ReportController::class, 'export'])
        ->whereIn('report', ReportController::REPORTS)
        ->name('reports.export');
});

Route::middleware('can:settings.manage')->group(function () {
    Route::get('settings', [SettingsController::class, 'edit'])->name('settings.edit');
    Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');
});

Route::middleware('can:roles.manage')->group(function () {
    Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
    Route::get('roles/create', [RoleController::class, 'create'])->name('roles.create');
    Route::post('roles', [RoleController::class, 'store'])->name('roles.store');
    Route::get('roles/{role}/edit', [RoleController::class, 'edit'])->whereNumber('role')->name('roles.edit');
    Route::put('roles/{role}', [RoleController::class, 'update'])->whereNumber('role')->name('roles.update');
    Route::delete('roles/{role}', [RoleController::class, 'destroy'])->whereNumber('role')->name('roles.destroy');
});
