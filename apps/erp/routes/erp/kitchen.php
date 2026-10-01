<?php

use App\Http\Controllers\StoreRoom\CountController;
use App\Http\Controllers\StoreRoom\IssueController;
use App\Http\Controllers\StoreRoom\ProductionController;
use App\Http\Controllers\StoreRoom\WastageController;
use Illuminate\Support\Facades\Route;

/*
| The Kitchen and Counts hubs: the store-room paperwork of the day.
*/

Route::middleware('can:issue')->group(function () {
    Route::get('issues', [IssueController::class, 'index'])->name('issues.index');
    Route::get('issues/create', [IssueController::class, 'create'])->name('issues.create');
    Route::post('issues', [IssueController::class, 'store'])->name('issues.store');
});

Route::middleware('can:wastage')->group(function () {
    Route::get('wastage', [WastageController::class, 'index'])->name('wastage.index');
    Route::get('wastage/create', [WastageController::class, 'create'])->name('wastage.create');
    Route::post('wastage', [WastageController::class, 'store'])->name('wastage.store');
});

Route::middleware('can:recipes.manage')->group(function () {
    Route::get('production', [ProductionController::class, 'index'])->name('production.index');
    Route::get('production/create', [ProductionController::class, 'create'])->name('production.create');
    Route::post('production', [ProductionController::class, 'store'])->name('production.store');
});

Route::middleware('can:count')->group(function () {
    Route::get('counts', [CountController::class, 'index'])->name('counts.index');
    Route::get('counts/create', [CountController::class, 'create'])->name('counts.create');
    Route::post('counts', [CountController::class, 'store'])->name('counts.store');
    Route::get('counts/{count}', [CountController::class, 'show'])->whereNumber('count')->name('counts.show');
});
