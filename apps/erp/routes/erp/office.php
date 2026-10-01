<?php

use App\Http\Controllers\ReportController;
use App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

/*
| Reports, and how stock behaves at this outlet.
*/

Route::get('reports/{report}', ReportController::class)
    ->whereIn('report', ReportController::REPORTS)
    ->middleware('can:reports.view')
    ->name('reports.show');

Route::middleware('can:settings.manage')->group(function () {
    Route::get('settings', [SettingsController::class, 'edit'])->name('settings.edit');
    Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');
});
