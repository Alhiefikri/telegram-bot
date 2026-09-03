<?php

use App\Http\Controllers\KlikQrisProductionWebhookController;
use App\Http\Controllers\KlikQrisSandboxWebhookController;
use App\Http\Controllers\PakasirWebhookController;
use App\Http\Controllers\SakurupiahWebhookController;
use App\Http\Controllers\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/webhook/klikqris/sandbox', KlikQrisSandboxWebhookController::class)->middleware('throttle:klikqris-sandbox-webhook')->name('klikqris.sandbox.webhook');
Route::post('/webhook/klikqris', KlikQrisProductionWebhookController::class)->middleware('throttle:klikqris-production-webhook')->name('klikqris.production.webhook');
Route::post('/webhook/sakurupiah', SakurupiahWebhookController::class)->middleware('throttle:sakurupiah-webhook')->name('sakurupiah.webhook');
Route::post('/webhook/pakasir', PakasirWebhookController::class)->middleware('throttle:pakasir-webhook');
Route::post('/webhook/telegram', TelegramWebhookController::class)->middleware('throttle:telegram');
