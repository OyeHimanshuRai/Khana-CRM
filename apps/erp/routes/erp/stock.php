<?php

use App\Http\Controllers\Stock\ItemController;
use App\Http\Controllers\Stock\MovementController;
use App\Http\Controllers\Stock\OnHandController;
use App\Http\Controllers\Stock\StoreController;
use Illuminate\Support\Facades\Route;

/*
| The Stock hub: what is on the shelf, what the store holds at all, every
| reason a level changed, and the stores themselves.
*/

Route::middleware('can:stock.view')->group(function () {
    Route::get('stock', [OnHandController::class, 'index'])->name('stock.index');
    Route::get('stock/{item}', [OnHandController::class, 'show'])->whereNumber('item')->name('stock.show');

    Route::get('items', [ItemController::class, 'index'])->name('items.index');
    Route::get('movements', MovementController::class)->name('movements.index');
});

Route::middleware('can:stock.manage')->group(function () {
    Route::get('items/create', [ItemController::class, 'create'])->name('items.create');
    Route::post('items', [ItemController::class, 'store'])->name('items.store');
    Route::get('items/{item}/edit', [ItemController::class, 'edit'])->whereNumber('item')->name('items.edit');
    Route::put('items/{item}', [ItemController::class, 'update'])->whereNumber('item')->name('items.update');

    Route::get('stores', [StoreController::class, 'index'])->name('stores.index');
    Route::post('stores', [StoreController::class, 'store'])->name('stores.store');
    Route::put('stores/{store}', [StoreController::class, 'update'])->whereNumber('store')->name('stores.update');
});
