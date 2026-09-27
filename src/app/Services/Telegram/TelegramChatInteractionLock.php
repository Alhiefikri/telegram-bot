<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Cache;

final class TelegramChatInteractionLock
{
    public const LEASE_SECONDS = 55;

    public function withLock(string $chatId, callable $callback): mixed
    {
        $chatId = trim($chatId);
        if ($chatId === '') {
            return $callback();
        }

        $lock = Cache::lock($this->key($chatId), self::LEASE_SECONDS);
        if (! $lock->get()) {
            throw new TelegramChatInteractionLockUnavailable('Telegram chat interaction is already locked.');
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    private function key(string $chatId): string
    {
        return 'telegram:chat-interaction:'.$chatId;
    }
}
