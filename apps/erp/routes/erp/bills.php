<?php

use App\Http\Controllers\Bills\BillController;
use App\Http\Controllers\Bills\ExpenseController;
use App\Http\Controllers\Bills\PaidOutController;
use App\Http\Controllers\Bills\PaymentController;
use Illuminate\Support\Facades\Route;

/*
| The Bills hub: what is owed, what has been paid, what was spent, and the
| cash that left the till in CRM.
*/

Route::middleware('can:bills.view')->group(function () {
    Route::get('bills', [BillController::class, 'index'])->name('bills.index');
    Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
    Route::get('expenses', [ExpenseController::class, 'index'])->name('expenses.index');
    Route::get('paid-outs', [PaidOutController::class, 'index'])->name('paid-outs.index');
});

Route::middleware('can:bills.manage')->group(function () {
    Route::post('payments', [PaymentController::class, 'store'])->name('payments.store');
    Route::post('expenses', [ExpenseController::class, 'store'])->name('expenses.store');
    Route::put('paid-outs/{paidOut}', [PaidOutController::class, 'file'])->whereNumber('paidOut')->name('paid-outs.file');
});
