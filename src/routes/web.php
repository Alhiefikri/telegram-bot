<?php

use App\Http\Controllers\Admin\AdminBalanceAdjustmentController;
use App\Http\Controllers\Admin\AdminBroadcastController;
use App\Http\Controllers\Admin\AdminBscReceiverController;
use App\Http\Controllers\Admin\AdminBuyMailController;
use App\Http\Controllers\Admin\AdminCategoryCreateController;
use App\Http\Controllers\Admin\AdminCategoryDeleteController;
use App\Http\Controllers\Admin\AdminCategoryUpdateController;
use App\Http\Controllers\Admin\AdminCheckoutMaintenanceController;
use App\Http\Controllers\Admin\AdminCustomerUsdtFundingPauseController;
use App\Http\Controllers\Admin\AdminCustomerUsdtFundingPolicyController;
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
use App\Http\Controllers\Admin\AdminPaymentReconcileController;
use App\Http\Controllers\Admin\AdminProductAccountStockController;
use App\Http\Controllers\Admin\AdminProductCreateController;
use App\Http\Controllers\Admin\AdminProductDeleteController;
use App\Http\Controllers\Admin\AdminProductFamilyCreateController;
use App\Http\Controllers\Admin\AdminProductFamilyDeleteController;
use App\Http\Controllers\Admin\AdminProductFamilyMembershipController;
use App\Http\Controllers\Admin\AdminProductFamilyUpdateController;
use App\Http\Controllers\Admin\AdminProductStockController;
use App\Http\Controllers\Admin\AdminProductStockItemDeleteController;
use App\Http\Controllers\Admin\AdminProductStockItemsBulkDeleteController;
use App\Http\Controllers\Admin\AdminProductStockItemsController;
use App\Http\Controllers\Admin\AdminProductStockItemUpdateController;
use App\Http\Controllers\Admin\AdminProductUpdateController;
use App\Http\Controllers\Admin\AdminProductVisibilityController;
use App\Http\Controllers\Admin\AdminSalesProofRetryController;
use App\Http\Controllers\Admin\AdminStoreBalanceFundingPolicyController;
use App\Http\Controllers\Admin\AdminStoreBalanceTopUpCompensationController;
use App\Http\Controllers\Admin\AdminStoreChannelController;
use App\Http\Controllers\Admin\AdminStoreChannelTestController;
use App\Http\Controllers\Admin\AdminStorefrontBannersController;
use App\Http\Controllers\Admin\AdminStorefrontPlaceholderController;
use App\Http\Controllers\Admin\AdminSupplierController;
use App\Http\Controllers\Admin\AdminSupportSettingsController;
use App\Http\Controllers\Admin\AdminTelegramOutboundEffectController;
use App\Http\Controllers\Admin\AdminTelegramWelcomeBannerController;
use App\Http\Controllers\Admin\AdminUsdtTopUpReviewController;
use App\Http\Controllers\Admin\AdminWarrantyController;
use App\Http\Controllers\CustomerAccountController;
use App\Http\Controllers\StorefrontController;
use App\Http\Controllers\StorefrontFunnelEventController;
use App\Http\Controllers\StorefrontLegacyProductFamilyRedirectController;
use App\Http\Controllers\StorefrontLoginGuideController;
use App\Http\Controllers\StorefrontOrderAccessController;
use App\Http\Controllers\StorefrontPolicyController;
use App\Http\Controllers\StorefrontProductController;
use App\Http\Controllers\StorefrontProductFamilyController;
use App\Http\Controllers\StorefrontRobotsController;
use App\Http\Controllers\StorefrontShopBinanceOrderIdController;
use App\Http\Controllers\StorefrontShopBscTxHashController;
use App\Http\Controllers\StorefrontShopCartController;
use App\Http\Controllers\StorefrontShopCheckoutController;
use App\Http\Controllers\StorefrontShopCheckoutReviewController;
use App\Http\Controllers\StorefrontShopController;
use App\Http\Controllers\StorefrontShopOrderCancelController;
use App\Http\Controllers\StorefrontShopOrderController;
use App\Http\Controllers\StorefrontShopOrderQrisController;
use App\Http\Controllers\StorefrontShopOrderStatusController;
use App\Http\Controllers\StorefrontSitemapController;
use App\Http\Controllers\StorefrontVerifiedPurchaseActivityController;
use App\Http\Controllers\StorefrontWaitlistController;
use App\Http\Controllers\WarrantyController;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::get('/robots.txt', StorefrontRobotsController::class)->name('storefront.robots');
Route::get('/sitemap.xml', StorefrontSitemapController::class)->name('storefront.sitemap');
Route::get('/', StorefrontController::class)
    ->withoutMiddleware([
        StartSession::class,
        ShareErrorsFromSession::class,
        PreventRequestForgery::class,
    ])
    ->name('storefront.home');
