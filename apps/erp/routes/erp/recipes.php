<?php

use App\Http\Controllers\Recipes\DishController;
use App\Http\Controllers\Recipes\PreparedController;
use Illuminate\Support\Facades\Route;

/*
| The Recipes hub: what each dish, add-on and prepared item is made of.
*/

Route::middleware('can:recipes.view')->group(function () {
    Route::get('recipes', [DishController::class, 'index'])->name('recipes.index');
    Route::get('recipes/dish/{item}/{size?}', [DishController::class, 'edit'])->whereNumber(['item', 'size'])->name('recipes.edit');
    Route::get('recipes/addon/{addon}', [DishController::class, 'editAddon'])->whereNumber('addon')->name('recipes.addon');

    Route::get('prepared', [PreparedController::class, 'index'])->name('prepared.index');
    Route::get('prepared/{item}', [PreparedController::class, 'edit'])->whereNumber('item')->name('prepared.edit');
});

Route::middleware('can:recipes.manage')->group(function () {
    // An outlet with Khana CRM too: bring the dish list in line with its menu.
    Route::post('recipes/pull-menu', [DishController::class, 'pullMenu'])->name('recipes.pull-menu');

    Route::put('recipes/dish/{item}/{size?}', [DishController::class, 'update'])->whereNumber(['item', 'size'])->name('recipes.update');
    Route::delete('recipes/dish/{item}/{size?}', [DishController::class, 'destroy'])->whereNumber(['item', 'size'])->name('recipes.destroy');
    Route::put('recipes/addon/{addon}', [DishController::class, 'updateAddon'])->whereNumber('addon')->name('recipes.addon.update');

    Route::put('prepared/{item}', [PreparedController::class, 'update'])->whereNumber('item')->name('prepared.update');
});
