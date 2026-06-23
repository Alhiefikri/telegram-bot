<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessTelegramUpdate;
use App\Models\TelegramUpdate;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $secret = (string) config('services.telegram.webhook_secret');
        if ($secret !== '' && ! hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token'))) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate(['update_id' => ['required', 'integer', 'min:1']]);
        $payload = $request->all();
        if (! Arr::has($payload, 'message') && ! Arr::has($payload, 'callback_query')) {
            return response()->json(['message' => 'Unsupported update type'], 422);
        }

        try {
            $update = TelegramUpdate::create(['update_id' => (int) $validated['update_id'], 'payload' => $payload, 'created_at' => now()]);
            ProcessTelegramUpdate::dispatch($update->id);
        } catch (UniqueConstraintViolationException) {
            // Duplicate Telegram update: already stored/processing/processed.
        }

        return response()->json(['ok' => true]);
    }
}