Route::get('/panduan', [StorefrontLoginGuideController::class, 'index'])->name('storefront.guides.index');
Route::get('/gpt/panduan-login-akun', [StorefrontLoginGuideController::class, 'show'])->name('storefront.login-guide');
Route::redirect('/panduan-login-akun', '/gpt/panduan-login-akun');
Route::get('/privasi', [StorefrontPolicyController::class, 'privacy'])->name('storefront.policies.privacy');
Route::get('/syarat-ketentuan', [StorefrontPolicyController::class, 'terms'])->name('storefront.policies.terms');
Route::get('/refund', [StorefrontPolicyController::class, 'refund'])->name('storefront.policies.refund');
Route::get('/pesanan/{order}/bantuan-akun', [WarrantyController::class, 'show'])->name('warranty.show');
Route::post('/pesanan/{order}/bantuan-akun/klaim', [WarrantyController::class, 'claim'])->middleware('throttle:10,1')->name('warranty.claim');
Route::post('/pesanan/{order}/bantuan-akun/pilihan', [WarrantyController::class, 'choose'])->middleware('throttle:10,1')->name('warranty.choose');
Route::post('/pesanan/{order}/bantuan-akun/konfirmasi', [WarrantyController::class, 'confirm'])->middleware('throttle:10,1')->name('warranty.confirm');
Route::post('/pesanan/{order}/bantuan-akun/sisa', [WarrantyController::class, 'refundMissing'])->middleware('throttle:10,1')->name('warranty.refund-missing');
Route::get('/pilihan/{productFamily:slug}', StorefrontProductFamilyController::class)->name('storefront.product-families.show');
Route::get('/produk-keluarga/{slug}', StorefrontLegacyProductFamilyRedirectController::class)->name('storefront.product-families.legacy');
Route::get('/pemulihan-pesanan', [StorefrontPolicyController::class, 'recovery'])->name('storefront.policies.recovery');
Route::get('/produk/{product}', StorefrontProductController::class)->name('storefront.products.show');
Route::post('/produk/{product}/waitlist', [StorefrontWaitlistController::class, 'store'])
    ->middleware('throttle:storefront-waitlist')
    ->name('storefront.waitlist.store');
Route::get('/waitlist/confirm/{subscription}', [StorefrontWaitlistController::class, 'confirm'])
    ->name('storefront.waitlist.confirm');
Route::get('/waitlist/unsubscribe/{subscription}', [StorefrontWaitlistController::class, 'unsubscribe'])
    ->name('storefront.waitlist.unsubscribe');
Route::get('/aktivitas-pembelian-terverifikasi', StorefrontVerifiedPurchaseActivityController::class)
    ->middleware('throttle:storefront-verified-purchase-activity')
    ->name('storefront.verified-purchase-activity');
Route::post('/storefront/events', StorefrontFunnelEventController::class)
    ->middleware('throttle:120,1')
    ->withoutMiddleware(PreventRequestForgery::class)
    ->name('storefront.events.store');
Route::get('/akses-pesanan', [StorefrontOrderAccessController::class, 'create'])->name('storefront.order-access.create');
Route::post('/akses-pesanan', [StorefrontOrderAccessController::class, 'store'])->middleware('throttle:storefront-order-access')->name('storefront.order-access.store');
Route::get('/akses-pesanan/{order}', [StorefrontOrderAccessController::class, 'show'])->name('storefront.order-access.show');

