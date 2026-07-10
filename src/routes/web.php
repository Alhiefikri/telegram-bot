<?php

use App\Http\Controllers\Admin\AdminBroadcastController;
use App\Http\Controllers\Admin\AdminCategoryCreateController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminFakeSalesProofPostController;
use App\Http\Controllers\Admin\AdminLoginController;
use App\Http\Controllers\Admin\AdminOrderCancelReleaseController;
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
use App\Http\Controllers\StorefrontOrderAccessController;
use App\Http\Controllers\StorefrontProductController;
use App\Http\Controllers\StorefrontShopCheckoutController;
use App\Http\Controllers\StorefrontShopController;
use App\Http\Controllers\StorefrontShopOrderController;
use App\Http\Controllers\StorefrontShopOrderStatusController;
use Illuminate\Support\Facades\Route;

Route::get('/', StorefrontController::class)->name('storefront.home');
Route::get('/panduan-login-akun', [StorefrontLoginGuideController::class, 'show'])->name('storefront.login-guide');
Route::get('/produk/{product}', StorefrontProductController::class)->name('storefront.products.show');
Route::get('/akses-pesanan', [StorefrontOrderAccessController::class, 'create'])->name('storefront.order-access.create');
Route::post('/akses-pesanan', [StorefrontOrderAccessController::class, 'store'])->middleware('throttle:storefront-order-access')->name('storefront.order-access.store');
Route::get('/akses-pesanan/{order}', [StorefrontOrderAccessController::class, 'show'])->name('storefront.order-access.show');

Route::prefix('shop')->name('storefront.shop.')->group(function (): void {
    Route::get('/', StorefrontShopController::class)->name('index');
    Route::post('checkout', StorefrontShopCheckoutController::class)->middleware('throttle:storefront-checkout')->name('checkout');
    Route::get('orders/{order}', StorefrontShopOrderController::class)->middleware('storefront.signed')->name('orders.show');
    Route::post('orders/{order}/status', StorefrontShopOrderStatusController::class)->middleware(['storefront.signed', 'throttle:pakasir-status-refresh'])->name('orders.status');
});
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
                Route::post('fake-sales-proof', AdminFakeSalesProofPostController::class)->name('fake-sales-proof');
            });

            Route::prefix('orders')->name('orders.')->group(function (): void {
                Route::get('{order}', AdminOrderDetailController::class)->name('show');
                Route::post('{order}/sales-proof/retry', AdminSalesProofRetryController::class)->name('sales-proof.retry');
                Route::post('{order}/cancel-release', AdminOrderCancelReleaseController::class)->name('cancel-release');
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
