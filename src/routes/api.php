<?php

use App\Http\Controllers\TelegramWebhookController;
use App\Http\Controllers\TripayWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/webhook/tripay', TripayWebhookController::class);
Route::post('/webhook/telegram', TelegramWebhookController::class)->middleware('throttle:telegram');
