<?php

use App\Http\Controllers\Admin\AdminBroadcastController;
use App\Http\Controllers\Admin\AdminBscReceiverController;
use App\Http\Controllers\Admin\AdminCategoryCreateController;
use App\Http\Controllers\Admin\AdminCategoryDeleteController;
use App\Http\Controllers\Admin\AdminCategoryUpdateController;
use App\Http\Controllers\Admin\AdminCheckoutMaintenanceController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminFakeSalesProofPostController;
use App\Http\Controllers\Admin\AdminLoginController;
use App\Http\Controllers\Admin\AdminManualFulfillmentReviewController;
use App\Http\Controllers\Admin\AdminOrderCancelReleaseController;
use App\Http\Controllers\Admin\AdminOrderDetailController;
use App\Http\Controllers\Admin\AdminOrderManualCompleteController;
use App\Http\Controllers\Admin\AdminOrderManualRejectController;
use App\Http\Controllers\Admin\AdminPasswordConfirmationController;
use App\Http\Controllers\Admin\AdminPaymentModeController;
use App\Http\Controllers\Admin\AdminProductAccountStockController;
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
use App\Http\Controllers\Admin\AdminSupportSettingsController;
use App\Http\Controllers\Admin\AdminTelegramOutboundEffectController;
use App\Http\Controllers\CustomerAccountController;
use App\Http\Controllers\StorefrontController;
use App\Http\Controllers\StorefrontFunnelEventController;
use App\Http\Controllers\StorefrontLoginGuideController;
use App\Http\Controllers\StorefrontOrderAccessController;
use App\Http\Controllers\StorefrontPolicyController;
use App\Http\Controllers\StorefrontProductController;
use App\Http\Controllers\StorefrontShopBinanceOrderIdController;
use App\Http\Controllers\StorefrontShopBscTxHashController;
use App\Http\Controllers\StorefrontShopCheckoutController;
use App\Http\Controllers\StorefrontShopOrderCancelController;
use App\Http\Controllers\StorefrontShopOrderController;
use App\Http\Controllers\StorefrontShopOrderQrisController;
use App\Http\Controllers\StorefrontShopOrderStatusController;
use App\Http\Controllers\StorefrontVerifiedPurchaseActivityController;
use App\Storefront\StorefrontCatalogSearch;
use App\Storefront\StorefrontUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', StorefrontController::class)->name('storefront.home');
Route::get('/panduan', [StorefrontLoginGuideController::class, 'index'])->name('storefront.guides.index');
Route::get('/gpt/panduan-login-akun', [StorefrontLoginGuideController::class, 'show'])->name('storefront.login-guide');
Route::redirect('/panduan-login-akun', '/gpt/panduan-login-akun');
Route::get('/privasi', [StorefrontPolicyController::class, 'privacy'])->name('storefront.policies.privacy');
Route::get('/syarat-ketentuan', [StorefrontPolicyController::class, 'terms'])->name('storefront.policies.terms');
Route::get('/refund', [StorefrontPolicyController::class, 'refund'])->name('storefront.policies.refund');
Route::get('/pemulihan-pesanan', [StorefrontPolicyController::class, 'recovery'])->name('storefront.policies.recovery');
Route::get('/produk/{product}', StorefrontProductController::class)->name('storefront.products.show');
Route::get('/aktivitas-pembelian-terverifikasi', StorefrontVerifiedPurchaseActivityController::class)
    ->middleware('throttle:storefront-verified-purchase-activity')
    ->name('storefront.verified-purchase-activity');
Route::post('/storefront/events', StorefrontFunnelEventController::class)->middleware('throttle:120,1')->name('storefront.events.store');
Route::get('/akses-pesanan', [StorefrontOrderAccessController::class, 'create'])->name('storefront.order-access.create');
Route::post('/akses-pesanan', [StorefrontOrderAccessController::class, 'store'])->middleware('throttle:storefront-order-access')->name('storefront.order-access.store');
Route::get('/akses-pesanan/{order}', [StorefrontOrderAccessController::class, 'show'])->name('storefront.order-access.show');

