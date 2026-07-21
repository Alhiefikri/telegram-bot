<?php

namespace App\Support;

class AdminTelegramDogfoodTiming
{
    public static function logRequestFinished(string $action, int $startedAt, array $broadcastResult): void
    {
        DogfoodTiming::log('dogfood.telegram.admin_request_finished', [
            'action' => $action,
            'request_duration_ms' => DogfoodTiming::elapsedMs($startedAt),
            'recipient_count' => (int) ($broadcastResult['recipient_count'] ?? 0),
            'sent' => (int) ($broadcastResult['sent'] ?? 0),
            'failed' => (int) ($broadcastResult['failed'] ?? 0),
            'skipped' => (bool) ($broadcastResult['skipped'] ?? false),
            'queued' => (bool) ($broadcastResult['queued'] ?? false),
            'telegram_total_duration_ms' => (float) ($broadcastResult['telegram_total_duration_ms'] ?? 0.0),
            'per_recipient_telegram' => $broadcastResult['per_recipient_telegram'] ?? [],
        ]);
    }
}
