<?php

namespace App\Services\Telegram;

final class TelegramCallbackAckState
{
    private ?string $acknowledgedCallbackId = null;

    public function reset(): void
    {
        $this->acknowledgedCallbackId = null;
    }

    public function acknowledge(string $callbackQueryId): void
    {
        $this->acknowledgedCallbackId = $callbackQueryId;
    }

    public function isAcknowledged(string $callbackQueryId): bool
    {
        return $this->acknowledgedCallbackId === $callbackQueryId;
    }
}