Route::prefix('shop')->name('storefront.shop.')->group(function (): void {
    Route::get('/', function (Request $request, StorefrontUrl $urls) {
        $search = StorefrontCatalogSearch::fromRequestQuery($request->query('q'));

        return redirect()->to($urls->catalogUrl(search: $search !== '' ? $search : null));
    })->name('index');
    Route::get('checkout', fn () => redirect()->route('storefront.shop.index'))->name('checkout.start');
    Route::post('checkout', StorefrontShopCheckoutController::class)->middleware('throttle:storefront-checkout')->name('checkout');
    Route::get('orders/{order}', StorefrontShopOrderController::class)->middleware('storefront.signed')->name('orders.show');
    Route::get('orders/{order}/qris', StorefrontShopOrderQrisController::class)->middleware('storefront.signed')->name('orders.qris');
    Route::post('orders/{order}/binance-order-id', StorefrontShopBinanceOrderIdController::class)->middleware(['storefront.signed', 'throttle:storefront-binance-order-id'])->name('orders.binance-order-id');
    Route::post('orders/{order}/bsc-tx-hash', StorefrontShopBscTxHashController::class)->middleware(['storefront.signed', 'throttle:storefront-bsc-tx-hash'])->name('orders.bsc-tx-hash');
    Route::post('orders/{order}/status', StorefrontShopOrderStatusController::class)->middleware(['storefront.signed', 'throttle:automatic-payment-status-refresh'])->name('orders.status');
    Route::post('orders/{order}/cancel', StorefrontShopOrderCancelController::class)->middleware('storefront.signed')->name('orders.cancel');
});
Route::redirect('/cara-loginoutlook', '/gpt/panduan-login-akun');

Route::prefix('akun')->name('customer.')->group(function (): void {
    Route::get('/', [CustomerAccountController::class, 'landing'])->name('account');
    Route::post('login-link', [CustomerAccountController::class, 'requestLink'])->middleware('throttle:customer-login-link')->name('login-link.request');
    Route::get('login-link', [CustomerAccountController::class, 'loginLink'])->name('login-link.show');
    Route::post('login-link/consume', [CustomerAccountController::class, 'consume'])->middleware('throttle:customer-login-consume')->name('login-link.consume');
    Route::middleware('auth:customer')->group(function (): void {
        Route::post('logout', [CustomerAccountController::class, 'logout'])->name('logout');
        Route::get('pesanan', [CustomerAccountController::class, 'orders'])->name('orders.index');
        Route::post('pesanan/{order}/status', [CustomerAccountController::class, 'status'])->middleware('throttle:automatic-payment-status-refresh')->name('orders.status');
        Route::get('pesanan/{order}', [CustomerAccountController::class, 'show'])->name('orders.show');
        Route::get('klaim-pesanan', [CustomerAccountController::class, 'claimForm'])->name('claim.create');
        Route::post('klaim-pesanan', [CustomerAccountController::class, 'claim'])->middleware('throttle:customer-order-claim')->name('claim.store');
    });
});

