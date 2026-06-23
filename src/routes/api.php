<?php

use App\Http\Controllers\TelegramWebhookController;
use App\Http\Controllers\TripayWebhookController;
use App\Http\Middleware\RejectOversizedTripayWebhook;
use Illuminate\Support\Facades\Route;

Route::post('/webhook/tripay', TripayWebhookController::class)->middleware([RejectOversizedTripayWebhook::class, 'throttle:tripay']);
Route::post('/webhook/telegram', TelegramWebhookController::class)->middleware('throttle:telegram');