Route::prefix('shop')->name('storefront.shop.')->group(function (): void {
    Route::get('/', StorefrontShopController::class)->name('index');
    Route::get('cart', [StorefrontShopCartController::class, 'index'])->name('cart');
    Route::post('cart/items', [StorefrontShopCartController::class, 'store'])->name('cart.items.store');
    Route::patch('cart/items/{product}', [StorefrontShopCartController::class, 'update'])->name('cart.items.update');
    Route::delete('cart/items/{product}', [StorefrontShopCartController::class, 'destroy'])->name('cart.items.destroy');
    Route::get('checkout', StorefrontShopCheckoutReviewController::class)->name('checkout.start');
    Route::get('checkout/products/{product}', [StorefrontShopCheckoutReviewController::class, 'product'])->name('checkout.product');
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
            // BSC controllers persist validated pending mutations because password redirects cannot replay POST bodies.
            Route::prefix('bsc')->name('bsc.')->middleware('admin.bsc-owner')->group(function (): void {
                Route::post('receivers', [AdminBscReceiverController::class, 'store'])->name('receivers.store');
                Route::post('receivers/{receiver}/challenge', [AdminBscReceiverController::class, 'challenge'])->name('receivers.challenge');
                Route::post('receivers/{receiver}/verify', [AdminBscReceiverController::class, 'verify'])->name('receivers.verify');
                Route::post('receivers/{receiver}/manual-verify', [AdminBscReceiverController::class, 'manualVerify'])->name('receivers.manual-verify');
                Route::post('receivers/{receiver}/activate', [AdminBscReceiverController::class, 'activate'])->name('receivers.activate');
                Route::post('receivers/{receiver}/deactivate', [AdminBscReceiverController::class, 'deactivate'])->name('receivers.deactivate');
            });
            Route::post('checkout-maintenance', AdminCheckoutMaintenanceController::class)->name('checkout-maintenance.update');
            Route::prefix('store-balance/adjustments')->name('store-balance.adjustments.')->middleware('admin.store-balance-owner')->group(function (): void {
                Route::get('/', [AdminBalanceAdjustmentController::class, 'index'])->name('index');
                Route::post('previews', [AdminBalanceAdjustmentController::class, 'preview'])->name('previews.store');
                Route::post('previews/{adjustment}/confirm', [AdminBalanceAdjustmentController::class, 'confirm'])->name('previews.confirm');
                Route::post('previews/{adjustment}/cancel', [AdminBalanceAdjustmentController::class, 'cancel'])->name('previews.cancel');
                Route::post('reversals', [AdminBalanceAdjustmentController::class, 'reversal'])->name('reversals.store');
            });
            Route::prefix('store-balance/usdt-review')->name('store-balance.usdt-review.')->middleware('admin.store-balance-owner')->group(function (): void {
                Route::get('/', [AdminUsdtTopUpReviewController::class, 'index'])->name('index');
                Route::post('previews', [AdminUsdtTopUpReviewController::class, 'preview'])->name('previews.store');
                Route::post('previews/{review}/confirm', [AdminUsdtTopUpReviewController::class, 'confirm'])->whereUuid('review')->name('previews.confirm');
                Route::post('recovery-previews', [AdminUsdtTopUpReviewController::class, 'recoveryPreview'])->name('recovery-previews.store');
                Route::post('recovery-previews/{recovery}/confirm', [AdminUsdtTopUpReviewController::class, 'recoveryConfirm'])->whereUuid('recovery')->name('recovery-previews.confirm');
            });
            Route::post('store-balance/funding-policy', AdminStoreBalanceFundingPolicyController::class)->name('store-balance.funding-policy.update');
            Route::post('store-balance/usdt-policy', AdminCustomerUsdtFundingPolicyController::class)
                ->middleware('admin.store-balance-owner')
                ->name('store-balance.usdt-policy.update');
            Route::post('store-balance/usdt-pauses/{scope}', [AdminCustomerUsdtFundingPauseController::class, 'store'])
                ->middleware('admin.store-balance-owner')
                ->name('store-balance.usdt-pauses.store');
            Route::delete('store-balance/usdt-pauses/{scope}', [AdminCustomerUsdtFundingPauseController::class, 'destroy'])
                ->middleware('admin.store-balance-owner')
                ->name('store-balance.usdt-pauses.destroy');
            Route::post('store-balance/top-ups/{topUp}/compensate', AdminStoreBalanceTopUpCompensationController::class)
                ->middleware('admin.store-balance-owner')
                ->name('store-balance.top-ups.compensate');
            Route::post('support-settings', AdminSupportSettingsController::class)->name('support-settings.update');
            Route::post('storefront-banners', AdminStorefrontBannersController::class)->name('storefront-banners.update');
            Route::post('storefront-placeholder', AdminStorefrontPlaceholderController::class)->name('storefront-placeholder.update');
            Route::post('telegram-welcome-banner', AdminTelegramWelcomeBannerController::class)->name('telegram-welcome-banner.update');
            Route::post('broadcast', AdminBroadcastController::class)->name('broadcast.store');
            Route::prefix('suppliers')->name('suppliers.')->group(function (): void {
                Route::get('warranty', [AdminWarrantyController::class, 'index'])->name('warranty');
                Route::get('warranty/{claim}/proof', [AdminWarrantyController::class, 'proof'])->name('warranty.proof');
                Route::post('warranty/{claim}/refresh', [AdminWarrantyController::class, 'refresh'])->middleware('throttle:10,1')->name('warranty.refresh');
                Route::post('warranty/{claim}/resubmit', [AdminWarrantyController::class, 'resubmit'])->middleware('throttle:10,1')->name('warranty.resubmit');
                Route::post('warranty/{claim}/recover-replacement', [AdminWarrantyController::class, 'recoverReplacement'])->middleware('throttle:10,1')->name('warranty.recover-replacement');
                Route::post('warranty/{claim}/approve-acquired', [AdminWarrantyController::class, 'approveAcquired'])->middleware('throttle:10,1')->name('warranty.approve-acquired');
                Route::post('warranty/refunds/{refund}', [AdminWarrantyController::class, 'refund'])->middleware('throttle:10,1')->name('warranty.refund');
                Route::post('/', [AdminSupplierController::class, 'store'])->name('store');
                Route::patch('{supplier}', [AdminSupplierController::class, 'update'])->name('update');
                Route::post('{supplier}/sync', [AdminSupplierController::class, 'sync'])->middleware('throttle:10,1')->name('sync');
                Route::get('{supplier}/catalog', [AdminSupplierController::class, 'catalog'])->name('catalog');
                Route::get('{supplier}/buymail', [AdminBuyMailController::class, 'index'])->name('buymail');
                Route::post('{supplier}/buymail/deposits', [AdminBuyMailController::class, 'createDeposit'])->middleware('throttle:10,1')->name('buymail.deposit');
                Route::post('{supplier}/buymail/history', [AdminBuyMailController::class, 'history'])->middleware('throttle:10,1')->name('buymail.history');
                Route::post('buymail/deposits/{deposit}/cancel', [AdminBuyMailController::class, 'cancelDeposit'])->middleware('throttle:10,1')->name('buymail.deposit.cancel');
                Route::post('buymail/deposits/{deposit}/refresh', [AdminBuyMailController::class, 'refreshDeposit'])->middleware('throttle:10,1')->name('buymail.deposit.refresh');
                Route::post('catalog/{catalogItem}/import', [AdminSupplierController::class, 'import'])->name('import');
                Route::post('catalog/{catalogItem}/map', [AdminSupplierController::class, 'map'])->name('map');
                Route::post('catalog/{catalogItem}/move', [AdminSupplierController::class, 'move'])->name('move');
                Route::delete('catalog/{catalogItem}/map', [AdminSupplierController::class, 'detach'])->name('detach');
                Route::get('monitor/purchases', [AdminSupplierController::class, 'purchases'])->name('purchases');
                Route::post('fulfillments/{fulfillment}/operate', [AdminSupplierController::class, 'operate'])->middleware('throttle:10,1')->name('operate');
            });
            Route::get('products/{product}/suppliers', [AdminSupplierController::class, 'product'])->name('products.suppliers');
            Route::post('products/{product}/supplier-pricing', [AdminSupplierController::class, 'price'])->name('products.supplier-pricing');
            Route::post('products/{product}/supplier-pricing/preview', [AdminSupplierController::class, 'preview'])->name('products.supplier-pricing.preview');
            Route::post('categories', AdminCategoryCreateController::class)->name('categories.store');
            Route::patch('categories/{category}', AdminCategoryUpdateController::class)->name('categories.update');
            Route::delete('categories/{category}', AdminCategoryDeleteController::class)->name('categories.destroy');

            Route::prefix('product-families')->name('product-families.')->group(function (): void {
                Route::post('/', AdminProductFamilyCreateController::class)->name('store');
                Route::patch('{productFamily}', AdminProductFamilyUpdateController::class)->name('update');
                Route::delete('{productFamily}', AdminProductFamilyDeleteController::class)->name('destroy');
            });

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

            Route::post('payments/{payment}/reconcile', AdminPaymentReconcileController::class)->name('payments.reconcile');

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
                Route::patch('{product}/family', AdminProductFamilyMembershipController::class)->name('family.update');
                Route::post('/', AdminProductCreateController::class)->name('store');
                Route::patch('{product}', AdminProductUpdateController::class)->name('update');
                Route::delete('{product}', AdminProductDeleteController::class)->name('destroy');
                Route::patch('{product}/visibility', AdminProductVisibilityController::class)->name('visibility.update');
                Route::get('{product}/stock-items', AdminProductStockItemsController::class)->name('stock-items.index');
                Route::post('{product}/stock', AdminProductStockController::class)->name('stock.store');
                Route::post('{product}/stock/accounts/review', [AdminProductAccountStockController::class, 'review'])->name('stock.accounts.review');
                Route::post('{product}/stock/accounts', AdminProductAccountStockController::class)->name('stock.accounts.store');
                Route::delete('{product}/stock-items', AdminProductStockItemsBulkDeleteController::class)->name('stock-items.bulk-destroy');
                Route::patch('{product}/stock-items/{stockItem}', AdminProductStockItemUpdateController::class)->name('stock-items.update');
                Route::delete('{product}/stock-items/{stockItem}', AdminProductStockItemDeleteController::class)->name('stock-items.destroy');
            });
        });
    });
});
