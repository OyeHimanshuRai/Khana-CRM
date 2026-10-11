<?php

use App\Http\Controllers\Api\FoodCostController;
use App\Http\Controllers\Api\ItemStockController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\SignalsController;
use App\Http\Controllers\Api\SupplierBillController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| ERP's API (contracts/api/erp.yaml)
|--------------------------------------------------------------------------
|
| Read by CRM and Accounts, and written to by other billing software, all
| on machine tokens issued by Accounts and checked here against its public
| key (the kit's `machine:<scope>` middleware). Served under /api/v1.
|
*/

$ulid = '[0-7][0-9A-HJKMNP-TV-Z]{25}';

Route::middleware('machine:roles:read')
    ->get('roles', RoleController::class)
    ->name('api.roles');

// CRM's paid-out screen.
Route::middleware('machine:bills:read')
    ->get('outlets/{id}/supplier-bills', SupplierBillController::class)
    ->where('id', $ulid)
    ->name('api.supplier-bills');

Route::middleware('machine:stock:read')->group(function () use ($ulid) {
    // The stock and cost panel on CRM's menu item page.
    Route::get('outlets/{id}/items/{item}/stock', ItemStockController::class)
        ->where(['id' => $ulid, 'item' => $ulid])
        ->name('api.item-stock');

    // The food cost tile on CRM's home screen.
    Route::get('outlets/{id}/food-cost', FoodCostController::class)
        ->where('id', $ulid)
        ->name('api.food-cost');

    // What ERP last told CRM about each dish, for CRM's nightly check.
    Route::get('outlets/{id}/signals', SignalsController::class)
        ->where('id', $ulid)
        ->name('api.signals');
});

// Other billing software.
Route::middleware('machine:sales:write')->group(function () {
    Route::post('sales', [SaleController::class, 'store'])->name('api.sales.store');
    Route::post('sales/{ref}/cancel', [SaleController::class, 'cancel'])->where('ref', '.+')->name('api.sales.cancel');
});
