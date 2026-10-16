<?php

use App\Http\Controllers\BuyMailWebhookController;
use App\Http\Controllers\CustomerApiOrderController;
use App\Http\Controllers\CustomerApiOrderReadController;
use App\Http\Controllers\CustomerApiReadController;
use App\Http\Controllers\KlikQrisProductionWebhookController;
use App\Http\Controllers\KlikQrisSandboxWebhookController;
use App\Http\Controllers\PakasirWebhookController;
use App\Http\Controllers\SakurupiahWebhookController;
use App\Http\Controllers\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/webhook/suppliers/buymail/{supplier}', BuyMailWebhookController::class)->middleware('throttle:240,1')->name('buymail.webhook');

Route::post('/webhook/klikqris/sandbox', KlikQrisSandboxWebhookController::class)->middleware('throttle:klikqris-sandbox-webhook')->name('klikqris.sandbox.webhook');
Route::post('/webhook/klikqris', KlikQrisProductionWebhookController::class)->middleware('throttle:klikqris-production-webhook')->name('klikqris.production.webhook');
Route::post('/webhook/sakurupiah', SakurupiahWebhookController::class)->middleware('throttle:sakurupiah-webhook')->name('sakurupiah.webhook');
Route::post('/webhook/pakasir', PakasirWebhookController::class)->middleware('throttle:pakasir-webhook');
Route::post('/webhook/telegram', TelegramWebhookController::class)->middleware('throttle:telegram');

Route::prefix('v1')->name('customer-api.')->group(function (): void {
    Route::get('/balance', [CustomerApiReadController::class, 'balance'])->name('balance');
    Route::get('/products', [CustomerApiReadController::class, 'products'])->name('products');
    Route::get('/products/{id}', [CustomerApiReadController::class, 'product'])->name('product');
    Route::post('/orders', [CustomerApiOrderController::class, 'store'])->name('orders.store');
    Route::get('/orders', [CustomerApiOrderReadController::class, 'index'])->name('orders.index');
    Route::get('/orders/{id}', [CustomerApiOrderReadController::class, 'show'])->name('orders.show');
    Route::get('/orders/{id}/delivery', [CustomerApiOrderReadController::class, 'delivery'])->name('orders.delivery');
});
