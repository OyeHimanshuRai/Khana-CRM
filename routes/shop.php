<?php

use App\Http\Controllers\Shop\AccountController;
use App\Http\Controllers\Shop\AddressController;
use App\Http\Controllers\Shop\Auth\LoginController;
use App\Http\Controllers\Shop\Auth\PasswordResetController;
use App\Http\Controllers\Shop\Auth\RegisterController;
use App\Http\Controllers\Shop\CartController;
use App\Http\Controllers\Shop\CatalogController;
use App\Http\Controllers\Shop\CheckoutController;
use App\Http\Controllers\Shop\HomeController;
use App\Http\Controllers\Shop\OrderController;
use App\Http\Controllers\Shop\ProductController;
use App\Http\Controllers\Shop\WishlistController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Storefront (per shop)
|--------------------------------------------------------------------------
|
| One storefront per shop, at /shop/{shop:slug}/... - matching the schema's
| existing rule that a Customer belongs to exactly one Shop, so there is no
| redesign of tenancy to support this. See ShareStorefrontShop for the
| inactive-shop 404 that route-model binding alone does not give.
*/

Route::prefix('shop/{shop:slug}')
    ->name('shop.')
    ->middleware('storefront.shop')
    // Product/category/order are not children of Shop through a
    // relationship Laravel could scope by (they're global catalogue rows
    // and customer-owned rows, not $shop->products()/$shop->orders()) -
    // without this, Laravel's implicit binding scoping tries exactly that
    // and throws on every route with two custom-keyed bindings.
    ->withoutScopedBindings()
    ->group(function () {
        Route::get('/', [HomeController::class, 'index'])->name('home');

        Route::get('catalog', [CatalogController::class, 'index'])->name('catalog');
        Route::get('category/{category:slug}', [CatalogController::class, 'category'])->name('category');
        Route::get('product/{product:slug}', [ProductController::class, 'show'])->name('product');

        Route::get('cart', [CartController::class, 'index'])->name('cart');
        Route::post('cart/add', [CartController::class, 'add'])->name('cart.add');
        Route::patch('cart/{product}', [CartController::class, 'update'])->name('cart.update');
        Route::delete('cart/{product}', [CartController::class, 'remove'])->name('cart.remove');

        Route::middleware('guest:customer')->group(function () {
            Route::get('login', [LoginController::class, 'create'])->name('login');
            Route::post('login', [LoginController::class, 'store'])->name('login.store');
            Route::get('register', [RegisterController::class, 'create'])->name('register');
            Route::post('register', [RegisterController::class, 'store'])->name('register.store');

            // Forgot password, for this shop's own customer account - see Shop\Auth\PasswordResetController.
            Route::get('forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
            Route::post('forgot-password', [PasswordResetController::class, 'email'])->middleware('throttle:5,10')->name('password.email');
            Route::get('reset-password/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
            Route::post('reset-password', [PasswordResetController::class, 'update'])->middleware('throttle:10,10')->name('password.update');
        });

        Route::middleware(['auth:customer', 'customer.shop'])->group(function () {
            Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

            Route::get('checkout', [CheckoutController::class, 'index'])->name('checkout');
            Route::post('checkout/coupon', [CheckoutController::class, 'applyCoupon'])->name('checkout.coupon');
            Route::post('checkout', [CheckoutController::class, 'store'])->middleware('idempotent')->name('checkout.store');

            Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
            Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');

            Route::get('wishlist', [WishlistController::class, 'index'])->name('wishlist.index');
            Route::post('wishlist/{product}', [WishlistController::class, 'toggle'])->name('wishlist.toggle');

            Route::get('account', [AccountController::class, 'edit'])->name('account.edit');
            Route::put('account', [AccountController::class, 'update'])->name('account.update');

            Route::get('account/addresses', [AddressController::class, 'index'])->name('account.addresses.index');
            Route::post('account/addresses', [AddressController::class, 'store'])->name('account.addresses.store');
            Route::put('account/addresses/{address}', [AddressController::class, 'update'])->name('account.addresses.update');
            Route::delete('account/addresses/{address}', [AddressController::class, 'destroy'])->name('account.addresses.destroy');
        });
    });
