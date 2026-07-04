<?php

use App\Http\Controllers\PakasirWebhookController;
use App\Http\Controllers\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/webhook/pakasir', PakasirWebhookController::class)->middleware('throttle:pakasir-webhook');
Route::post('/webhook/telegram', TelegramWebhookController::class)->middleware('throttle:telegram');
