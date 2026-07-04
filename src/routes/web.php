<?php

use App\Http\Controllers\Admin\AdminBroadcastController;
use App\Http\Controllers\Admin\AdminCategoryCreateController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminLoginController;
use App\Http\Controllers\Admin\AdminOrderDetailController;
use App\Http\Controllers\Admin\AdminPaymentModeController;
use App\Http\Controllers\Admin\AdminProductCreateController;
use App\Http\Controllers\Admin\AdminProductDeleteController;
use App\Http\Controllers\Admin\AdminProductStockController;
use App\Http\Controllers\Admin\AdminProductStockItemDeleteController;
use App\Http\Controllers\Admin\AdminProductStockItemUpdateController;
use App\Http\Controllers\Admin\AdminProductUpdateController;
use App\Http\Controllers\Admin\AdminProductVisibilityController;
use App\Http\Controllers\Admin\AdminSalesProofRetryController;
use App\Http\Controllers\Admin\AdminStoreChannelController;
use App\Http\Controllers\Admin\AdminStoreChannelTestController;
use App\Http\Controllers\StorefrontController;
use App\Http\Controllers\StorefrontLoginGuideController;
use App\Http\Controllers\StorefrontManualCheckoutController;
use App\Http\Controllers\StorefrontProductController;
use App\Http\Controllers\StorefrontProductPaymentController;
use Illuminate\Support\Facades\Route;

Route::get('/', StorefrontController::class)->name('storefront.home');
Route::get('/panduan-login-akun', [StorefrontLoginGuideController::class, 'show'])->name('storefront.login-guide');
Route::get('/produk/{product}', StorefrontProductController::class)->name('storefront.products.show');
Route::get('/produk/{product}/bayar', StorefrontProductPaymentController::class)->name('storefront.products.payment');
Route::get('/produk/{product}/checkout-manual', StorefrontManualCheckoutController::class)->name('storefront.products.checkout-manual');
Route::redirect('/cara-loginoutlook', '/panduan-login-akun');

Route::middleware('admin.no-cache')->group(function (): void {
    Route::redirect('/telegram-mini-app', '/admin')->name('telegram-mini-app.dashboard');

    Route::prefix('admin')->name('admin.')->group(function (): void {
        Route::get('login', [AdminLoginController::class, 'create'])->name('login');
        Route::post('login', [AdminLoginController::class, 'store'])->name('login.store');
        Route::post('logout', [AdminLoginController::class, 'destroy'])->name('logout');

        Route::middleware('admin.access')->group(function (): void {
            Route::get('/', AdminDashboardController::class)->name('dashboard');
            Route::post('payment-mode', AdminPaymentModeController::class)->name('payment-mode.update');
            Route::post('broadcast', AdminBroadcastController::class)->name('broadcast.store');
            Route::post('categories', AdminCategoryCreateController::class)->name('categories.store');

            Route::prefix('store-channel')->name('store-channel.')->group(function (): void {
                Route::post('/', AdminStoreChannelController::class)->name('update');
                Route::post('test', AdminStoreChannelTestController::class)->name('test');
            });

            Route::prefix('orders')->name('orders.')->group(function (): void {
                Route::get('{order}', AdminOrderDetailController::class)->name('show');
                Route::post('{order}/sales-proof/retry', AdminSalesProofRetryController::class)->name('sales-proof.retry');
            });

            Route::prefix('products')->name('products.')->group(function (): void {
                Route::post('/', AdminProductCreateController::class)->name('store');
                Route::patch('{product}', AdminProductUpdateController::class)->name('update');
                Route::delete('{product}', AdminProductDeleteController::class)->name('destroy');
                Route::patch('{product}/visibility', AdminProductVisibilityController::class)->name('visibility.update');
                Route::post('{product}/stock', AdminProductStockController::class)->name('stock.store');
                Route::patch('{product}/stock-items/{stockItem}', AdminProductStockItemUpdateController::class)->name('stock-items.update');
                Route::delete('{product}/stock-items/{stockItem}', AdminProductStockItemDeleteController::class)->name('stock-items.destroy');
            });
        });
    });
});