Route::middleware('admin.no-cache')->group(function (): void {
    Route::redirect('/telegram-mini-app', '/admin')->name('telegram-mini-app.dashboard');
    Route::get('/admin/confirm-password', [AdminPasswordConfirmationController::class, 'create'])->name('password.confirm');
    Route::post('/admin/confirm-password', [AdminPasswordConfirmationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('admin.password.confirm.store');

    Route::prefix('admin')->name('admin.')->group(function (): void {
        Route::get('login', [AdminLoginController::class, 'create'])->name('login');
        Route::post('login', [AdminLoginController::class, 'store'])->name('login.store');
        Route::post('logout', [AdminLoginController::class, 'destroy'])->name('logout');

        Route::middleware('admin.access')->group(function (): void {
            Route::get('/', AdminDashboardController::class)->name('dashboard');
            Route::post('payment-mode', AdminPaymentModeController::class)->name('payment-mode.update');
            Route::prefix('bsc')->name('bsc.')->middleware(['admin.bsc-owner', 'password.confirm:password.confirm,900'])->group(function (): void {
                Route::post('receivers', [AdminBscReceiverController::class, 'store'])->name('receivers.store');
                Route::post('receivers/{receiver}/challenge', [AdminBscReceiverController::class, 'challenge'])->name('receivers.challenge');
                Route::post('receivers/{receiver}/verify', [AdminBscReceiverController::class, 'verify'])->name('receivers.verify');
                Route::post('receivers/{receiver}/manual-verify', [AdminBscReceiverController::class, 'manualVerify'])->name('receivers.manual-verify');
                Route::post('receivers/{receiver}/activate', [AdminBscReceiverController::class, 'activate'])->name('receivers.activate');
                Route::post('receivers/{receiver}/deactivate', [AdminBscReceiverController::class, 'deactivate'])->name('receivers.deactivate');
            });
            Route::post('checkout-maintenance', AdminCheckoutMaintenanceController::class)->name('checkout-maintenance.update');
            Route::post('support-settings', AdminSupportSettingsController::class)->name('support-settings.update');
            Route::post('broadcast', AdminBroadcastController::class)->name('broadcast.store');
            Route::post('categories', AdminCategoryCreateController::class)->name('categories.store');
            Route::patch('categories/{category}', AdminCategoryUpdateController::class)->name('categories.update');
            Route::delete('categories/{category}', AdminCategoryDeleteController::class)->name('categories.destroy');

            Route::prefix('store-channel')->name('store-channel.')->group(function (): void {
                Route::post('/', AdminStoreChannelController::class)->name('update');
                Route::post('test', AdminStoreChannelTestController::class)->name('test');
                Route::post('fake-sales-proof', AdminFakeSalesProofPostController::class)->name('fake-sales-proof');
            });

            Route::prefix('orders')->name('orders.')->group(function (): void {
                Route::get('{order}', AdminOrderDetailController::class)->name('show');
                Route::post('{order}/complete-manual', AdminOrderManualCompleteController::class)->name('complete-manual');
                Route::post('{order}/reject-manual', AdminOrderManualRejectController::class)->name('reject-manual');
                Route::post('{order}/sales-proof/retry', AdminSalesProofRetryController::class)->name('sales-proof.retry');
                Route::post('{order}/cancel-release', AdminOrderCancelReleaseController::class)->name('cancel-release');
            });

            Route::prefix('manual-fulfillment-reviews')->name('manual-fulfillment-reviews.')->group(function (): void {
                Route::get('/', [AdminManualFulfillmentReviewController::class, 'index'])->name('index');
                Route::get('{review}', [AdminManualFulfillmentReviewController::class, 'show'])->name('show');
                Route::post('{review}/resolve', [AdminManualFulfillmentReviewController::class, 'resolve'])->name('resolve');
            });

            Route::prefix('telegram-outbound-effects')->name('telegram-outbound-effects.')->group(function (): void {
                Route::get('/', [AdminTelegramOutboundEffectController::class, 'index'])->name('index');
                Route::get('{effect}', [AdminTelegramOutboundEffectController::class, 'show'])->name('show');
                Route::post('{effect}/mark-sent', [AdminTelegramOutboundEffectController::class, 'markSent'])->name('mark-sent');
                Route::post('{effect}/resend', [AdminTelegramOutboundEffectController::class, 'resend'])->name('resend');
            });

            Route::prefix('products')->name('products.')->group(function (): void {
                Route::post('/', AdminProductCreateController::class)->name('store');
                Route::patch('{product}', AdminProductUpdateController::class)->name('update');
                Route::delete('{product}', AdminProductDeleteController::class)->name('destroy');
                Route::patch('{product}/visibility', AdminProductVisibilityController::class)->name('visibility.update');
                Route::post('{product}/stock', AdminProductStockController::class)->name('stock.store');
                Route::post('{product}/stock/accounts/review', [AdminProductAccountStockController::class, 'review'])->name('stock.accounts.review');
                Route::post('{product}/stock/accounts', AdminProductAccountStockController::class)->name('stock.accounts.store');
                Route::patch('{product}/stock-items/{stockItem}', AdminProductStockItemUpdateController::class)->name('stock-items.update');
                Route::delete('{product}/stock-items/{stockItem}', AdminProductStockItemDeleteController::class)->name('stock-items.destroy');
            });
        });
    });
});
