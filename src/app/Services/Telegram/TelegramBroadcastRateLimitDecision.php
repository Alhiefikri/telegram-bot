<?php

namespace App\Services\Telegram;

final readonly class TelegramBroadcastRateLimitDecision
{
    private function __construct(
        public bool $allowed,
        public int $retryAfterSeconds = 0,
    ) {}

    public static function allowed(): self
    {
        return new self(true);
    }

    public static function deferred(int $retryAfterSeconds): self
    {
        return new self(false, max(1, $retryAfterSeconds));
    }
}
