<?php

use App\Http\Controllers\Sales\ImportController;
use App\Http\Controllers\Sales\SaleController;
use Illuminate\Support\Facades\Route;

/*
| The Sales hub: the bills ERP has heard about, and the way in for an
| outlet that bills with something other than Khana CRM.
*/

Route::middleware('can:sales.view')->group(function () {
    Route::get('sales', [SaleController::class, 'index'])->name('sales.index');
    Route::get('sales/{sale}', [SaleController::class, 'show'])->whereNumber('sale')->name('sales.show');
});

Route::middleware('can:sales.import')->group(function () {
    Route::get('sales/import', [ImportController::class, 'create'])->name('sales.import');
    Route::post('sales/import', [ImportController::class, 'upload'])->name('sales.import.upload');
    Route::get('sales/import/{token}', [ImportController::class, 'match'])->where('token', '[A-Za-z0-9]{32}')->name('sales.import.match');
    Route::post('sales/import/{token}', [ImportController::class, 'store'])->where('token', '[A-Za-z0-9]{32}')->name('sales.import.store');
});
