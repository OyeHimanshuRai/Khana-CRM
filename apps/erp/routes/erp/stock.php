<?php

use App\Http\Controllers\Stock\ItemController;
use App\Http\Controllers\Stock\LabelController;
use App\Http\Controllers\Stock\MovementController;
use App\Http\Controllers\Stock\OnHandController;
use App\Http\Controllers\Stock\StoreController;
use App\Http\Controllers\Stock\TransferController;
use Illuminate\Support\Facades\Route;

/*
| The Stock hub: what is on the shelf, what the store holds at all, every
| reason a level changed, what went to and came from the other outlets, and
| the stores themselves.
*/

Route::middleware('can:stock.view')->group(function () {
    Route::get('stock', [OnHandController::class, 'index'])->name('stock.index');
    Route::get('stock/{item}', [OnHandController::class, 'show'])->whereNumber('item')->name('stock.show');

    Route::get('items', [ItemController::class, 'index'])->name('items.index');
    Route::get('movements', MovementController::class)->name('movements.index');

    Route::get('transfers', [TransferController::class, 'index'])->name('transfers.index');
    Route::get('transfers/{transfer}', [TransferController::class, 'show'])->whereNumber('transfer')->name('transfers.show');

    // Batch labels: a page made to be printed, for whoever can see a batch.
    Route::get('labels', LabelController::class)->name('labels.show');
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

/*
| Sending stock away is the store manager's call; taking in what another
| outlet sent is a delivery like any other, so it goes with `receive`.
*/
Route::middleware('can:transfer')->group(function () {
    Route::get('transfers/create', [TransferController::class, 'create'])->name('transfers.create');
    Route::post('transfers', [TransferController::class, 'store'])->name('transfers.store');
    Route::put('transfers/{transfer}/cancel', [TransferController::class, 'cancel'])->whereNumber('transfer')->name('transfers.cancel');
});

Route::put('transfers/{transfer}/receive', [TransferController::class, 'receive'])
    ->whereNumber('transfer')
    ->middleware('can:receive')
    ->name('transfers.receive');
