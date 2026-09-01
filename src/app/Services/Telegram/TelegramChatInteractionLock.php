<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Cache;

final class TelegramChatInteractionLock
{
    private const BLOCK_SECONDS = 5;

    private const MIN_LEASE_SECONDS = 60;

    public function withLock(string $chatId, callable $callback): mixed
    {
        $chatId = trim($chatId);
        if ($chatId === '') {
            return $callback();
        }

        return Cache::lock($this->key($chatId), $this->leaseSeconds())
            ->block(self::BLOCK_SECONDS, $callback);
    }

    private function key(string $chatId): string
    {
        return 'telegram:chat-interaction:'.$chatId;
    }

    private function leaseSeconds(): int
    {
        $timeout = config('services.telegram.http_timeout_seconds', 30);
        $timeoutSeconds = is_numeric($timeout) ? (float) $timeout : 30.0;

        return max(self::MIN_LEASE_SECONDS, (int) ceil($timeoutSeconds * 4));
    }
}
