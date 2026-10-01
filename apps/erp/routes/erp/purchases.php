<?php

use App\Http\Controllers\Purchases\OrderController;
use App\Http\Controllers\Purchases\ReceiptController;
use App\Http\Controllers\Purchases\SupplierController;
use Illuminate\Support\Facades\Route;

/*
| The Purchases hub: what arrived, what was ordered, and who from.
*/

Route::middleware('can:purchasing.view')->group(function () {
    Route::get('receipts', [ReceiptController::class, 'index'])->name('receipts.index');
    Route::get('receipts/{receipt}', [ReceiptController::class, 'show'])->whereNumber('receipt')->name('receipts.show');

    Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('orders/{order}', [OrderController::class, 'show'])->whereNumber('order')->name('orders.show');

    Route::get('suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
    Route::get('suppliers/{supplier}', [SupplierController::class, 'show'])->whereNumber('supplier')->name('suppliers.show');
});

Route::middleware('can:receive')->group(function () {
    Route::get('receipts/create', [ReceiptController::class, 'create'])->name('receipts.create');
    Route::post('receipts', [ReceiptController::class, 'store'])->name('receipts.store');
});

Route::middleware('can:purchasing.manage')->group(function () {
    Route::get('orders/create', [OrderController::class, 'create'])->name('orders.create');
    Route::post('orders', [OrderController::class, 'store'])->name('orders.store');
    Route::put('orders/{order}/cancel', [OrderController::class, 'cancel'])->whereNumber('order')->name('orders.cancel');

    Route::post('suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
    Route::put('suppliers/{supplier}', [SupplierController::class, 'update'])->whereNumber('supplier')->name('suppliers.update');
    Route::post('suppliers/{supplier}/send-back', [SupplierController::class, 'sendBack'])->whereNumber('supplier')->name('suppliers.send-back');
});
